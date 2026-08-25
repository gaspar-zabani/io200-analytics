<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../system/config.php';

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$expectedOrigin = defined('WEBSITE_URL') ? normalizedOrigin(WEBSITE_URL) : null;

if ($expectedOrigin === null) {
    error_log('[IO200 Analytics] Collector configuration error: invalid WEBSITE_URL');
    respond(500, ['ok' => false, 'error' => 'Server error']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'ok' => false,
        'error' => 'Method not allowed'
    ]);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

if ($mediaType !== 'application/json') {
    respond(415, ['ok' => false, 'error' => 'Unsupported media type']);
}

$requestOrigin = null;
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== '') {
    $requestOrigin = normalizedOrigin($_SERVER['HTTP_ORIGIN']);
} elseif (isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '') {
    $requestOrigin = normalizedOrigin($_SERVER['HTTP_REFERER']);
}

if (!originsMatch($requestOrigin, $expectedOrigin)) {
    respond(403, ['ok' => false, 'error' => 'Forbidden']);
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

$imageUrl = null;
if (array_key_exists('image_url', $data) && $data['image_url'] !== null) {
    if (!is_string($data['image_url']) || strlen($data['image_url']) > 2000) {
        respond(400, ['ok' => false, 'error' => 'Invalid image URL']);
    }

    $imageUrl = normalizeResourcePath($data['image_url'], $expectedOrigin);
    if ($imageUrl === null) {
        respond(400, ['ok' => false, 'error' => 'Invalid image URL']);
    }
}

$downloadUrl = null;
if (array_key_exists('download_url', $data) && $data['download_url'] !== null) {
    if (!is_string($data['download_url']) || strlen($data['download_url']) > 2000) {
        respond(400, ['ok' => false, 'error' => 'Invalid download URL']);
    }

    $downloadUrl = normalizeResourcePath($data['download_url'], $expectedOrigin);
    if ($downloadUrl === null) {
        respond(400, ['ok' => false, 'error' => 'Invalid download URL']);
    }
}

$sessionId = isset($data['session_id']) && is_string($data['session_id'])
    ? substr($data['session_id'], 0, 64)
    : null;

$isAdmin = 0;

if (array_key_exists('is_admin', $data)) {
    if (!is_int($data['is_admin']) || !in_array($data['is_admin'], [0, 1], true)) {
        respond(400, [
            'ok' => false,
            'error' => 'Invalid is_admin flag'
        ]);
    }

    $isAdmin = $data['is_admin'];
}

function normalizedOrigin($url) {
    if (!is_string($url) || $url === '') return null;

    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) return null;

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) return null;

    $host = strtolower(rtrim($parts['host'], '.'));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if ($host === '' || $port < 1 || $port > 65535) return null;

    return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
}

function originsMatch($left, $right) {
    return is_array($left) && is_array($right)
        && $left['scheme'] === $right['scheme']
        && $left['host'] === $right['host']
        && $left['port'] === $right['port'];
}

function normalizeResourcePath($url, $expectedOrigin) {
    if (!is_string($url) || $url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) return null;

    if (substr($url, 0, 2) === '//') {
        $url = $expectedOrigin['scheme'] . ':' . $url;
    }

    $parts = parse_url($url);
    if ($parts === false) return null;

    if (substr($url, 0, 1) !== '/') {
        if (!isset($parts['scheme'], $parts['host']) || !originsMatch(normalizedOrigin($url), $expectedOrigin)) return null;
    }

    if (!isset($parts['path']) || substr($parts['path'], 0, 1) !== '/' || substr($parts['path'], 0, 2) === '//') return null;

    foreach (explode('/', $parts['path']) as $segment) {
        $decodedSegment = rawurldecode($segment);
        if (
            $decodedSegment === '.' ||
            $decodedSegment === '..' ||
            preg_match('/[\x00-\x1F\x7F\\\\\/]/', $decodedSegment)
        ) return null;
    }

    return $parts['path'];
}

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
        if (!is_string($url) || strlen($url) > 2000) {
            respond(400, ['ok' => false, 'error' => 'Invalid batch URL']);
        }

        $cleanUrl = normalizeResourcePath($url, $expectedOrigin);
        if ($cleanUrl === null) {
            respond(400, ['ok' => false, 'error' => 'Invalid batch URL']);
        }

        $cleanPhotoUrls[] = $cleanUrl;
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
            session_id,
            is_admin
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        'ssissssi',
        $type,
        $page,
        $photoId,
        $imageUrl,
        $downloadUrl,
        $batchData,
        $sessionId,
        $isAdmin
    );

    $stmt->execute();

    $stmt->close();
    $mysqli->close();

    respond(200, [
        'ok' => true
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
