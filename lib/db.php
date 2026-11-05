<?php
/**
 * Database connection helper.
 * Returns a shared PDO instance connected to PostgreSQL.
 */
declare(strict_types=1);

function svdp_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/../config.php';
        if (!is_file($path)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Server not configured: config.php is missing.']);
            exit;
        }
        $config = require $path;
    }
    return $config;
}

function svdp_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = svdp_config()['db'];
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $c['host'], $c['port'], $c['dbname']
        );
        try {
            $pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            // Do not leak credentials/details to the client.
            echo json_encode(['error' => 'Database connection failed.']);
            error_log('SVdP DB connect error: ' . $e->getMessage());
            exit;
        }
    }
    return $pdo;
}
