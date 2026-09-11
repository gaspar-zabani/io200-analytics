<?php

// Internal dashboard handler: authentication and the database connection must exist.
if (!isset($authenticated, $mysqli, $whereAdmin) || $authenticated !== true) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
try {
    $query = $_GET['photo_search'] ?? '';
    if (!is_string($query) || strlen($query) > 200) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid search']);
        exit;
    }
    $query = trim($query);
    if ($query === '') {
        echo json_encode(['results' => []]);
        exit;
    }

    // Separate from CMS sessions; cache only after dashboard authentication.
    session_name('ioa_photo_search');
    $sessionStarted = session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict',
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'use_strict_mode' => true]);
    if (!$sessionStarted) throw new RuntimeException('Search cache unavailable');
    $cacheKey = hash('sha256', __DIR__ . CMS_DB_DATABASE . $whereAdmin);
    $cache = $_SESSION['ioa_photo_search'][$cacheKey] ?? null;
    if (!is_array($cache) || ($cache['expires'] ?? 0) <= time()) {
        $photos = [];
        $rows = $mysqli->query("SELECT photo_id, image_url FROM ioa_events
            WHERE photo_id > 0 {$whereAdmin} ORDER BY id ASC");
        while ($row = $rows->fetch_assoc()) {
            $id = (string)$row['photo_id'];
            $photos[$id] ??= ['id' => $id, 'title' => null, 'image_url' => null];
            $image = safeDashboardResourceUrl($row['image_url']);
            if ($image !== null) $photos[$id]['image_url'] = $image;
        }
        $rows = $mysqli->query("SELECT batch_data FROM ioa_events
            WHERE event_type = 'batch_download' {$whereAdmin} ORDER BY id ASC");
        while ($row = $rows->fetch_assoc()) {
            $batch = json_decode($row['batch_data'] ?? '', true);
            if (!is_array($batch) || !is_array($batch['photo_ids'] ?? null)) continue;
            foreach ($batch['photo_ids'] as $index => $rawId) {
                if (!is_scalar($rawId) || !ctype_digit((string)$rawId) || (int)$rawId <= 0) continue;
                $id = (string)(int)$rawId;
                $photos[$id] ??= ['id' => $id, 'title' => null, 'image_url' => null];
                $image = safeDashboardResourceUrl($batch['image_urls'][$index] ?? null);
                if ($photos[$id]['image_url'] === null && $image !== null) $photos[$id]['image_url'] = $image;
            }
        }
        // Bound each title lookup; never search the full CMS photo inventory.
        foreach (array_chunk(array_keys($photos), 500) as $ids) {
            $idList = implode(',', array_map('intval', $ids));
            $rows = $mysqli->query("SELECT id, title FROM cms_photos WHERE id IN ({$idList})");
            while ($row = $rows->fetch_assoc()) {
                $photos[(string)$row['id']]['title'] = trim((string)$row['title']) ?: null;
            }
        }
        $cache = ['expires' => time() + 60, 'photos' => $photos];
        $_SESSION['ioa_photo_search'][$cacheKey] = $cache;
    }
    session_write_close();
    $matches = [];
    $exactId = ctype_digit($query) ? ltrim($query, '0') : null;
    foreach ($cache['photos'] as $photo) {
        $exact = $exactId !== null && $photo['id'] === $exactId;
        $titleMatch = function_exists('mb_stripos')
            ? mb_stripos($photo['title'] ?? '', $query, 0, 'UTF-8') !== false
            : stripos($photo['title'] ?? '', $query) !== false;
        if ($exact || $titleMatch) $matches[] = $photo;
    }
    usort($matches, static function ($a, $b) use ($exactId) {
        return (($b['id'] === $exactId) <=> ($a['id'] === $exactId))
            ?: strnatcasecmp($a['title'] ?? '', $b['title'] ?? '')
            ?: ((int)$a['id'] <=> (int)$b['id']);
    });
    echo json_encode(['results' => array_slice($matches, 0, 5)], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    error_log('[IO200 Analytics] Photo search: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search unavailable']);
}
