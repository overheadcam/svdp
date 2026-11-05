<?php
/**
 * ServWare integration seam (placeholder).
 *
 * This file is NOT wired into anything yet. It exists so that, when ServWare
 * API access is arranged, the integration lands in one clearly-defined place
 * instead of being scattered through the app.
 *
 * Typical shape once you have credentials: fill in config.php['servware'],
 * flip 'enabled' to true, and implement the calls below using PHP's cURL
 * (already available on a standard Ubuntu PHP install via php-curl).
 *
 * Nothing here talks to ServWare until you implement it, and the rest of the
 * Resource Center works fully without it.
 */
declare(strict_types=1);

function servware_enabled(): bool
{
    $s = svdp_config()['servware'] ?? [];
    return !empty($s['enabled']) && !empty($s['base_url']);
}

/**
 * Example scaffold for a future GET call to ServWare.
 * Returns decoded JSON or null. Implement per ServWare's actual API.
 */
function servware_get(string $path): ?array
{
    if (!servware_enabled()) {
        return null;
    }
    $cfg = svdp_config()['servware'];
    $url = rtrim($cfg['base_url'], '/') . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . ($cfg['api_key'] ?? ''),
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        error_log("ServWare GET $url failed (HTTP $code)");
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}
