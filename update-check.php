<?php

// Replace with the production manifest endpoint when publishing is ready.
// Never derive this URL from request parameters or customer configuration.
const IOA_UPDATE_MANIFEST_URL = 'https://updates.io200-analytics.invalid/manifest.json';

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

function ioaManifestVersion(string $body): ?string
{
    if (strlen($body) > 16384) return null;
    $manifest = json_decode($body, false, 8);
    if (json_last_error() !== JSON_ERROR_NONE || !is_object($manifest)
        || ioaParseVersion($manifest->version ?? null) === null) {
        return null;
    }
    // Package, checksum and notes fields are deliberately unused in Phase 1.
    return $manifest->version;
}

function ioaFetchManifestVersion(): ?string
{
    if (!function_exists('curl_init')) return null;
    $url = IOA_UPDATE_MANIFEST_URL;
    if (parse_url($url, PHP_URL_SCHEME) !== 'https') return null;
    // The reserved placeholder is intentionally inactive.
    if (str_ends_with((string)parse_url($url, PHP_URL_HOST), '.invalid')) return null;
    $handle = curl_init($url);
    if ($handle === false) return null;
    $body = '';
    try {
        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'IO200-Analytics-Update-Check',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 16384) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }
        ]);
        $success = curl_exec($handle);
        if ($success === false || curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) return null;
        return ioaManifestVersion($body);
    } finally {
        unset($handle);
    }
}

function ioaUpdateStatus(string $installedVersion): array
{
    $unavailable = ['status' => 'unavailable', 'version' => null];
    if (ioaParseVersion($installedVersion) === null) return $unavailable;
    $cache = false;
    set_error_handler(static function ($severity, $message, $file, $line): void {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        // No analytics/configuration data is written. Scope the cache to this installation and URL.
        $key = hash('sha256', __DIR__ . '|' . IOA_UPDATE_MANIFEST_URL);
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ioa-update-' . $key . '.json';
        if (is_link($path)) return $unavailable;
        $cache = @fopen($path, 'c+');
        if ($cache === false) return $unavailable;
        // Concurrent requests must not wait for another request's network check.
        if (!@flock($cache, LOCK_EX | LOCK_NB)) return $unavailable;
        $stored = json_decode((string)stream_get_contents($cache, 1024), true);
        $now = time();
        $fresh = is_array($stored) && isset($stored['checked_at']) && is_int($stored['checked_at'])
            && array_key_exists('version', $stored)
            && ($stored['version'] === null || ioaParseVersion($stored['version']) !== null)
            && $stored['checked_at'] <= $now
            && $now - $stored['checked_at'] < ($stored['version'] === null ? 900 : 21600);
        if ($fresh) {
            $remote = $stored['version'];
        } else {
            // Persist a failure entry first so a failed/interrupted request is also throttled.
            $failure = json_encode(['checked_at' => $now, 'version' => null]);
            rewind($cache);
            if (!ftruncate($cache, 0) || fwrite($cache, $failure) !== strlen($failure) || !fflush($cache)) {
                return $unavailable;
            }
            $remote = ioaFetchManifestVersion();
            $result = json_encode(['checked_at' => $now, 'version' => $remote]);
            rewind($cache);
            ftruncate($cache, 0);
            fwrite($cache, $result);
            fflush($cache);
        }
        if ($remote === null) return $unavailable;
        return [
            'status' => ioaCompareVersions($remote, $installedVersion) > 0 ? 'available' : 'current',
            'version' => $remote
        ];
    } catch (Throwable $e) {
        return $unavailable;
    } finally {
        restore_error_handler();
        if (is_resource($cache)) @fclose($cache);
    }
}
