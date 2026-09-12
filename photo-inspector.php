<?php

// Included only by the authenticated dashboard, with its existing filters/helpers.
if (!isset($authenticated, $mysqli, $whereAdmin, $whereDate) || $authenticated !== true) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
try {
    $rawId = $_GET['photo_inspector'] ?? null;
    $photoId = is_string($rawId)
        ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if ($photoId === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid photo ID']);
        exit;
    }

    $emptyCounts = ['views' => 0, 'direct_downloads' => 0, 'selection_downloads' => 0,
        'basket_adds' => 0, 'basket_removes' => 0];
    $photo = ['id' => (string)$photoId, 'title' => null, 'image_url' => null,
        'metrics' => $emptyCounts, 'latest' => null, 'albums' => [], 'activity' => []];

    // Read all link types so conflicting non-album routes cannot become album activity.
    // Exact paths only: nested paths may be Photo Pages or other contexts.
    $routes = [];
    $rows = $mysqli->query("SELECT l.path, l.template, l.reference_type,
        c.id, c.title, c.type
        FROM cms_links l LEFT JOIN cms_collections c ON c.id = l.reference_id
        WHERE l.path IS NOT NULL AND l.path <> ''");
    while ($row = $rows->fetch_assoc()) {
        if (!is_string($row['path']) || !str_starts_with($row['path'], '/')
            || str_starts_with($row['path'], '//')) continue;
        $path = normalizedDashboardPath($row['path']);
        // The front page can be routed independently of its stored link path.
        if ($path === null || $path === '/') continue;
        $album = $row['template'] === 'album' && $row['reference_type'] === 'album'
            && $row['type'] === 'album' && (int)$row['id'] > 0
            ? ['id' => (string)$row['id'], 'title' => trim((string)$row['title'])] : null;
        if (!array_key_exists($path, $routes)) {
            $routes[$path] = $album;
        } elseif ($routes[$path] !== $album) {
            $routes[$path] = null;
        }
    }

    // Identity may use older recorded images; metrics use precisely the dashboard period.
    $stmt = $mysqli->prepare("SELECT event_type, photo_id, image_url, batch_data,
        page_path, created_at, (1 {$whereDate}) AS in_period
        FROM ioa_events
        WHERE (photo_id = ? OR event_type = 'batch_download') {$whereAdmin}
        ORDER BY created_at ASC, id ASC");
    $stmt->bind_param('i', $photoId);
    $stmt->execute();
    $stmt->bind_result($eventType, $eventPhotoId, $imageUrl, $batchData, $pagePath, $createdAt, $inPeriod);
    $known = false;
    $activity = [];
    $metricKeys = ['photo_view' => 'views', 'photo_download' => 'direct_downloads',
        'batch_download' => 'selection_downloads', 'basket_add' => 'basket_adds',
        'basket_remove' => 'basket_removes'];
    $matchPhoto = static function ($eventType, $eventPhotoId, $imageUrl, $batchData) use ($photoId, $metricKeys) {
        $image = null;
        if ($eventType === 'batch_download') {
            $batch = json_decode($batchData ?? '', true);
            if (!is_array($batch) || !is_array($batch['photo_ids'] ?? null)) return null;
            $matched = false;
            foreach ($batch['photo_ids'] as $index => $id) {
                if (!is_scalar($id) || !ctype_digit((string)$id) || (string)(int)$id !== (string)$photoId) continue;
                $matched = true;
                $image = safeDashboardResourceUrl($batch['image_urls'][$index] ?? null) ?? $image;
            }
            if (!$matched) return null;
        } else {
            if ((string)$eventPhotoId !== (string)$photoId || !isset($metricKeys[$eventType])) return null;
            $image = safeDashboardResourceUrl($imageUrl);
        }
        return [$metricKeys[$eventType], $image];
    };
    $recordContext = static function (&$activity, $pagePath, $key) use ($routes, $emptyCounts) {
        $path = normalizedDashboardPath($pagePath);
        $album = $path !== null ? ($routes[$path] ?? null) : null;
        $context = $album !== null ? 'album:' . $album['id'] : 'other';
        $activity[$context] ??= ['album_id' => $album['id'] ?? null,
            'title' => $album !== null ? ($album['title'] ?: 'Album ' . $album['id']) : 'Other / unresolved contexts',
            'metrics' => $emptyCounts];
        $activity[$context]['metrics'][$key]++;
    };
    while ($stmt->fetch()) {
        $match = $matchPhoto($eventType, $eventPhotoId, $imageUrl, $batchData);
        if ($match === null) continue;
        [$key, $image] = $match;
        $known = true;
        if ($image !== null) $photo['image_url'] = $image;
        if (!$inPeriod) continue;
        // One selected-photo download per matching batch event, even if an ID is repeated.
        $photo['metrics'][$key]++;
        $photo['latest'] = $createdAt;
        $recordContext($activity, $pagePath, $key);
    }
    $stmt->close();
    if (!$known) {
        http_response_code(404);
        echo json_encode(['error' => 'No recorded activity for this photo']);
        exit;
    }

    $stmt = $mysqli->prepare('SELECT title FROM cms_photos WHERE id = ?');
    $stmt->bind_param('i', $photoId);
    $stmt->execute();
    $stmt->bind_result($title);
    if ($stmt->fetch()) $photo['title'] = trim((string)$title) ?: null;
    $stmt->close();

    $stmt = $mysqli->prepare("SELECT c.id, c.title FROM cms_collections c
        WHERE c.type = 'album' AND c.membership_type = 'static'
        AND EXISTS (SELECT 1 FROM cms_collections_photos cp
            WHERE cp.collection_id = c.id AND cp.photo_id = ?)
        ORDER BY c.title, c.id");
    $stmt->bind_param('i', $photoId);
    $stmt->execute();
    $stmt->bind_result($albumId, $albumTitle);
    while ($stmt->fetch()) {
        $photo['albums'][] = ['id' => (string)$albumId, 'title' => trim((string)$albumTitle) ?: 'Album ' . $albumId];
    }
    $stmt->close();
    $photo['activity'] = array_values($activity);
    usort($photo['activity'], static function ($a, $b) {
        return (($a['album_id'] === null) <=> ($b['album_id'] === null))
            ?: strnatcasecmp($a['title'], $b['title'])
            ?: ((int)$a['album_id'] <=> (int)$b['album_id']);
    });
    $response = ['photo' => $photo];
    if (array_key_exists('visit', $_GET)) {
        $rawVisit = $_GET['visit'];
        $visitId = is_string($rawVisit)
            ? filter_var($rawVisit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $context = ['status' => 'unavailable', 'visit_id' => $visitId === false ? null : $visitId,
            'scope' => 'full_visit', 'metrics' => $emptyCounts,
            'first_activity' => null, 'latest_activity' => null, 'activity' => []];
        // Optional context must never discard the successfully computed overall response.
        try {
            if ($visitId !== false) {
                $stmt = $mysqli->prepare("SELECT session_id FROM ioa_events WHERE id = ? {$whereAdmin}");
                $stmt->bind_param('i', $visitId);
                $stmt->execute();
                $stmt->bind_result($sessionId);
                $session = $stmt->fetch() ? $sessionId : null;
                $stmt->close();
                if (is_string($session) && $session !== '') {
                    $stmt = $mysqli->prepare("SELECT id, session_id, event_type, photo_id, image_url,
                        batch_data, page_path, created_at, (1 {$whereDate}) AS in_period
                        FROM ioa_events WHERE session_id = ? {$whereAdmin}
                        ORDER BY created_at ASC, id ASC");
                    $stmt->bind_param('s', $session);
                    $stmt->execute();
                    $stmt->bind_result($id, $sessionId, $eventType, $eventPhotoId, $imageUrl,
                        $batchData, $pagePath, $createdAt, $inPeriod);
                    $history = [];
                    while ($stmt->fetch()) {
                        $history[] = ['id' => $id, 'session_id' => $sessionId, 'event_type' => $eventType,
                            'photo_id' => $eventPhotoId, 'image_url' => $imageUrl, 'batch_data' => $batchData,
                            'page_path' => $pagePath, 'created_at' => $createdAt, 'in_period' => $inPeriod];
                    }
                    $stmt->close();
                    foreach (ioaSegmentVisits($history) as $episode) {
                        if ($episode['visit_id'] !== $visitId) continue;
                        if (!array_filter($episode['events'], static function ($event) { return (bool)$event['in_period']; })) {
                            $context['status'] = 'outside_period';
                            break;
                        }
                        $context['status'] = 'available';
                        $visitActivity = [];
                        foreach ($episode['events'] as $event) {
                            $match = $matchPhoto($event['event_type'], $event['photo_id'], $event['image_url'], $event['batch_data']);
                            if ($match === null) continue;
                            $key = $match[0];
                            $context['metrics'][$key]++;
                            $context['first_activity'] ??= $event['created_at'];
                            $context['latest_activity'] = $event['created_at'];
                            $recordContext($visitActivity, $event['page_path'], $key);
                        }
                        $context['activity'] = array_values($visitActivity);
                        break;
                    }
                }
            }
        } catch (Throwable $error) {
            error_log('[IO200 Analytics] Inspector Visit: ' . $error->getMessage());
            $context = ['status' => 'unavailable', 'visit_id' => $visitId === false ? null : $visitId,
                'scope' => 'full_visit', 'metrics' => $emptyCounts,
                'first_activity' => null, 'latest_activity' => null, 'activity' => []];
        }
        $response['this_visit'] = $context;
    }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('[IO200 Analytics] Photo inspector: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Photo details unavailable']);
}
