<?php

// Replace with the production manifest endpoint when publishing is ready.
// Never derive this URL from request parameters or customer configuration.
const IOA_UPDATE_MANIFEST_URL = 'https://jesperalvermark.se/ioa-versioncontrol/manifest/manifest.json';

function ioaParseVersion($version): ?array
{
    if (!is_string($version) || strlen($version) > 128 || !preg_match(
        '/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?\z/',
        $version,
        $parts
    )) {
        return null;
    }
    $pre = isset($parts[4]) && $parts[4] !== '' ? explode('.', $parts[4]) : [];
    foreach ($pre as $identifier) {
        if (ctype_digit($identifier) && strlen($identifier) > 1 && $identifier[0] === '0') {
            return null;
        }
    }
    return ['core' => array_slice($parts, 1, 3), 'pre' => $pre];
}

// Compare numeric identifiers as strings to avoid integer overflow.
function ioaCompareVersionNumber(string $a, string $b): int
{
    return (strlen($a) <=> strlen($b)) ?: strcmp($a, $b);
}

function ioaCompareVersions(string $a, string $b): ?int
{
    $left = ioaParseVersion($a);
    $right = ioaParseVersion($b);
    if ($left === null || $right === null) return null;
    foreach ($left['core'] as $index => $number) {
        $order = ioaCompareVersionNumber($number, $right['core'][$index]);
        if ($order !== 0) return $order <=> 0;
    }
    if (!$left['pre'] || !$right['pre']) {
        return (!$left['pre'] <=> !$right['pre']);
    }
    foreach ($left['pre'] as $index => $identifier) {
        if (!isset($right['pre'][$index])) return 1;
        $other = $right['pre'][$index];
        $numeric = ctype_digit($identifier);
        $otherNumeric = ctype_digit($other);
        $order = $numeric && $otherNumeric
            ? ioaCompareVersionNumber($identifier, $other)
            : ($numeric !== $otherNumeric ? ($numeric ? -1 : 1) : strcmp($identifier, $other));
        if ($order !== 0) return $order <=> 0;
    }
    return count($left['pre']) <=> count($right['pre']);
}

function ioaValidateManifest($manifest): ?array
{
    if (!is_object($manifest) || count(get_object_vars($manifest)) !== 2
        || ioaParseVersion($manifest->version ?? null) === null
        || !is_string($manifest->download_url ?? null)
        || strlen($manifest->download_url) > 2048
        || !filter_var($manifest->download_url, FILTER_VALIDATE_URL)) return null;
    $url = parse_url($manifest->download_url);
    if (($url['scheme'] ?? '') !== 'https' || empty($url['host'])
        || isset($url['user']) || isset($url['pass'])
        || strpos($manifest->download_url, chr(92)) !== false
        || preg_match('/[\x00-\x20\x7f]/', $manifest->download_url)) return null;
    return ['version' => $manifest->version, 'download_url' => $manifest->download_url];
}

function ioaFetchUpdateManifest(): ?array
{
    if (!function_exists('curl_init')) return null;
    $url = IOA_UPDATE_MANIFEST_URL;
    if (parse_url($url, PHP_URL_SCHEME) !== 'https') return null;
    // The reserved placeholder is intentionally inactive until configured in code.
    if (substr((string)parse_url($url, PHP_URL_HOST), -8) === '.invalid') return null;
    $handle = curl_init($url);
    if ($handle === false) return null;
    $body = '';
    try {
        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'IO200-Analytics-Update-Check',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 4096) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }
        ]);
        if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) return null;
        $manifest = json_decode($body, false, 4);
        return json_last_error() === JSON_ERROR_NONE ? ioaValidateManifest($manifest) : null;
    } finally {
        unset($handle);
    }
}

// Returns only a validated newer release, or null. Never renders technical status.
function ioaAvailableUpdate(string $installedVersion): ?array
{
    if (ioaParseVersion($installedVersion) === null) return null;
    $cache = false;
    set_error_handler(static function ($severity, $message, $file, $line): void {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $key = hash('sha256', __DIR__ . '|' . IOA_UPDATE_MANIFEST_URL);
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ioa-update-' . $key . '.json';
        if (is_link($path)) return null;
        $cache = fopen($path, 'c+');
        if ($cache === false || !flock($cache, LOCK_EX | LOCK_NB)) return null;
        $stored = json_decode((string)stream_get_contents($cache, 8192));
        $now = time();
        $remote = ioaValidateManifest($stored->manifest ?? null);
        $fresh = is_object($stored) && isset($stored->checked_at) && is_int($stored->checked_at)
            && property_exists($stored, 'manifest')
            && ($stored->manifest === null || $remote !== null)
            && $stored->checked_at <= $now
            && $now - $stored->checked_at < ($remote === null ? 900 : 21600);
        if (!$fresh) {
            // Record the attempt before networking, including interrupted checks.
            $failure = json_encode(['checked_at' => $now, 'manifest' => null]);
            rewind($cache);
            if (!ftruncate($cache, 0) || fwrite($cache, $failure) !== strlen($failure) || !fflush($cache)) return null;
            $remote = ioaFetchUpdateManifest();
            $result = json_encode(['checked_at' => $now, 'manifest' => $remote]);
            rewind($cache);
            if (!ftruncate($cache, 0) || fwrite($cache, $result) !== strlen($result) || !fflush($cache)) return null;
        }
        return $remote !== null && ioaCompareVersions($remote['version'], $installedVersion) > 0 ? $remote : null;
    } catch (Throwable $e) {
        return null;
    } finally {
        // Close while warnings are still contained, then restore the caller's handler.
        try {
            if (is_resource($cache)) fclose($cache);
        } catch (Throwable $e) {
            // Cache failures must not affect analytics.
        }
        restore_error_handler();
    }
}
