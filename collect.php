<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../system/config.php';

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'ok' => false,
        'error' => 'Method not allowed'
    ]);
}

$input = file_get_contents('php://input');

if ($input === false || strlen($input) > 50000) {
    respond(400, [
        'ok' => false,
        'error' => 'Invalid request body'
    ]);
}

$data = json_decode($input, true);

if (!is_array($data)) {
    respond(400, [
        'ok' => false,
        'error' => 'Invalid JSON'
    ]);
}

$allowedEvents = [
    'photo_view',
    'basket_add',
    'basket_remove',
    'photo_download',
    'batch_download'
];

$type = $data['type'] ?? null;

if (!in_array($type, $allowedEvents, true)) {
    respond(400, [
        'ok' => false,
        'error' => 'Invalid event type'
    ]);
}

$page = isset($data['page']) && is_string($data['page'])
    ? substr($data['page'], 0, 500)
    : null;

$imageUrl = isset($data['image_url']) && is_string($data['image_url'])
    ? substr($data['image_url'], 0, 2000)
    : null;

$downloadUrl = isset($data['download_url']) && is_string($data['download_url'])
    ? substr($data['download_url'], 0, 2000)
    : null;

$sessionId = isset($data['session_id']) && is_string($data['session_id'])
    ? substr($data['session_id'], 0, 64)
    : null;

$photoId = null;

if (isset($data['photo_id'])) {
    if (!is_numeric($data['photo_id'])) {
        respond(400, [
            'ok' => false,
            'error' => 'Invalid photo_id'
        ]);
    }

    $photoId = (int)$data['photo_id'];

    if ($photoId <= 0) {
        respond(400, [
            'ok' => false,
            'error' => 'Invalid photo_id'
        ]);
    }
}

$batchData = null;

if ($type === 'batch_download') {

    $photoIds = $data['photo_ids'] ?? [];
    $photoUrls = $data['photo_urls'] ?? [];

    if (!is_array($photoIds) || !is_array($photoUrls)) {
        respond(400, [
            'ok' => false,
            'error' => 'Invalid batch data'
        ]);
    }

    if (count($photoIds) > 500 || count($photoUrls) > 500) {
        respond(400, [
            'ok' => false,
            'error' => 'Batch too large'
        ]);
    }

    $cleanPhotoIds = [];

    foreach ($photoIds as $id) {
        if (!is_numeric($id)) {
            continue;
        }

        $id = (int)$id;

        if ($id > 0) {
            $cleanPhotoIds[] = $id;
        }
    }

    $cleanPhotoUrls = [];

    foreach ($photoUrls as $url) {
        if (!is_string($url)) {
            continue;
        }

        $cleanPhotoUrls[] = substr($url, 0, 2000);
    }

    $batchData = json_encode([
        'photo_ids' => $cleanPhotoIds,
        'photo_urls' => $cleanPhotoUrls,
        'count' => count($cleanPhotoIds)
    ]);
}

try {

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $mysqli = new mysqli(
        CMS_DB_HOSTNAME,
        CMS_DB_USERNAME,
        CMS_DB_PASSWORD,
        CMS_DB_DATABASE
    );

    $mysqli->set_charset('utf8mb4');

    $stmt = $mysqli->prepare("
        INSERT INTO ioa_events
        (
            event_type,
            page_path,
            photo_id,
            image_url,
            download_url,
            batch_data,
            session_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        'ssissss',
        $type,
        $page,
        $photoId,
        $imageUrl,
        $downloadUrl,
        $batchData,
        $sessionId
    );

    $stmt->execute();

    $eventId = $stmt->insert_id;

    $stmt->close();
    $mysqli->close();

    respond(200, [
        'ok' => true,
        'event_id' => $eventId
    ]);

} catch (Throwable $e) {

    error_log(
        '[IO200 Analytics] Collector error: ' .
        $e->getMessage()
    );

    respond(500, [
        'ok' => false,
        'error' => 'Server error'
    ]);
}