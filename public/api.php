<?php
/**
 * Vincentian Resource Center — JSON API
 *
 * Endpoints (all under /api.php):
 *   GET  ?action=get       -> { data, version, updated_at, can_edit }
 *   POST ?action=login     body: { password }        -> { ok, can_edit }
 *   POST ?action=logout                                -> { ok }
 *   POST ?action=save      body: { data, version }    -> { ok, version, updated_at }
 *
 * Viewing (get) is public. Saving requires a logged-in editor.
 * Saving uses optimistic concurrency: send the version you loaded; if someone
 * else saved in the meantime the server responds 409 with the current content.
 */
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// Best-effort real client IP. Behind a reverse proxy the direct address is the
// proxy's, so prefer the first X-Forwarded-For entry when present.
function client_ip(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    $ra = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ra, FILTER_VALIDATE_IP) ? $ra : '';
}

// Recursively delete a directory (used to clean up render workspaces).
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach ((@scandir($dir) ?: []) as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $p = $dir . '/' . $it;
        if (is_dir($p)) {
            rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

// Create the survey_responses table on first use (mirrors how usage tables are
// auto-created), so no manual migration is needed after deploy.
function ensure_survey_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS survey_responses (
            id           bigserial    PRIMARY KEY,
            survey_id    text         NOT NULL,
            answers      jsonb        NOT NULL,
            submitted_at timestamptz  NOT NULL DEFAULT now(),
            ip           text
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_survey_responses_survey ON survey_responses (survey_id)');
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {

        // ---------------------------------------------------------------
        case 'get':
            $pdo = svdp_db();
            $row = $pdo->query('SELECT data, version, updated_at FROM content WHERE id = 1')
                       ->fetch();
            if ($row === false) {
                // No content saved yet — the frontend will fall back to its
                // built-in defaults. version 0 signals "nothing saved yet".
                respond(200, [
                    'data'       => null,
                    'version'    => 0,
                    'updated_at' => null,
                    'can_edit'   => svdp_can_edit(),
                ]);
            }
            respond(200, [
                'data'       => json_decode($row['data'], true),
                'version'    => (int)$row['version'],
                'updated_at' => $row['updated_at'],
                'can_edit'   => svdp_can_edit(),
            ]);
            break;

        // ---------------------------------------------------------------
        case 'login':
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            $body = read_json_body();
            $password = (string)($body['password'] ?? '');
            if (svdp_login($password)) {
                respond(200, ['ok' => true, 'can_edit' => true]);
            }
            // Small delay to slow brute-force attempts.
            usleep(400000);
            respond(401, ['ok' => false, 'error' => 'Incorrect password.']);
            break;

        // ---------------------------------------------------------------
        case 'logout':
            svdp_logout();
            respond(200, ['ok' => true, 'can_edit' => false]);
            break;

        // ---------------------------------------------------------------
        case 'save':
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            if (!svdp_can_edit()) {
                respond(401, ['error' => 'Not authorized. Please enter the edit password.']);
            }

            $body = read_json_body();
            if (!isset($body['data']) || !is_array($body['data'])) {
                respond(400, ['error' => 'Missing or invalid "data".']);
            }
            $expected = (int)($body['version'] ?? 0);
            $dataJson = json_encode($body['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($dataJson === false) {
                respond(400, ['error' => 'Content could not be encoded as JSON.']);
            }

            $pdo = svdp_db();
            $pdo->beginTransaction();

            // Lock the row (if any) to serialize concurrent saves.
            $current = $pdo->query('SELECT version FROM content WHERE id = 1 FOR UPDATE')
                           ->fetch();
            $currentVersion = $current === false ? 0 : (int)$current['version'];

            if ($expected !== $currentVersion) {
                // Someone else saved since this editor loaded the page.
                $pdo->rollBack();
                $row = $pdo->query('SELECT data, version, updated_at FROM content WHERE id = 1')
                           ->fetch();
                respond(409, [
                    'error'      => 'conflict',
                    'message'    => 'Someone else saved changes since you loaded the page. Your copy is below so you can merge, then save again.',
                    'data'       => $row ? json_decode($row['data'], true) : null,
                    'version'    => $row ? (int)$row['version'] : 0,
                    'updated_at' => $row['updated_at'] ?? null,
                ]);
            }

            $newVersion = $currentVersion + 1;
            $now = null;

            if ($current === false) {
                $stmt = $pdo->prepare(
                    'INSERT INTO content (id, data, version, updated_at)
                     VALUES (1, CAST(:data AS jsonb), :version, now())
                     RETURNING updated_at'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE content SET data = CAST(:data AS jsonb), version = :version, updated_at = now()
                     WHERE id = 1
                     RETURNING updated_at'
                );
            }
            $stmt->execute([':data' => $dataJson, ':version' => $newVersion]);
            $now = $stmt->fetchColumn();

            // Append to the history/audit table (best effort).
            try {
                $h = $pdo->prepare(
                    'INSERT INTO content_history (version, data) VALUES (:v, CAST(:d AS jsonb))'
                );
                $h->execute([':v' => $newVersion, ':d' => $dataJson]);
            } catch (Throwable $e) {
                // History is non-critical; ignore failures.
                error_log('SVdP history insert failed: ' . $e->getMessage());
            }

            $pdo->commit();
            respond(200, ['ok' => true, 'version' => $newVersion, 'updated_at' => $now]);
            break;

        // ---------------------------------------------------------------
        case 'upload':
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            if (!svdp_can_edit()) {
                respond(401, ['error' => 'Not authorized. Please enter the edit password.']);
            }
            if (empty($_FILES['file']) || !isset($_FILES['file']['error'])) {
                respond(400, ['error' => 'No file received.']);
            }
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                // Most commonly the file exceeds PHP's upload_max_filesize / post_max_size.
                respond(400, ['error' => 'Upload error (code ' . (int)$f['error'] . '). The file may exceed the server upload limit.']);
            }
            if ($f['size'] > 100 * 1024 * 1024) {
                respond(413, ['error' => 'File too large (max 100 MB).']);
            }
            $orig = (string)$f['name'];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf','odt','png','jpg','jpeg','gif'];
            if (!in_array($ext, $allowed, true)) {
                respond(415, ['error' => 'That file type is not allowed. Allowed: ' . implode(', ', $allowed) . '.']);
            }
            $dir = __DIR__ . '/uploads';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                respond(500, ['error' => 'The uploads folder is missing or not writable on the server.']);
            }
            $safe = bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $dir . '/' . $safe;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                respond(500, ['error' => 'Could not save the uploaded file.']);
            }
            @chmod($dest, 0644);
            respond(200, ['ok' => true, 'url' => 'uploads/' . $safe, 'filename' => $orig]);
            break;

        // ---------------------------------------------------------------
        case 'parse_docx':
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            if (!svdp_can_edit()) {
                respond(401, ['error' => 'Not authorized. Please enter the edit password.']);
            }
            if (empty($_FILES['file']) || !isset($_FILES['file']['error']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                respond(400, ['error' => 'No file received, or the upload failed.']);
            }
            $f   = $_FILES['file'];
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if ($ext !== 'docx') {
                respond(415, ['error' => 'Please upload a Word .docx file. Older .doc files aren\'t supported — open it in Word and "Save As" .docx first.']);
            }
            if (!class_exists('ZipArchive')) {
                respond(500, ['error' => 'The server is missing PHP zip support (install php-zip).']);
            }
            $zip = new ZipArchive();
            if ($zip->open($f['tmp_name']) !== true) {
                respond(400, ['error' => 'Could not open the Word file.']);
            }
            $xml    = $zip->getFromName('word/document.xml');
            $numXml = $zip->getFromName('word/numbering.xml'); // may be false
            $zip->close();
            if ($xml === false) {
                respond(400, ['error' => 'That doesn\'t look like a valid Word .docx.']);
            }

            // --- Resolve list numbering so we can tell each item's depth ---
            // numId -> abstractNumId, and abstractNumId -> (ilvl -> numFmt)
            $numToAbs  = [];
            $absLevels = [];
            if ($numXml !== false) {
                if (preg_match_all('/<w:num\b[^>]*w:numId="(\d+)"[^>]*>(.*?)<\/w:num>/s', $numXml, $nm, PREG_SET_ORDER)) {
                    foreach ($nm as $one) {
                        if (preg_match('/<w:abstractNumId w:val="(\d+)"/', $one[2], $am)) {
                            $numToAbs[$one[1]] = $am[1];
                        }
                    }
                }
                if (preg_match_all('/<w:abstractNum\b[^>]*w:abstractNumId="(\d+)".*?<\/w:abstractNum>/s', $numXml, $anm, PREG_SET_ORDER)) {
                    foreach ($anm as $one) {
                        $lv = [];
                        if (preg_match_all('/<w:lvl\b[^>]*w:ilvl="(\d+)".*?<\/w:lvl>/s', $one[0], $lm, PREG_SET_ORDER)) {
                            foreach ($lm as $l) {
                                if (preg_match('/<w:numFmt w:val="([^"]+)"/', $l[0], $fm)) {
                                    $lv[$l[1]] = $fm[1];
                                }
                            }
                        }
                        $absLevels[$one[1]] = $lv;
                    }
                }
            }
            // Map a Word number format to an outline depth (0=number,1=letter,2=dash,3=bullet)
            $rankFmt = function ($fmt) {
                if ($fmt === 'decimal') return 0;
                if ($fmt === 'lowerLetter' || $fmt === 'upperLetter') return 1;
                if ($fmt === 'bullet') return 2;
                if ($fmt === 'lowerRoman' || $fmt === 'upperRoman') return 3;
                return 1;
            };

            // --- Extract paragraphs with text, list flag, and depth level ---
            $paras = [];
            if (preg_match_all('/<w:p\b.*?<\/w:p>/s', $xml, $mm)) {
                foreach ($mm[0] as $p) {
                    $isList = (strpos($p, 'ListParagraph') !== false);
                    $ilvl  = preg_match('/<w:ilvl w:val="(\d+)"/', $p, $im) ? (int)$im[1] : 0;
                    $numId = preg_match('/<w:numId w:val="(\d+)"/', $p, $nid) ? $nid[1] : null;
                    $level = -1;
                    if ($numId !== null && $numId !== '0') {
                        $aid = $numToAbs[$numId] ?? null;
                        $fmt = ($aid !== null && isset($absLevels[$aid][(string)$ilvl])) ? $absLevels[$aid][(string)$ilvl] : null;
                        $level = ($fmt !== null) ? $rankFmt($fmt) : $ilvl;
                    }
                    // tabs / line breaks -> spaces so runs don't glue together
                    $p = preg_replace('/<w:(?:tab|br)\b[^>]*\/?>/', ' ', $p);
                    $text = '';
                    if (preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $p, $tt)) {
                        $text = implode('', $tt[1]);
                    }
                    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $text = trim(preg_replace('/[ \t]+/u', ' ', $text));
                    $paras[] = ['text' => $text, 'list' => $isList, 'level' => $level];
                }
            }
            respond(200, ['ok' => true, 'paragraphs' => $paras]);
            break;

        // ---------------------------------------------------------------
        case 'extract_text':
            // Return the plain text of an uploaded .docx or .pdf (for the
            // Run of Show). Public — the files are already downloadable.
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            $body = read_json_body();
            $name = basename((string)($body['file'] ?? ''));
            if ($name === '') {
                respond(400, ['error' => 'Missing file.']);
            }
            $path = __DIR__ . '/uploads/' . $name;
            if (!is_file($path)) {
                respond(404, ['error' => 'File not found.']);
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === 'docx') {
                if (!class_exists('ZipArchive')) {
                    respond(500, ['error' => 'Server missing php-zip.']);
                }
                $zip = new ZipArchive();
                if ($zip->open($path) !== true) {
                    respond(400, ['error' => 'Could not open the Word file.']);
                }
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml === false) {
                    respond(400, ['error' => 'Not a valid .docx.']);
                }
                $paras = [];
                if (preg_match_all('/<w:p\b.*?<\/w:p>/s', $xml, $mm)) {
                    foreach ($mm[0] as $p) {
                        $p = preg_replace('/<w:(?:tab|br)\b[^>]*\/?>/', ' ', $p);
                        $t = '';
                        if (preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $p, $tt)) {
                            $t = implode('', $tt[1]);
                        }
                        $t = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        $paras[] = trim(preg_replace('/[ \t]+/u', ' ', $t));
                    }
                }
                respond(200, ['ok' => true, 'text' => implode("\n", $paras)]);
            } elseif ($ext === 'pdf') {
                // pdftotext (poppler-utils) if available and shell_exec is enabled.
                $bin = @shell_exec('command -v pdftotext 2>/dev/null');
                if (is_string($bin) && trim($bin) !== '') {
                    $out = @shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
                    if (is_string($out) && trim($out) !== '') {
                        respond(200, ['ok' => true, 'text' => $out]);
                    }
                }
                respond(422, ['error' => 'PDF text extraction is unavailable on the server (install poppler-utils / pdftotext).']);
            } else {
                respond(415, ['error' => 'Only .docx and .pdf can be shown as text.']);
            }
            break;

        // ---------------------------------------------------------------
        case 'log_usage':
            // Record one usage hit. Public (any visitor), best-effort, never
            // blocks the page. section = 'visit' for a page load, else a tab id.
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            $body    = read_json_body();
            $section = substr(trim((string)($body['section'] ?? '')), 0, 40);
            if ($section === '') {
                respond(400, ['error' => 'Missing section.']);
            }
            $pdo = svdp_db();
            $pdo->exec('CREATE TABLE IF NOT EXISTS usage_stats (day date NOT NULL, section text NOT NULL, hits integer NOT NULL DEFAULT 0, PRIMARY KEY (day, section))');
            $stmt = $pdo->prepare(
                'INSERT INTO usage_stats (day, section, hits) VALUES (CURRENT_DATE, :s, 1)
                 ON CONFLICT (day, section) DO UPDATE SET hits = usage_stats.hits + 1'
            );
            $stmt->execute([':s' => $section]);

            // Record the visitor IP on page loads only (for the top-visitors list).
            if ($section === 'visit') {
                $ip = client_ip();
                if ($ip !== '') {
                    $pdo->exec('CREATE TABLE IF NOT EXISTS usage_ips (day date NOT NULL, ip text NOT NULL, hits integer NOT NULL DEFAULT 0, PRIMARY KEY (day, ip))');
                    $ipStmt = $pdo->prepare(
                        'INSERT INTO usage_ips (day, ip, hits) VALUES (CURRENT_DATE, :ip, 1)
                         ON CONFLICT (day, ip) DO UPDATE SET hits = usage_ips.hits + 1'
                    );
                    $ipStmt->execute([':ip' => substr($ip, 0, 45)]);
                }
            }
            respond(200, ['ok' => true]);
            break;

        // ---------------------------------------------------------------
        case 'usage_stats':
            // Aggregated usage figures. Editors only.
            if (!svdp_can_edit()) {
                respond(401, ['error' => 'Not authorized.']);
            }
            $pdo = svdp_db();
            $pdo->exec('CREATE TABLE IF NOT EXISTS usage_stats (day date NOT NULL, section text NOT NULL, hits integer NOT NULL DEFAULT 0, PRIMARY KEY (day, section))');
            $perSectionAllTime = $pdo->query(
                'SELECT section, SUM(hits)::int AS hits FROM usage_stats GROUP BY section ORDER BY hits DESC'
            )->fetchAll();
            // Daily hits per section for the last 180 days (sparse; only days with activity).
            $rows = $pdo->query(
                "SELECT section, to_char(day,'YYYY-MM-DD') AS day, hits::int AS hits
                 FROM usage_stats WHERE day >= CURRENT_DATE - 179
                 ORDER BY section, day"
            )->fetchAll();
            $totals = $pdo->query(
                "SELECT
                    COALESCE(SUM(hits) FILTER (WHERE section='visit'),0)::int AS all_time,
                    COALESCE(SUM(hits) FILTER (WHERE section='visit' AND day >= CURRENT_DATE - 6),0)::int AS last7,
                    COALESCE(SUM(hits) FILTER (WHERE section='visit' AND day >= CURRENT_DATE - 29),0)::int AS last30,
                    COALESCE(SUM(hits) FILTER (WHERE section='visit' AND day >= CURRENT_DATE - 179),0)::int AS last180
                 FROM usage_stats"
            )->fetch();
            $pdo->exec('CREATE TABLE IF NOT EXISTS usage_ips (day date NOT NULL, ip text NOT NULL, hits integer NOT NULL DEFAULT 0, PRIMARY KEY (day, ip))');
            $topIps = $pdo->query(
                "SELECT ip, SUM(hits)::int AS hits FROM usage_ips
                 WHERE day >= CURRENT_DATE - 6
                 GROUP BY ip ORDER BY hits DESC, ip LIMIT 10"
            )->fetchAll();
            respond(200, ['ok' => true, 'window' => 180, 'perSectionAllTime' => $perSectionAllTime, 'rows' => $rows, 'totals' => $totals, 'topIps' => $topIps]);
            break;

        // ---------------------------------------------------------------
        case 'parse_dispatch':
            // Read the first table out of an already-uploaded .docx report and
            // return it as a grid of rows/cells. Public — the report files
            // themselves are already publicly downloadable, and the Dispatching
            // charts are viewable by everyone (only uploading requires login).
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            $body = read_json_body();
            $name = basename((string)($body['file'] ?? ''));   // constrain to uploads/
            if ($name === '') {
                respond(400, ['error' => 'Missing file.']);
            }
            $path = __DIR__ . '/uploads/' . $name;
            if (!is_file($path)) {
                respond(404, ['error' => 'That file was not found on the server.']);
            }
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'docx') {
                respond(415, ['error' => 'Only Word .docx reports can be analyzed. Attach the .docx version of the dispatcher report.']);
            }
            if (!class_exists('ZipArchive')) {
                respond(500, ['error' => 'The server is missing PHP zip support (install php-zip).']);
            }
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                respond(400, ['error' => 'Could not open the Word file.']);
            }
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml === false) {
                respond(400, ['error' => 'That does not look like a valid Word .docx.']);
            }
            if (!preg_match('/<w:tbl\b.*?<\/w:tbl>/s', $xml, $tm)) {
                respond(422, ['error' => 'No table was found in this document.']);
            }
            $rows = [];
            if (preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $tm[0], $trm)) {
                foreach ($trm[0] as $tr) {
                    $cells = [];
                    if (preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $tr, $tcm)) {
                        foreach ($tcm[0] as $tc) {
                            $tc = preg_replace('/<w:(?:tab|br)\b[^>]*\/?>/', ' ', $tc);
                            $txt = '';
                            if (preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $tc, $tt)) {
                                $txt = implode('', $tt[1]);
                            }
                            $txt = html_entity_decode($txt, ENT_QUOTES | ENT_XML1, 'UTF-8');
                            $cells[] = trim(preg_replace('/[ \t]+/u', ' ', $txt));
                        }
                    }
                    $rows[] = $cells;
                }
            }
            respond(200, ['ok' => true, 'rows' => $rows]);
            break;

        // ---------------------------------------------------------------
        case 'render_doc':
            // Convert an uploaded document (.doc/.docx/.ppt/.pptx/.xls/.xlsx/.pdf)
            // or image into a series of page images, so the Run of Show can show
            // it inline — with full formatting — in one continuous scroll instead
            // of opening separate tabs. Results are cached under
            // uploads/_render/<hash>/ keyed by file content, so each file is only
            // ever converted once. Public — the source files are already
            // publicly downloadable.
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            $body = read_json_body();
            $name = basename((string)($body['file'] ?? ''));   // constrain to uploads/
            if ($name === '') {
                respond(400, ['error' => 'Missing file.']);
            }
            $path = __DIR__ . '/uploads/' . $name;
            if (!is_file($path)) {
                respond(404, ['error' => 'That file was not found on the server.']);
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            // Images already display inline as-is.
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
                respond(200, ['ok' => true, 'kind' => 'image',
                              'pages' => ['uploads/' . rawurlencode($name)], 'count' => 1]);
            }

            $officeExt = ['doc', 'docx', 'odt', 'rtf', 'ppt', 'pptx', 'odp', 'xls', 'xlsx', 'ods'];
            if ($ext !== 'pdf' && !in_array($ext, $officeExt, true)) {
                respond(415, ['error' => "This file type can't be shown inline."]);
            }

            $renderRoot = __DIR__ . '/uploads/_render';
            @mkdir($renderRoot, 0775, true);
            $key      = sha1_file($path) . '-' . $ext;          // content-addressed
            $cacheDir = $renderRoot . '/' . $key;
            $manifest = $cacheDir . '/manifest.json';

            // Already converted? Serve the cached pages.
            if (is_file($manifest)) {
                $m = json_decode((string)@file_get_contents($manifest), true);
                if (is_array($m) && !empty($m['pages'])) {
                    respond(200, ['ok' => true, 'kind' => 'pages',
                                  'pages' => $m['pages'], 'count' => count($m['pages'])]);
                }
            }

            if (!function_exists('shell_exec')) {
                respond(422, ['error' => 'Inline rendering is unavailable on the server (shell access disabled).']);
            }
            $pdftoppm = trim((string)@shell_exec('command -v pdftoppm 2>/dev/null'));
            if ($pdftoppm === '') {
                respond(422, ['error' => 'Inline rendering needs poppler-utils (pdftoppm) on the server.']);
            }
            $timeoutBin = trim((string)@shell_exec('command -v timeout 2>/dev/null'));
            $pre = ($timeoutBin !== '') ? escapeshellarg($timeoutBin) . ' 120 ' : '';

            // Convert in a private temp workspace; publish only the page images.
            $tmp = $renderRoot . '/tmp-' . bin2hex(random_bytes(6));
            if (!@mkdir($tmp, 0775, true)) {
                respond(500, ['error' => 'Could not create a render workspace.']);
            }

            $pdf = $path;   // for a PDF the source is itself
            if ($ext !== 'pdf') {
                $soffice = trim((string)@shell_exec('command -v libreoffice 2>/dev/null'));
                if ($soffice === '') {
                    $soffice = trim((string)@shell_exec('command -v soffice 2>/dev/null'));
                }
                if ($soffice === '') {
                    rrmdir($tmp);
                    respond(422, ['error' => 'Inline rendering of Office files needs LibreOffice on the server.']);
                }
                // HOME + a private UserInstallation keep concurrent conversions
                // from fighting over one LibreOffice profile.
                $cmd = 'HOME=' . escapeshellarg($tmp) . ' ' . $pre . escapeshellarg($soffice)
                     . ' --headless --norestore --nolockcheck'
                     . ' -env:UserInstallation=file://' . $tmp . '/loprofile'
                     . ' --convert-to pdf --outdir ' . escapeshellarg($tmp) . ' '
                     . escapeshellarg($path) . ' 2>&1';
                @shell_exec($cmd);
                $pdf = $tmp . '/' . pathinfo($name, PATHINFO_FILENAME) . '.pdf';
                if (!is_file($pdf)) {
                    $found = glob($tmp . '/*.pdf');   // LibreOffice may sanitize the name
                    if ($found) {
                        $pdf = $found[0];
                    }
                }
                if (!is_file($pdf)) {
                    rrmdir($tmp);
                    respond(422, ['error' => 'The server could not convert this file for inline viewing.']);
                }
            }

            // PDF -> one PNG per page (cap at 80 pages).
            @shell_exec($pre . escapeshellarg($pdftoppm) . ' -png -r 150 -l 80 '
                . escapeshellarg($pdf) . ' ' . escapeshellarg($tmp . '/p') . ' 2>&1');
            $pngs = glob($tmp . '/p*.png') ?: [];
            if (!$pngs) {
                rrmdir($tmp);
                respond(422, ['error' => 'The server could not render this file to images.']);
            }
            natsort($pngs);
            $pngs = array_values($pngs);

            // Publish the pages under the content-addressed cache dir.
            @mkdir($cacheDir, 0775, true);
            $pages = [];
            foreach ($pngs as $n => $src) {
                $destName = sprintf('p-%03d.png', $n + 1);
                @rename($src, $cacheDir . '/' . $destName)
                    || @copy($src, $cacheDir . '/' . $destName);
                $pages[] = 'uploads/_render/' . $key . '/' . $destName;
            }
            @file_put_contents($manifest, json_encode(['pages' => $pages]));
            rrmdir($tmp);

            respond(200, ['ok' => true, 'kind' => 'pages',
                          'pages' => $pages, 'count' => count($pages)]);
            break;

        // ---------------------------------------------------------------
        case 'survey_submit':
            // A member submits answers to an open survey. Public (members only
            // use this site), best-effort stored in survey_responses. The survey
            // definitions live in the content blob; we check the survey exists
            // and is "open" before storing.
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            $body     = read_json_body();
            $surveyId = trim((string)($body['surveyId'] ?? ''));
            $answers  = $body['answers'] ?? null;
            if ($surveyId === '' || !is_array($answers)) {
                respond(400, ['error' => 'Missing survey or answers.']);
            }
            $pdo = svdp_db();
            ensure_survey_table($pdo);
            $row     = $pdo->query('SELECT data FROM content WHERE id = 1')->fetch();
            $content = $row ? json_decode($row['data'], true) : null;
            $surveys = (is_array($content) && isset($content['surveys']) && is_array($content['surveys']))
                ? $content['surveys'] : [];
            $survey = null;
            foreach ($surveys as $s) { if (($s['id'] ?? '') === $surveyId) { $survey = $s; break; } }
            if ($survey === null) {
                respond(404, ['error' => 'That survey was not found.']);
            }
            if (($survey['status'] ?? '') !== 'open') {
                respond(403, ['error' => 'This survey is not currently accepting responses.']);
            }
            $answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($answersJson === false) {
                respond(400, ['error' => 'Answers could not be encoded.']);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO survey_responses (survey_id, answers, ip)
                 VALUES (:sid, CAST(:a AS jsonb), :ip)'
            );
            $stmt->execute([':sid' => $surveyId, ':a' => $answersJson, ':ip' => client_ip()]);
            respond(200, ['ok' => true]);
            break;

        // ---------------------------------------------------------------
        case 'survey_results':
            // Editors only: every stored response for one survey.
            if (!svdp_can_edit()) {
                respond(401, ['error' => 'Please log in to view survey results.']);
            }
            $surveyId = trim((string)($_GET['survey'] ?? ''));
            if ($surveyId === '') {
                respond(400, ['error' => 'Missing survey id.']);
            }
            $pdo = svdp_db();
            ensure_survey_table($pdo);
            $stmt = $pdo->prepare(
                'SELECT id, answers, submitted_at FROM survey_responses
                 WHERE survey_id = :sid ORDER BY submitted_at'
            );
            $stmt->execute([':sid' => $surveyId]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'id'           => (int)$r['id'],
                    'answers'      => json_decode($r['answers'], true),
                    'submitted_at' => $r['submitted_at'],
                ];
            }
            respond(200, ['ok' => true, 'count' => count($out), 'responses' => $out]);
            break;

        // ---------------------------------------------------------------
        case 'survey_clear':
            // Editors only: delete all stored responses for one survey (e.g.
            // before reopening it for a new round).
            if ($method !== 'POST') {
                respond(405, ['error' => 'Use POST.']);
            }
            if (!svdp_can_edit()) {
                respond(401, ['error' => 'Please log in.']);
            }
            $body     = read_json_body();
            $surveyId = trim((string)($body['surveyId'] ?? ''));
            if ($surveyId === '') {
                respond(400, ['error' => 'Missing survey id.']);
            }
            $pdo = svdp_db();
            ensure_survey_table($pdo);
            $stmt = $pdo->prepare('DELETE FROM survey_responses WHERE survey_id = :sid');
            $stmt->execute([':sid' => $surveyId]);
            respond(200, ['ok' => true, 'deleted' => $stmt->rowCount()]);
            break;

        // ---------------------------------------------------------------
        default:
            respond(404, ['error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('SVdP API error: ' . $e->getMessage());
    respond(500, ['error' => 'Server error.']);
}
