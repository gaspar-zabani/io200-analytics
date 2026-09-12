<?php

require_once __DIR__ . '/../../system/config.php';
require_once __DIR__ . '/../../../admin/sys/Autoload.php';
require_once __DIR__ . '/localization.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/visits.php';

// --------------------------------------------------
// IO200 admin authentication
// --------------------------------------------------

$authenticated = false;
$refreshToken = $_COOKIE['refreshtoken'] ?? null;

try {
    if ($refreshToken) {
        $AuthenticationService = new AuthenticationService(
            CMS_SECRETKEY,
            CMS_SECRETKEY,
            'HS256',
            dirname(__DIR__, 3)
        );

        $tokenData = $AuthenticationService->readUserToken($refreshToken);

        if (
            !ErrorInfo::isError($tokenData) &&
            is_array($tokenData) &&
            ($tokenData['type'] ?? null) === 'refresh' &&
            !empty($tokenData['mail'])
        ) {
            $authenticated = true;
        }
    }
} catch (Throwable $e) {
    error_log(
        '[IO200 Analytics] Dashboard authentication check failed: ' .
        $e->getMessage()
    );
}

if (!$authenticated) {
    header('Location: https://jesperalvermark.se/ioa', true, 302);
    exit;
}

// --------------------------------------------------
// Helpers
// --------------------------------------------------

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safeDashboardResourceUrl($value)
{
    if (!is_string($value) || $value === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $value)) {
        return null;
    }

    $websiteParts = parse_url(WEBSITE_URL);
    if ($websiteParts === false || !isset($websiteParts['scheme'], $websiteParts['host'])) {
        return null;
    }

    $expectedScheme = strtolower($websiteParts['scheme']);
    $expectedHost = strtolower(rtrim($websiteParts['host'], '.'));
    $expectedPort = isset($websiteParts['port'])
        ? (int)$websiteParts['port']
        : ($expectedScheme === 'https' ? 443 : 80);

    if (str_starts_with($value, '//')) {
        return null;
    }

    $parts = parse_url($value);
    if ($parts === false) {
        return null;
    }

    if (!str_starts_with($value, '/')) {
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port'])
            ? (int)$parts['port']
            : ($scheme === 'https' ? 443 : 80);

        if (
            !in_array($scheme, ['http', 'https'], true) ||
            $scheme !== $expectedScheme ||
            $host !== $expectedHost ||
            $port !== $expectedPort
        ) {
            return null;
        }
    }

    if (!isset($parts['path']) || !str_starts_with($parts['path'], '/') || str_starts_with($parts['path'], '//')) {
        return null;
    }

    foreach (explode('/', $parts['path']) as $segment) {
        $decodedSegment = rawurldecode($segment);
        if (
            $decodedSegment === '.' ||
            $decodedSegment === '..' ||
            preg_match('/[\x00-\x1F\x7F\\\\\/]/', $decodedSegment)
        ) {
            return null;
        }
    }

    return $parts['path'];
}

function readablePagePath($pagePath)
{
    if (!is_string($pagePath) || trim($pagePath) === '') {
        return null;
    }

    $path = parse_url(trim($pagePath), PHP_URL_PATH);

    if (!is_string($path)) {
        return null;
    }

    $segments = array_values(array_filter(
        explode('/', trim($path, '/')),
        static function ($segment) {
            return $segment !== '';
        }

    ));

    if (!$segments) {
        return null;
    }

    $labels = array_map(static function ($segment) {
        $segment = rawurldecode($segment);
        $segment = trim(str_replace(['-', '_'], ' ', $segment));

        if (preg_match('/^[a-z]+\d+[a-z\d]*$/i', $segment)) {
            return strtoupper($segment);
        }

        return ucfirst($segment);
    }, $segments);

    return implode(' → ', $labels);
}

function normalizedDashboardPath($pagePath)
{
    if (!is_string($pagePath) || trim($pagePath) === '') {
        return null;
    }

    $path = parse_url(trim($pagePath), PHP_URL_PATH);

    if (!is_string($path)) {
        return null;
    }

    $path = '/' . trim($path, '/');
    $websiteDirectory = defined('WEBSITE_DIRECTORY')
        ? trim((string)WEBSITE_DIRECTORY, '/')
        : '';

    if ($websiteDirectory !== '') {
        $websiteDirectory = '/' . $websiteDirectory;
    }

    if (
        $websiteDirectory !== '' &&
        ($path === $websiteDirectory || str_starts_with($path, $websiteDirectory . '/'))
    ) {
        $path = substr($path, strlen($websiteDirectory));
        $path = $path === '' ? '/' : $path;
    }

    return $path;
}

function formatActivityTimestamp($timestamp)
{
    if (!is_string($timestamp) || trim($timestamp) === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($timestamp);
    } catch (Throwable $e) {
        return $timestamp;
    }

    $today = new DateTimeImmutable('today', $date->getTimezone());
    $dateKey = $date->format('Y-m-d');

    if ($dateKey === $today->format('Y-m-d')) {
        return ioa_translate('period_today') . ', ' . $date->format('H:i');
    }

    if ($dateKey === $today->modify('-1 day')->format('Y-m-d')) {
        return ioa_translate('yesterday') . ', ' . $date->format('H:i');
    }

    return $date->format('M j, H:i');
}

function formatActivityRange($firstTimestamp, $lastTimestamp)
{
    $startLabel = formatActivityTimestamp($firstTimestamp);
    $endLabel = formatActivityTimestamp($lastTimestamp);

    try {
        $start = new DateTimeImmutable($firstTimestamp);
        $end = new DateTimeImmutable($lastTimestamp);
        if ($start->format('Y-m-d H:i') === $end->format('Y-m-d H:i')) {
            return $startLabel;
        }
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $startLabel . '–' . $end->format('H:i');
        }
    } catch (Throwable $e) {
        // Preserve the existing timestamp fallback for malformed recorded values.
    }

    return $startLabel . ' – ' . $endLabel;
}

function formatActivitySpan($firstTimestamp, $lastTimestamp)
{
    if (!is_string($firstTimestamp) || !is_string($lastTimestamp)) {
        return null;
    }

    try {
        $first = new DateTimeImmutable($firstTimestamp);
        $last = new DateTimeImmutable($lastTimestamp);
    } catch (Throwable $e) {
        return null;
    }

    $seconds = max(0, $last->getTimestamp() - $first->getTimestamp());

    if ($seconds === 0) {
        return sprintf(ioa_translate('minutes_short'), 0);
    }

    if ($seconds < 60) {
        return ioa_translate('less_than_one_minute');
    }

    $minutes = (int)floor($seconds / 60);

    if ($minutes < 60) {
        return sprintf(ioa_translate('minutes_short'), $minutes);
    }

    $hours = (int)floor($minutes / 60);
    $remainingMinutes = $minutes % 60;

    if ($hours < 24) {
        return sprintf(ioa_translate('hours_minutes_short'), $hours, $remainingMinutes);
    }

    $days = (int)floor($hours / 24);
    $remainingHours = $hours % 24;

    return sprintf(ioa_translate('days_hours_short'), $days, $remainingHours);
}

function formatCountLabel(
    int $count,
    string $singularKey,
    string $pluralKey,
    string $singularFallback,
    string $pluralFallback
): string {
    $key = $count === 1 ? $singularKey : $pluralKey;
    $template = ioa_translate($key);

    // Never expose an internal localization key if a language file is stale or incomplete.
    if ($template === $key) {
        $template = $count === 1 ? $singularFallback : $pluralFallback;
    }

    return sprintf($template, $count);
}

// --------------------------------------------------
// Period filter
// --------------------------------------------------

$period = $_GET['period'] ?? '30';

$allowedPeriods = [
    'today' => ioa_translate('period_today'),
    '7'   => ioa_translate('period_7_days'),
    '30'  => ioa_translate('period_30_days'),
    '90'  => ioa_translate('period_90_days'),
    'all' => ioa_translate('period_all_time')
];

if (!array_key_exists($period, $allowedPeriods)) {
    $period = '30';
}

$allowedPhotoTabs = ['visits', 'views', 'downloads'];
$photoTab = $_GET['photo_tab'] ?? 'visits';

if (!in_array($photoTab, $allowedPhotoTabs, true)) {
    $photoTab = 'visits';
}

$includeAdmin = ($_GET['include_admin'] ?? '') === '1';

$whereAdmin = $includeAdmin
    ? ''
    : 'AND is_admin = 0';

$whereDate = '';

if ($period === 'today') {
    $whereDate = "
        AND created_at >= CURDATE()
        AND created_at <= NOW()
    ";
} elseif ($period !== 'all') {
    $days = (int)$period;

    $whereDate = "
        AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    ";
}

// --------------------------------------------------
// Database
// --------------------------------------------------

try {

    $mysqli = new mysqli(
        CMS_DB_HOSTNAME,
        CMS_DB_USERNAME,
        CMS_DB_PASSWORD,
        CMS_DB_DATABASE
    );

    $mysqli->set_charset('utf8mb4');

    if (isset($_GET['photo_inspector'])) {
        require __DIR__ . '/photo-inspector.php';
        exit;
    }

    if (isset($_GET['photo_search'])) {
        require __DIR__ . '/photo-search.php';
        exit;
    }

    function getSingleValue(mysqli $db, string $sql): int
    {
        $result = $db->query($sql);
        $row = $result->fetch_row();

        return (int)($row[0] ?? 0);
    }

    // --------------------------------------------------
    // Totals
    // --------------------------------------------------

    $photoViews = getSingleValue(
        $mysqli,
        "
        SELECT COUNT(*)
        FROM ioa_events
        WHERE event_type = 'photo_view'
        {$whereAdmin}
        {$whereDate}
        "
    );

    $result = $mysqli->query("
        SELECT
            SUM(event_type = 'photo_view') AS photo_views,
            SUM(event_type = 'basket_add') AS basket_adds,
            SUM(event_type = 'photo_download') AS single_downloads
        FROM ioa_events
        WHERE session_id IS NOT NULL
          AND session_id <> ''
          {$whereAdmin}
          {$whereDate}
    ");

    $visitSummaryRow = $result->fetch_assoc();
    $visitSummary = [
        'visits' => 0, // Filled from qualifying activity episodes below.
        'photo_views' => (int)($visitSummaryRow['photo_views'] ?? 0),
        'basket_adds' => (int)($visitSummaryRow['basket_adds'] ?? 0),
        'downloads' => (int)($visitSummaryRow['single_downloads'] ?? 0)
    ];

    $basketAdds = getSingleValue(
        $mysqli,
        "
        SELECT COUNT(*)
        FROM ioa_events
        WHERE event_type = 'basket_add'
        {$whereAdmin}
        {$whereDate}
        "
    );

    $singleDownloads = getSingleValue(
        $mysqli,
        "
        SELECT COUNT(*)
        FROM ioa_events
        WHERE event_type = 'photo_download'
        {$whereAdmin}
        {$whereDate}
        "
    );

    // --------------------------------------------------
    // Batch downloads
    // --------------------------------------------------

    $batchResult = $mysqli->query("
        SELECT session_id, batch_data
        FROM ioa_events
        WHERE event_type = 'batch_download'
          AND batch_data IS NOT NULL
          {$whereAdmin}
          {$whereDate}
    ");

    $batchDownloads = 0;
    $visitBatchDownloads = 0;

    while ($row = $batchResult->fetch_assoc()) {

        $batch = json_decode($row['batch_data'], true);

        if (
            is_array($batch) &&
            isset($batch['photo_ids']) &&
            is_array($batch['photo_ids'])
        ) {
            $batchPhotoCount = count($batch['photo_ids']);
            $batchDownloads += $batchPhotoCount;

            if (is_string($row['session_id']) && $row['session_id'] !== '') {
                $visitBatchDownloads += $batchPhotoCount;
            }
        }
    }

    $downloads = $singleDownloads + $batchDownloads;
    $visitSummary['downloads'] += $visitBatchDownloads;

    // --------------------------------------------------
    // Recent visits (activity episodes within existing session_id values)
    // --------------------------------------------------

    $candidateSessionIds = [];
    $qualifyingEventIds = [];

    $result = $mysqli->query("
        SELECT id, session_id
        FROM ioa_events
        WHERE session_id IS NOT NULL
          AND session_id <> ''
          {$whereAdmin}
          {$whereDate}
    ");

    while ($row = $result->fetch_assoc()) {
        $candidateSessionIds[(string)$row['session_id']] = true;
        $qualifyingEventIds[(string)$row['id']] = true;
    }

    $recentVisits = [];

    if ($candidateSessionIds) {
        $escapedSessionIds = array_map(
            static function ($sessionId) use ($mysqli) {
                return "'" . $mysqli->real_escape_string($sessionId) . "'";
            },
            array_keys($candidateSessionIds)
        );

        $result = $mysqli->query("
            SELECT
                session_id,
                event_type,
                photo_id,
                page_path,
                image_url,
                batch_data,
                created_at,
                id
            FROM ioa_events
            WHERE session_id IN (" . implode(', ', $escapedSessionIds) . ")
              {$whereAdmin}
            ORDER BY session_id, created_at ASC, id ASC
        ");

        $eventRows = (static function () use ($result) {
            while ($row = $result->fetch_assoc()) yield $row;
        })();
        foreach (ioaSegmentVisits($eventRows) as $episode) {
            $currentVisit = $episode;
            $currentVisit['events'] = [];
            $currentVisit += ['photo_views' => 0, 'basket_adds' => 0,
                'basket_removes' => 0, 'downloads' => 0, 'qualifies_for_period' => false];
            foreach ($episode['events'] as $row) {
                $event = [
                    'id' => (int)$row['id'],
                    'event_type' => $row['event_type'],
                    'photo_id' => $row['photo_id'],
                    'image_url' => $row['image_url'],
                    'image_urls' => [],
                    'page_path' => $row['page_path'],
                    'page_context' => readablePagePath($row['page_path'] ?? null),
                    'created_at' => $row['created_at'],
                    'formatted_created_at' => formatActivityTimestamp($row['created_at'] ?? null),
                    'photo_ids' => [],
                    'photo_count' => 0
                ];

                if ($row['event_type'] === 'batch_download' && $row['batch_data'] !== null) {
                    $batch = json_decode($row['batch_data'], true);

                    if (
                        is_array($batch) &&
                        isset($batch['photo_ids']) &&
                        is_array($batch['photo_ids'])
                    ) {
                        $photoIds = array_values($batch['photo_ids']);
                        $photoCount = count($photoIds);

                        $currentVisit['downloads'] += $photoCount;
                        $event['photo_ids'] = $photoIds;
                        $event['photo_count'] = $photoCount;
                        $event['image_urls'] = is_array($batch['image_urls'] ?? null)
                            ? array_values($batch['image_urls']) : [];
                    }
                } elseif ($row['event_type'] === 'photo_view') {
                    $currentVisit['photo_views']++;
                } elseif ($row['event_type'] === 'photo_download') {
                    $currentVisit['downloads']++;
                } elseif ($row['event_type'] === 'basket_add') {
                    $currentVisit['basket_adds']++;
                } elseif ($row['event_type'] === 'basket_remove') {
                    $currentVisit['basket_removes']++;
                }

                $currentVisit['qualifies_for_period'] =
                    $currentVisit['qualifies_for_period'] ||
                    isset($qualifyingEventIds[(string)$row['id']]);
                $currentVisit['events'][] = $event;
            }
            if ($currentVisit['qualifies_for_period']) {
                unset($currentVisit['qualifies_for_period']);
                $recentVisits[] = $currentVisit;
            }
        }
    }

    foreach ($recentVisits as &$visit) {
        $visit['basket_actions'] = $visit['basket_adds'] + $visit['basket_removes'];
        $visit['formatted_latest_activity'] = formatActivityTimestamp(
            $visit['latest_activity']
        );
        $visit['activity_span'] = count($visit['events']) > 1
            ? formatActivitySpan(
                $visit['first_activity'],
                $visit['latest_activity']
            )
            : null;
    }
    unset($visit);

    usort($recentVisits, static function ($a, $b) {
        if ($a['latest_activity'] !== $b['latest_activity']) {
            return strcmp($b['latest_activity'], $a['latest_activity']);
        }

        return $b['last_event_id'] <=> $a['last_event_id'];
    });

    $visitSummary['visits'] = count($recentVisits);

    $recentVisits = array_slice($recentVisits, 0, 20);

    $visitPhotoIds = [];
    $visitImages = [];

    // Shape highlights only for the final episodes; keep all journey events.
    foreach ($recentVisits as &$visit) {
        $photos = [];
        $visit['downloads'] = 0;
        foreach ($visit['events'] as $eventIndex => &$event) {
            $ids = $event['event_type'] === 'batch_download'
                ? $event['photo_ids'] : [$event['photo_id']];
            $seen = [];
            foreach ($ids as $index => $rawId) {
                $photoId = (int)$rawId;
                if ($photoId <= 0) continue;
                $candidate = $event['event_type'] === 'batch_download'
                    ? ($event['image_urls'][$index] ?? null) : $event['image_url'];
                $image = safeDashboardResourceUrl($candidate);
                if ($image !== null) $visitImages[$photoId] = $image;
                if (isset($seen[$photoId])) continue;
                $seen[$photoId] = true;
                $visitPhotoIds[$photoId] = $photoId;
                if (!isset($photos[$photoId])) {
                    $photos[$photoId] = [
                        'photo_id' => $photoId, 'views' => 0, 'downloads' => 0
                    ];
                }
                if ($event['event_type'] === 'photo_view') {
                    $photos[$photoId]['views']++;
                    $photos[$photoId]['latest_views'] = $eventIndex;
                }
                if (in_array($event['event_type'], ['photo_download', 'batch_download'], true)) {
                    $photos[$photoId]['downloads']++;
                    $photos[$photoId]['latest_downloads'] = $eventIndex;
                }
            }
            if ($event['event_type'] === 'batch_download') {
                $event['selection_photo_ids'] = array_keys($seen);
                $event['photo_count'] = count($seen);
                $visit['downloads'] += count($seen);
            } elseif ($event['event_type'] === 'photo_download') {
                $visit['downloads']++;
            }
        }
        unset($event);
        $visit['highlights'] = [];
        $visit['hero_candidates'] = [];
        foreach (['views', 'downloads'] as $group) {
            $ranked = array_values(array_filter($photos, static function ($photo) use ($group) {
                return $photo[$group] > 0;
            }));
            // Events are ordered by timestamp and ID, so the index breaks recency ties.
            usort($ranked, static function ($a, $b) use ($group) {
                return ($b[$group] <=> $a[$group])
                    ?: ($b['latest_' . $group] <=> $a['latest_' . $group])
                    ?: ($a['photo_id'] <=> $b['photo_id']);
            });
            if ($group === 'views') $visit['viewed_photos'] = $ranked;
            if ($ranked) $visit['highlights'][$group] = array_slice($ranked, 0, 5);
            foreach ($ranked as $photo) {
                $visit['hero_candidates'][] = $photo['photo_id'];
            }
        }
        // Keep separate actions in recorded timestamp/ID order, including repeated photos.
        $basketActions = array_values(array_filter($visit['events'], static function ($event) {
            return in_array($event['event_type'], ['basket_add', 'basket_remove'], true);
        }));
        $visit['more_basket_actions'] = max(0, count($basketActions) - 5);
        if ($basketActions) {
            $visit['highlights']['basket'] = array_slice($basketActions, -5);
        }
        foreach (array_reverse($basketActions) as $event) {
            $visit['hero_candidates'][] = (int)$event['photo_id'];
        }
    }
    unset($visit);

    // Resolve after all existing Visit image data is collected, skipping missing URLs.
    foreach ($recentVisits as &$visit) {
        $visit['hero_image'] = null;
        foreach ($visit['hero_candidates'] as $photoId) {
            if (isset($visitImages[$photoId])) {
                $visit['hero_image'] = $visitImages[$photoId];
                break;
            }
        }
        unset($visit['hero_candidates']);
    }
    unset($visit);

    $latestVisitImage = $recentVisits[0]['hero_image'] ?? null;

    // --------------------------------------------------
    // Latest viewed photo
    // --------------------------------------------------

    $latestViewedPhoto = null;

    $result = $mysqli->query("
        SELECT
            photo_id,
            image_url,
            created_at
        FROM ioa_events
        WHERE event_type = 'photo_view'
          {$whereAdmin}
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");

    $latestViewedPhoto = $result->fetch_assoc();

    if (
        $latestViewedPhoto &&
        empty($latestViewedPhoto['image_url']) &&
        !empty($latestViewedPhoto['photo_id'])
    ) {
        $photoId = (int)$latestViewedPhoto['photo_id'];

        $result = $mysqli->query("
            SELECT image_url
            FROM ioa_events
            WHERE photo_id = {$photoId}
              AND image_url IS NOT NULL
              AND image_url <> ''
              {$whereAdmin}
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");

        $imageRow = $result->fetch_assoc();

        if ($imageRow) {
            $latestViewedPhoto['image_url'] = $imageRow['image_url'];
        }
    }

    // --------------------------------------------------
    // Build per-photo stats
    // --------------------------------------------------

    $photoStats = [];

    // Views
    $result = $mysqli->query("
        SELECT
            photo_id,
            COUNT(*) AS views,
            MAX(image_url) AS image_url
        FROM ioa_events
        WHERE event_type = 'photo_view'
          AND photo_id IS NOT NULL
          {$whereAdmin}
          {$whereDate}
        GROUP BY photo_id
    ");

    while ($row = $result->fetch_assoc()) {

        $photoId = (string)$row['photo_id'];

        $photoStats[$photoId] = [
            'photo_id' => $photoId,
            'views' => (int)$row['views'],
            'basket' => 0,
            'downloads' => 0,
            'image_url' => $row['image_url']
        ];
    }

    // Basket adds
    $result = $mysqli->query("
        SELECT
            photo_id,
            COUNT(*) AS basket_count
        FROM ioa_events
        WHERE event_type = 'basket_add'
          AND photo_id IS NOT NULL
          {$whereAdmin}
          {$whereDate}
        GROUP BY photo_id
    ");

    while ($row = $result->fetch_assoc()) {

        $photoId = (string)$row['photo_id'];

        if (!isset($photoStats[$photoId])) {
            $photoStats[$photoId] = [
                'photo_id' => $photoId,
                'views' => 0,
                'basket' => 0,
                'downloads' => 0,
                'image_url' => null
            ];
        }

        $photoStats[$photoId]['basket'] =
            (int)$row['basket_count'];
    }

    // Single downloads
    $result = $mysqli->query("
        SELECT
            photo_id,
            COUNT(*) AS download_count
        FROM ioa_events
        WHERE event_type = 'photo_download'
          AND photo_id IS NOT NULL
          {$whereAdmin}
          {$whereDate}
        GROUP BY photo_id
    ");

    while ($row = $result->fetch_assoc()) {

        $photoId = (string)$row['photo_id'];

        if (!isset($photoStats[$photoId])) {
            $photoStats[$photoId] = [
                'photo_id' => $photoId,
                'views' => 0,
                'basket' => 0,
                'downloads' => 0,
                'image_url' => null
            ];
        }

        $photoStats[$photoId]['downloads'] +=
            (int)$row['download_count'];
    }

    // Batch downloads per photo
    $result = $mysqli->query("
        SELECT batch_data
        FROM ioa_events
        WHERE event_type = 'batch_download'
          AND batch_data IS NOT NULL
          {$whereAdmin}
          {$whereDate}
    ");

    while ($row = $result->fetch_assoc()) {

        $batch = json_decode($row['batch_data'], true);

        if (
            !is_array($batch) ||
            empty($batch['photo_ids']) ||
            !is_array($batch['photo_ids'])
        ) {
            continue;
        }

        foreach ($batch['photo_ids'] as $index => $photoId) {

            $photoId = (string)$photoId;
            $imageUrl = null;

            if (
                isset($batch['image_urls']) &&
                is_array($batch['image_urls']) &&
                array_key_exists($index, $batch['image_urls']) &&
                is_string($batch['image_urls'][$index]) &&
                $batch['image_urls'][$index] !== ''
            ) {
                $imageUrl = $batch['image_urls'][$index];
            }

            if (!isset($photoStats[$photoId])) {
                $photoStats[$photoId] = [
                    'photo_id' => $photoId,
                    'views' => 0,
                    'basket' => 0,
                    'downloads' => 0,
                    'image_url' => $imageUrl
                ];
            } elseif (empty($photoStats[$photoId]['image_url']) && $imageUrl !== null) {
                $photoStats[$photoId]['image_url'] = $imageUrl;
            }

            $photoStats[$photoId]['downloads']++;
        }
    }

    // Latest available image URL from any event type
    $result = $mysqli->query("
        SELECT
            e.photo_id,
            e.image_url
        FROM ioa_events AS e
        INNER JOIN (
            SELECT
                photo_id,
                MAX(id) AS event_id
            FROM ioa_events
            WHERE photo_id IS NOT NULL
              AND image_url IS NOT NULL
              AND image_url <> ''
              {$whereAdmin}
              {$whereDate}
            GROUP BY photo_id
        ) AS latest_image
            ON latest_image.event_id = e.id
    ");

    while ($row = $result->fetch_assoc()) {

        $photoId = (string)$row['photo_id'];

        if (isset($photoStats[$photoId])) {
            $photoStats[$photoId]['image_url'] = $row['image_url'];
        }
    }

    // Build fixed rankings for tab previews and the downloads tab.
    $photosByViews = array_values(array_filter(
        $photoStats,
        static function ($photo) {
            return $photo['views'] > 0;
        }
    ));
    usort($photosByViews, function ($a, $b) {
        if ($a['views'] !== $b['views']) {
            return $b['views'] <=> $a['views'];
        }

        return (int)$a['photo_id'] <=> (int)$b['photo_id'];
    });

    $photosByDownloads = array_values(array_filter(
        $photoStats,
        static function ($photo) {
            return $photo['downloads'] > 0;
        }
    ));
    usort($photosByDownloads, function ($a, $b) {
        if ($a['downloads'] !== $b['downloads']) {
            return $b['downloads'] <=> $a['downloads'];
        }

        return (int)$a['photo_id'] <=> (int)$b['photo_id'];
    });

    $mostViewedPhoto = $photosByViews[0] ?? null;
    $mostDownloadedPhoto = $photosByDownloads[0] ?? null;
    $topDownloadedPhotos = array_slice($photosByDownloads, 0, 20);

    // Most viewed is always ranked by views descending.
    usort($photoStats, function ($a, $b) {

        if ($a['views'] !== $b['views']) {
            return $b['views'] <=> $a['views'];
        }

        return (int)$a['photo_id'] <=> (int)$b['photo_id'];
    });

    $topPhotos = array_slice($photoStats, 0, 20);

    // Current IO200 titles for rankings, the live hero card, and visit events.
    $titlePhotoIds = array_values(array_unique(array_filter(array_map(
        'intval',
        array_merge(
            array_column($topPhotos, 'photo_id'),
            array_column($topDownloadedPhotos, 'photo_id'),
            [$latestViewedPhoto['photo_id'] ?? null],
            $visitPhotoIds
        )
    ), static function ($photoId) {
        return $photoId > 0;
    })));
    $photoTitles = [];

    if ($titlePhotoIds) {
        $placeholders = implode(',', array_fill(0, count($titlePhotoIds), '?'));
        $stmt = $mysqli->prepare("
            SELECT id, title
            FROM cms_photos
            WHERE id IN ({$placeholders})
        ");
        $stmt->bind_param(str_repeat('i', count($titlePhotoIds)), ...$titlePhotoIds);
        $stmt->execute();
        $stmt->bind_result($titlePhotoId, $titleValue);

        while ($stmt->fetch()) {
            $title = is_string($titleValue)
                ? trim($titleValue)
                : '';

            if ($title !== '') {
                $photoTitles[(string)$titlePhotoId] = $title;
            }
        }

        $stmt->close();
    }

    foreach ($topPhotos as &$photo) {
        $photo['title'] = $photoTitles[$photo['photo_id']] ?? null;
    }
    unset($photo);

    foreach ($topDownloadedPhotos as &$photo) {
        $photo['title'] = $photoTitles[$photo['photo_id']] ?? null;
    }
    unset($photo);

    foreach ($recentVisits as &$visit) {
        foreach ($visit['events'] as &$event) {
            $photoId = $event['photo_id'] !== null
                ? (string)$event['photo_id']
                : null;
            $event['title'] = $photoId !== null
                ? ($photoTitles[$photoId] ?? null)
                : null;
        }
        unset($event);
    }
    unset($visit);

    // Resolve direct Album-template links to their current IO200 album titles.
    $directAlbumRoutes = [];

    if ($recentVisits) {
        $result = $mysqli->query("
            SELECT
                links.path,
                collections.id AS album_id,
                collections.title AS album_title
            FROM cms_links AS links
            INNER JOIN cms_collections AS collections
                ON collections.id = links.reference_id
            WHERE links.template = 'album'
              AND links.reference_type = 'album'
              AND links.path IS NOT NULL
              AND links.path <> ''
              AND collections.type = 0
        ");

        while ($row = $result->fetch_assoc()) {
            $routePath = normalizedDashboardPath($row['path']);

            if ($routePath === null || rtrim($routePath, '/') === '') {
                continue;
            }

            $directAlbumRoutes[] = [
                'path' => rtrim($routePath, '/'),
                'album_id' => (int)$row['album_id'],
                'title' => is_string($row['album_title'])
                    ? trim($row['album_title'])
                    : ''
            ];
        }

        usort($directAlbumRoutes, static function ($a, $b) {
            return strlen($b['path']) <=> strlen($a['path']);
        });
    }

    foreach ($recentVisits as &$visit) {
        $contextKeys = [];
        $viewedPhotoIds = [];
        $visit['context_sections'] = [];

        foreach ($visit['events'] as $event) {
            $eventPath = normalizedDashboardPath($event['page_path'] ?? null);
            $contextKey = $event['page_context'] !== null
                ? 'path:' . ($eventPath ?? $event['page_context'])
                : 'none';
            $contextTitle = $event['page_context'];

            if ($eventPath !== null) {
                foreach ($directAlbumRoutes as $route) {
                    if (
                        $eventPath === $route['path'] ||
                        str_starts_with($eventPath, $route['path'] . '/')
                    ) {
                        $contextKey = 'album:' . $route['album_id'];
                        $contextTitle = $route['title'] !== ''
                            ? $route['title']
                            : $event['page_context'];
                        break;
                    }
                }
            }

            if ($contextTitle !== null) {
                $contextKeys[$contextKey] = true;
            }

            $sectionIndex = count($visit['context_sections']) - 1;

            if (
                $sectionIndex < 0 ||
                $visit['context_sections'][$sectionIndex]['key'] !== $contextKey
            ) {
                $visit['context_sections'][] = [
                    'key' => $contextKey,
                    'title' => $contextTitle,
                    'first_activity' => $event['created_at'],
                    'latest_activity' => $event['created_at'],
                    'items' => []
                ];
                $sectionIndex++;
                $activityIndexes = [];
            }

            $visit['context_sections'][$sectionIndex]['latest_activity'] = $event['created_at'];
            $items =& $visit['context_sections'][$sectionIndex]['items'];
            $type = $event['event_type'];

            // Keep header metrics independent of the activity presentation.
            if ($type === 'photo_view' && $event['photo_id'] !== null) {
                $viewedPhotoIds[(string)$event['photo_id']] = true;
            }

            if ($type === 'batch_download') {
                $items[] = [
                    'type' => 'selection_download',
                    'photo_ids' => $event['selection_photo_ids'] ?? [],
                    'actions' => 1
                ];
                // Do not move later activity ahead of this Selection download.
                $activityIndexes = [];
            } elseif (in_array($type, ['photo_view', 'photo_download', 'basket_add', 'basket_remove'], true)) {
                if (!isset($activityIndexes[$type])) {
                    $activityIndexes[$type] = count($items);
                    $items[] = ['type' => $type, 'actions' => 0, 'photo_ids' => []];
                }
                $index = $activityIndexes[$type];
                $items[$index]['actions']++;
                $photoId = (int)$event['photo_id'];
                if ($photoId > 0) {
                    $items[$index]['photo_ids'][$photoId] = $photoId;
                }
            } else {
                $items[] = ['type' => 'recorded_activity', 'actions' => 1, 'photo_ids' => []];
                $activityIndexes = [];
            }
            unset($items);
        }

        $visit['unique_photos_viewed'] = count($viewedPhotoIds);
        $visit['context_count'] = count($contextKeys);
    }
    unset($visit);

    if ($latestViewedPhoto) {
        $latestPhotoId = $latestViewedPhoto['photo_id'] !== null
            ? (string)$latestViewedPhoto['photo_id']
            : null;
        $latestViewedPhoto['title'] = $latestPhotoId !== null
            ? ($photoTitles[$latestPhotoId] ?? null)
            : null;
        $latestViewedPhoto['formatted_created_at'] = formatActivityTimestamp(
            $latestViewedPhoto['created_at'] ?? null
        );
        $latestViewedPhoto['image_url'] = safeDashboardResourceUrl(
            $latestViewedPhoto['image_url'] ?? null
        );
    }

    if ($mostViewedPhoto) {
        $mostViewedPhoto['image_url'] = safeDashboardResourceUrl(
            $mostViewedPhoto['image_url'] ?? null
        );
    }

    if ($mostDownloadedPhoto) {
        $mostDownloadedPhoto['image_url'] = safeDashboardResourceUrl(
            $mostDownloadedPhoto['image_url'] ?? null
        );
    }

    foreach ($topPhotos as &$photo) {
        $photo['image_url'] = safeDashboardResourceUrl($photo['image_url'] ?? null);
    }
    unset($photo);

    foreach ($topDownloadedPhotos as &$photo) {
        $photo['image_url'] = safeDashboardResourceUrl($photo['image_url'] ?? null);
    }
    unset($photo);

    $mysqli->close();

} catch (Throwable $e) {

    error_log(
        '[IO200 Analytics] Dashboard error: ' .
        $e->getMessage()
    );

    http_response_code(500);

    die(ioa_t('app_name') . ': ' . ioa_t('dashboard_load_error'));
}

?>
<!doctype html>
<html lang="<?= ioa_language_code() ?>">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= ioa_t('app_name') ?></title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 38px 20px;

            background: #f4f5f7;
            color: #202124;

            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Roboto,
                Arial,
                sans-serif;
        }

        .dashboard {
            max-width: 1180px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;

            margin-bottom: 28px;
        }

        h1 {
            margin: 0 0 7px;

            font-size: 34px;
        }

        .subtitle {
            margin: 0;

            color: #6e7177;
        }

        .update-notice {
            padding: 5px 10px;
            border: 1px solid #dedfe2;
            border-radius: 999px;
            background: #f8f9fa;
            color: #6e7177;
            font-size: 12px;
            line-height: 1.4;
            text-decoration: none;
        }

        .update-notice--desktop {
            flex-shrink: 0;
            align-self: flex-end;
            padding: 8px 12px;
            white-space: nowrap;
        }

        .update-notice--mobile {
            display: none;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .filter {
            padding: 8px 12px;

            border-radius: 8px;

            background: white;
            color: #555;

            text-decoration: none;

            border: 1px solid #dedfe2;

            font-size: 14px;
        }

        .filter.active {
            background: #202124;
            color: white;

            border-color: #202124;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: stretch;

            gap: 10px;

            margin-bottom: 16px;
        }

        .kpi-grid {
            display: grid;
            grid-column: span 2;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow:
                0 2px 8px rgba(0, 0, 0, .06);
        }

        .kpi-card {
            padding: 22px;
        }

        .kpi-card__value {
            display: block;

            margin-bottom: 5px;

            font-size: 32px;
            font-weight: 750;
        }

        .kpi-card__label {
            color: #73767b;

            font-size: 14px;
        }

        .hero-card {
            display: grid;
            grid-template-rows: auto auto auto;
            align-content: start;
            gap: 14px;

            min-width: 0;
            padding: 22px;
        }

        .hero-card__title {
            margin: 0;

            font-size: 18px;
        }

        .hero-card__media {
            display: block;
            overflow: hidden;

            width: 100%;
            aspect-ratio: 3 / 2;

            background: #f0f1f2;
            border-radius: 8px;
        }

        .hero-card__image {
            display: block;

            width: 100%;
            height: 100%;

            object-fit: cover;
            object-position: center;
        }

        .hero-card__content {
            display: grid;
            gap: 4px;

            min-width: 0;
        }

        .hero-card__primary {
            overflow: hidden;

            font-size: 18px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .hero-card__meta {
            color: #73767b;

            font-size: 13px;
        }

        .panel {
            padding: 24px;
        }

        .photo-tabs {
            margin-bottom: 30px;
        }

        .photo-tabs__list {
            position: relative;
            z-index: 1;

            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: stretch;
            gap: 10px;
        }

        .photo-tab {
            display: grid;
            align-content: start;
            gap: 12px;

            min-width: 0;
            padding: 16px;

            border: 1px solid transparent;
            border-radius: 12px 12px 0 0;

            background: #fafafa;
            color: inherit;

            text-decoration: none;
        }

        .photo-tab:hover {
            background: white;
        }

        .photo-tab[aria-selected="true"] {
            margin-bottom: -1px;

            background: white;

            border-color: #e4e5e7;
            border-bottom-color: white;

            box-shadow: 0 -2px 8px rgba(0, 0, 0, .04);
        }

        .photo-tab:focus-visible {
            outline: 2px solid #4b76d1;
            outline-offset: 2px;
        }

        .photo-tab__title {
            margin: 0;

            font-size: 18px;
        }

        .photo-tab__mobile-label {
            display: none;
        }

        .photo-tabs__content {
            border: 1px solid #e4e5e7;
            border-radius: 0 0 12px 12px;

            box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
        }

        .photo-tabs__panel[hidden] {
            display: none;
        }

        .photo-item {
            display: flex;
            align-items: center;
            gap: 13px;

            min-width: 0;
        }

        .photo-item--featured {
            gap: 16px;
        }

        .photo-item--compact {
            gap: 10px;

            min-height: 46px;
        }

        .photo-item__body {
            display: grid;
            gap: 3px;

            min-width: 0;
        }

        .photo-item__primary {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 4px 10px;
        }

        .photo-item__id {
            font-weight: 700;
        }

        .photo-item__meta {
            color: #85888d;

            font-size: 12px;
            line-height: 1.35;
        }

        .photo-item__meta--truncate {
            overflow: hidden;

            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .photo-item__thumbnail {
            display: block;

            width: 80px;
            height: 56px;

            object-fit: cover;

            background: #eee;

            border-radius: 6px;
        }

        .photo-item__thumbnail--featured {
            width: 112px;
            height: 78px;

            border-radius: 8px;
        }

        .photo-item__thumbnail--compact {
            width: 78px;
            height: 55px;

            border-radius: 5px;
        }

        .photo-list {
            display: grid;
            gap: 0;
        }

        .photo-list .photo-item {
            padding: 8px 0;
        }

        .photo-list .photo-item + .photo-item {
            border-top: 1px solid #f0f0f1;
        }

        .visit-preview {
            display: grid;
            gap: 5px;
        }

        .visit-list {
            display: grid;
        }

        .visit-highlights {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
            gap: 18px;
            padding: 0 10px;
        }
        .visit-highlights:empty { display: none; }
        .visit-highlight-group { min-width: 0; }
        .visit-highlight-group h3 { margin: 4px 0 12px; font-size: 13px; color: #4f5358; }
        .visit-highlight-photo { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .visit-highlight-image { width: 56px; height: 56px; flex: 0 0 56px; object-fit: cover; border-radius: 5px; background: #f0f1f2; }
        .visit-image-placeholder { display: grid; place-items: center; color: #85888d; }
        .visit-highlight-caption { min-width: 0; }
        .visit-highlight-title { font-size: 13px; font-weight: 600; overflow-wrap: anywhere; }
        .visit-journey { margin: 4px 10px 16px; }
        .visit-journey > summary { padding: 8px 0; cursor: pointer; font-size: 13px; color: #4f5358; }
        .visit-activity-summary .visit-context-section__title { padding: 6px 0 4px; }
        .visit-activity-summary__item { margin: 12px 0; min-width: 0; }
        .visit-activity-summary .selection-preview { overflow-wrap: anywhere; }
        .selection-preview { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px; }
        @media (max-width: 600px) { .visit-highlights { grid-template-columns: 1fr; } }

        .visit-item {
            border-bottom: 1px solid #f0f0f1;
        }

        .visit-item:last-child {
            border-bottom: 0;
        }

        .visit-summary {
            position: relative;

            display: grid;
            grid-template-columns: 80px minmax(0, 1fr);
            align-items: center;
            gap: 13px;

            padding: 17px 32px 17px 10px;

            list-style: none;
        }

        details.visit-item > .visit-summary {
            padding-right: 80px;
            cursor: pointer;
        }

        .visit-summary::-webkit-details-marker {
            display: none;
        }

        details.visit-item > .visit-summary::after {
            position: absolute;
            right: 10px;
            top: 18px;

            content: attr(data-disclosure-label) ' ▾';
            color: #777a80;
            font-size: 12px;
            font-weight: 400;
        }

        details.visit-item[open] > .visit-summary::after {
            content: attr(data-disclosure-label) ' ▴';
        }

        .visit-summary:focus-visible {
            outline: 2px solid currentColor;
            outline-offset: -2px;
            border-radius: 6px;
        }

        .visit-summary__content {
            display: grid;
            gap: 5px;
            min-width: 0;
        }

        .visit-summary__identity {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px 16px;
            min-width: 0;
        }

        .visit-summary__time,
        .visit-summary__id {
            font-size: 13px;
            font-weight: 400;
            line-height: 1.4;
            color: #73777d;
        }

        .visit-summary__id {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: auto;
            white-space: nowrap;
        }

        .visit-summary__controls {
            display: contents;
        }

        .visit-summary__disclosure {
            display: none;
        }

        @media (min-width: 851px) {
            .visit-summary,
            details.visit-item > .visit-summary {
                padding-right: 10px;
            }

            details.visit-item > .visit-summary::after {
                display: none;
            }

            .visit-summary__content {
                grid-template-columns: minmax(0, 1fr) 74px;
                column-gap: 16px;
            }

            .visit-summary__metrics {
                grid-column: 1;
                grid-row: 1;
            }

            .visit-summary__identity {
                grid-column: 1;
                grid-row: 2;
            }

            .visit-summary__controls .visit-summary__id {
                grid-column: 2;
                grid-row: 1;
                align-self: center;
                justify-self: end;
                margin-left: 0;
            }

            .visit-summary__disclosure {
                grid-column: 2;
                grid-row: 2;
                align-self: center;
                justify-self: end;
                display: inline;
                color: #777a80;
                font-size: 12px;
                font-weight: 400;
            }

            .visit-summary__disclosure::after {
                content: ' ▾';
            }

            .visit-item[open] .visit-summary__disclosure::after {
                content: ' ▴';
            }
        }

        .visit-summary__id svg {
            width: 13px;
            height: 13px;
            flex: 0 0 13px;
        }

        .visit-summary__metrics {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 24px;
            min-width: 0;
        }

        .visit-summary__metric {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            color: #41464c;
        }

        .visit-summary__metric svg {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            color: #858b92;
        }

        .visit-summary__value {
            font-size: 26px;
            font-weight: 750;
            font-variant-numeric: tabular-nums;
            line-height: 1.3;
        }

        .visit-summary__metric--span .visit-summary__value {
            font-size: 13px;
            font-weight: 400;
            line-height: 1.4;
            color: #73777d;
        }

        .visit-summary__accessible {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
            border: 0;
        }

        .visit-timeline {
            display: grid;

            margin: 0 10px 16px;
            padding: 6px 14px;

            background: #f7f8f9;
            border-radius: 8px;
        }

        .visit-context-section {
            padding: 8px 0;
        }

        .visit-context-section + .visit-context-section {
            border-top: 1px solid #dfe1e4;
        }

        .visit-context-section__title {
            margin: 0;
            padding: 6px 0 4px 128px;

            color: #4f5358;

            font-size: 13px;
            font-weight: 700;
        }

        .visit-timeline__event {
            display: grid;
            grid-template-columns: 112px minmax(0, 1fr);
            gap: 16px;

            padding: 12px 0;
        }

        .visit-timeline__event + .visit-timeline__event {
            border-top: 1px solid #e5e7e9;
        }

        .visit-timeline__time,
        .visit-timeline__meta,
        .visit-activity-metrics {
            color: #74777c;

            font-size: 12px;
            line-height: 1.4;
        }

        .visit-timeline__content {
            min-width: 0;
        }

        .visit-timeline__action {
            font-size: 14px;
            font-weight: 650;
            overflow-wrap: anywhere;
        }

        .visit-activity-metrics {
            display: flex;
            flex-wrap: wrap;
            gap: 3px 14px;

            margin-top: 4px;
        }

        .selection-download {
            margin-top: 6px;

            font-size: 12px;
        }

        .selection-download summary {
            color: #5f6368;
            cursor: pointer;
        }

        .selection-download__ids {
            margin: 6px 0 0;

            color: #5f6368;
            overflow-wrap: anywhere;
        }

        .thumbnail-link {
            display: block;

            flex: 0 0 auto;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;

            gap: 15px;

            margin-bottom: 18px;
        }

        .panel-header h2 {
            margin: 0;
        }

        .panel-hint {
            color: #8a8d92;

            font-size: 13px;
        }

        table {
            width: 100%;

            border-collapse: collapse;
        }

        td {
            padding: 13px 10px;

            border-bottom: 1px solid #eeeeef;

            text-align: left;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .ranking-table { table-layout: fixed; }

        .ranking-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #41464c;
            font-size: 24px;
            font-weight: 700;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .ranking-item .photo-item__id,
        .visit-highlight-caption--ranking .visit-highlight-title {
            overflow: hidden;
            color: #73777d;
            font-size: 13px;
            font-weight: 400;
            line-height: 1.35;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .visit-highlight-caption--ranking {
            display: grid;
            gap: 3px;
        }

        .visit-highlight-basket-action {
            font-size: 15px;
            font-weight: 600;
        }

        .ranking-primary svg {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            color: #858b92;
        }

        .ranking-primary__accessible {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
        }

        .ranking-item {
            border-bottom: 1px solid #e5e7e9;
        }

        .ranking-item__main td {
            padding-top: 11px;
            padding-bottom: 6px;
            border-bottom: 0;
        }

        .empty {
            padding: 30px 10px;

            color: #777;
            text-align: center;
        }

        @media (max-width: 850px) {

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .kpi-grid {
                grid-column: auto;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .update-notice--desktop {
                display: none;
            }

            .update-notice--mobile {
                display: block;
                width: 100%;
                margin-bottom: 16px;
                border-radius: 6px;
                text-align: center;
            }

            .photo-tabs__list {
                gap: 6px;
            }

            .photo-tab,
            .photo-tab:hover {
                display: grid;
                grid-template-rows: 42px auto;
                align-items: center;
                justify-items: center;
                gap: 6px;
                padding: 8px 4px;
                border: 1px solid transparent;
                border-radius: 6px 6px 0 0;
                background: transparent;
                box-shadow: none;
                color: #73777d;
            }

            .photo-tab > :not(.photo-tab__mobile-label):not(.photo-item--featured):not(.visit-preview),
            .photo-tab .photo-item__body,
            .photo-tab .photo-item__meta,
            .photo-tab .photo-item__id {
                display: none;
            }

            .photo-tab > .photo-item--featured,
            .photo-tab > .visit-preview {
                grid-row: 1;
                min-width: 0;
            }

            .photo-tab .photo-item__thumbnail {
                width: 60px;
                height: 42px;
                border-radius: 6px;
            }

            .photo-tab__mobile-label {
                display: block;
                grid-row: 2;
                font-size: 13px;
                font-weight: 400;
                white-space: nowrap;
            }

            .photo-tab[aria-selected="true"] {
                margin-bottom: -1px;
                border-color: #e4e5e7;
                border-bottom-color: white;
                background: white;
                box-shadow: none;
                color: #41464c;
            }

            .photo-tab[aria-selected="true"] .photo-tab__mobile-label {
                font-weight: 700;
            }

            .photo-tabs__content {
                margin-top: 0;
                border-radius: 0 0 12px 12px;
            }

            .visit-summary__metrics {
                gap: 10px 18px;
            }

            .visit-summary {
                min-height: 110px;
            }

            .visit-summary > .photo-item__thumbnail {
                align-self: start;
            }

            .visit-summary__id {
                position: absolute;
                top: 77px; /* Header padding + thumbnail height + 4px caption gap. */
                left: 10px;
                width: 80px;
                justify-content: center;
                margin-left: 0;
                font-size: 11px;
                color: #858b92;
            }

            .visit-summary__id svg {
                width: 12px;
                height: 12px;
                flex-basis: 12px;
            }

        }

        @media (max-width: 650px) {

            details.visit-item > .visit-summary {
                padding-right: 32px;
            }

            details.visit-item > .visit-summary::after {
                content: '▾';
                font-size: 16px;
            }

            details.visit-item[open] > .visit-summary::after {
                content: '▴';
            }

            body {
                padding: 24px 12px;
            }

            .visit-timeline__event {
                grid-template-columns: 1fr;
                gap: 4px;
            }

            .visit-context-section__title {
                padding-left: 0;
            }


        }

        .dashboard-footer {
            padding: 22px 0 4px;
            color: #74777c;
            font-size: 13px;
            text-align: center;
        }

        .dashboard-footer a {
            color: inherit;
        }

        .photo-search { grid-column: span 2; padding: 24px; min-width: 0; }
        .photo-search label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; }
        .photo-search p { color: #74777c; font-size: 13px; }
        .photo-search-field { position: relative; }
        .photo-search input { box-sizing: border-box; width: 100%; min-width: 0; padding: 14px; border: 1px solid #c9cbd0; border-radius: 8px; font: inherit; font-size: 16px; }
        .photo-search input:focus-visible { outline: 2px solid #555; outline-offset: 2px; }
        .photo-search ul { position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin: 6px 0 0; padding: 4px; list-style: none; background: white; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 8px 24px #0002; max-height: min(360px, 55vh); overflow-y: auto; }
        .photo-search li { display: flex; align-items: center; gap: 10px; padding: 8px; cursor: pointer; border-radius: 5px; }
        .photo-search li:hover, .photo-search li[aria-selected="true"] { background: #edf0f3; }
        .photo-search li > span:last-child { min-width: 0; }
        .photo-search strong { display: block; overflow-wrap: anywhere; font-size: 14px; }
        .photo-search small { display: block; color: #74777c; margin-top: 3px; }
        .photo-search-preview { position: relative; display: grid; place-items: center; flex: 0 0 44px; height: 44px; background: #f2f2f2; border-radius: 4px; overflow: hidden; }
        .photo-search-preview img { position: absolute; width: 100%; height: 100%; object-fit: cover; }
        .photo-search-status:empty { margin: 0; }
        .photo-inspector--inline { padding-top: 16px; }
        .photo-inspector strong { display: block; overflow-wrap: anywhere; font-size: 14px; }
        .photo-inspector small { display: block; color: #74777c; margin-top: 3px; }
        .photo-inspector-preview { position: relative; display: grid; place-items: center; flex: 0 0 72px; height: 72px; background: #f2f2f2; border-radius: 4px; overflow: hidden; }
        .photo-inspector-preview img { position: absolute; width: 100%; height: 100%; object-fit: cover; }
        .photo-inspector-identity { display: flex; align-items: center; gap: 12px; }
        .photo-inspector-identity > div { min-width: 0; flex: 1; }
        .photo-inspector button { border: 0; background: transparent; color: #666; cursor: pointer; padding: 8px; font: inherit; font-size: 13px; }
        .photo-inspector dl { display: flex; flex-wrap: wrap; gap: 12px 24px; margin: 16px 0; }
        .photo-inspector dt { font-size: 12px; color: #74777c; }
        .photo-inspector dd { margin: 4px 0 0; font-size: 14px; }
        .photo-inspector h3 { font-size: 13px; margin: 16px 0 6px; }
        .photo-inspector p { margin: 6px 0; overflow-wrap: anywhere; color: #74777c; font-size: 13px; }
        .photo-inspector .photo-inspector-context { color: #333; font-size: 13px; }
        .photo-inspector-visit { padding-bottom: 16px; margin-bottom: 16px; border-bottom: 1px solid #e7e7e7; }
        .visit-photo-trigger { display: inline-flex; padding: 0; border: 0; border-radius: 4px; background: transparent; cursor: pointer; }
        .visit-photo-trigger:focus-visible { outline: 2px solid #555; outline-offset: 3px; }
        .visit-photo-gallery { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .visit-photo-gallery .visit-photo-trigger { width: 76px; height: 76px; max-width: 100%; display: grid; place-items: center; overflow: hidden; background: #f0f1f2; color: #74777c; }
        .visit-photo-gallery img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .visit-gallery-more { flex: 0 0 76px; height: 76px; max-width: 100%; padding: 0; border: 1px solid #e6e7e9; border-radius: 4px; background: #fafafa; color: #666; font: inherit; font-size: 15px; cursor: pointer; }
        .visit-gallery-more:hover { text-decoration: underline; }
        .visit-gallery-more:focus-visible { outline: 2px solid #555; outline-offset: 3px; }
        .visit-photo-popover { position: fixed; z-index: 1000; box-sizing: border-box; width: 320px; padding: 0; border: 1px solid #e1e1e1; border-radius: 12px; background: white; color: #333; box-shadow: 0 6px 24px #0002; overflow: visible; }
        .visit-photo-popover-surface { position: relative; padding: 16px; border-radius: inherit; background: white; max-height: calc(var(--popover-max-height, 80vh) - 2px); box-sizing: border-box; overflow: auto; }
        .visit-photo-popover::before { content: ''; position: absolute; width: 10px; height: 10px; background: white; border: solid #e1e1e1; border-width: 0; transform: rotate(45deg); pointer-events: none; }
        .visit-photo-popover[data-placement="above"]::before { bottom: -6px; left: calc(var(--pointer-offset) - 5px); border-width: 0 1px 1px 0; }
        .visit-photo-popover[data-placement="below"]::before { top: -6px; left: calc(var(--pointer-offset) - 5px); border-width: 1px 0 0 1px; }
        .visit-photo-popover[data-placement="left"]::before { right: -6px; top: calc(var(--pointer-offset) - 5px); border-width: 1px 1px 0 0; }
        .visit-photo-popover[data-placement="right"]::before { left: -6px; top: calc(var(--pointer-offset) - 5px); border-width: 0 0 1px 1px; }
        .visit-photo-popover:not([data-placement])::before { display: none; }
        .visit-popover-metrics { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 14px; margin: 10px 0; font-size: 13px; }
        .visit-popover-metrics svg { width: 16px; height: 16px; flex: 0 0 16px; }
        .visit-photo-popover .visit-popover-context { color: #444; margin-top: 10px; }
        .visit-photo-popover .visit-popover-time { font-variant-numeric: tabular-nums; }
        .visit-photo-popover:focus-visible { outline: 2px solid #777; outline-offset: 2px; }
        .visit-photo-popover .photo-inspector-identity { padding-right: 24px; }
        .visit-photo-popover .photo-inspector-preview { flex-basis: 44px; height: 44px; }
        .visit-photo-popover-close, .visit-photo-popover-full { border: 0; background: transparent; color: #555; cursor: pointer; font: inherit; padding: 6px; }
        .visit-photo-popover-close { position: absolute; right: 8px; top: 8px; font-size: 22px; line-height: 1; }
        .visit-photo-popover-full { display: block; margin-top: 12px; font-size: 13px; text-decoration: none; }
        .photo-inspector-modal { box-sizing: border-box; width: min(560px, calc(100% - 32px)); max-height: calc(100dvh - 32px); margin: auto; padding: 24px; border: 0; border-radius: 12px; color: #333; background: white; box-shadow: 0 16px 64px #0003; overflow-y: auto; }
        .photo-inspector-modal::backdrop { background: #0007; }
        .photo-inspector-modal header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 16px; }
        .photo-inspector-modal h2 { margin: 0; font-size: 18px; }
        .photo-inspector-modal-close { border: 0; background: transparent; color: #666; cursor: pointer; font: inherit; padding: 8px; }
        .photo-inspector-modal-close { font-size: 24px; line-height: 1; }
        .photo-inspector-modal-status { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
        @media (max-width: 850px) { .photo-search { grid-column: 1 / -1; padding: 18px; } }
    </style>

</head>

<body data-inspector-period="<?= h($allowedPeriods[$period]) ?>" data-inspector-today="<?= h((new DateTimeImmutable('today'))->format('Y-m-d')) ?>">

<div class="dashboard">

    <?php
    // Optional until the customer ZIP allowlist includes the notification files.
    $installedVersion = is_file(__DIR__ . '/version.php') ? (require __DIR__ . '/version.php') : null;
    $availableUpdate = null;
    if (is_string($installedVersion) && is_file(__DIR__ . '/update-check.php')) {
        require_once __DIR__ . '/update-check.php';
        $availableUpdate = ioaAvailableUpdate($installedVersion);
    }
    ?>
    <?php if ($availableUpdate !== null): ?>
        <a class="update-notice update-notice--mobile" href="<?= h($availableUpdate['download_url']) ?>"><?= ioa_t('update_available_mobile') ?></a>
    <?php endif; ?>

    <div class="topbar">

        <div>

            <h1><?= ioa_t('app_name') ?></h1>

            <p class="subtitle">
                <?= ioa_t('dashboard_subtitle') ?>
            </p>

        </div>

        <?php if ($availableUpdate !== null): ?>
            <a class="update-notice update-notice--desktop" href="<?= h($availableUpdate['download_url']) ?>"><?= ioa_t('update_available') ?></a>
        <?php endif; ?>

        <div class="filters">

            <?php foreach ($allowedPeriods as $value => $label): ?>

                <a
                    class="filter <?= $period === (string)$value ? 'active' : '' ?>"
                    href="?<?= h(http_build_query([
                        'period' => $value,
                        'include_admin' => $includeAdmin ? '1' : '0',
                        'photo_tab' => $photoTab
                    ])) ?>"
                >
                    <?= h($label) ?>
                </a>

            <?php endforeach; ?>

        </div>

    </div>

    <div class="summary-grid">

        <section class="dashboard-card photo-search" data-photo-search aria-labelledby="photo-search-label">
            <label id="photo-search-label" for="photo-search-input">Find photo</label>
            <div class="photo-search-field">
                <input id="photo-search-input" type="search" placeholder="Photo ID or current title" maxlength="200"
                    role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="photo-search-results"
                    autocomplete="off">
                <ul id="photo-search-results" role="listbox" aria-label="Matching photos" hidden></ul>
            </div>
            <p class="photo-search-status" role="status" aria-live="polite"></p>
            <div class="photo-inspector photo-inspector--inline" data-photo-inspector hidden></div>
        </section>

        <section class="dashboard-card hero-card" aria-labelledby="dashboard-hero-title">
            <h2 class="hero-card__title" id="dashboard-hero-title">
                <?= ioa_t('latest_image_viewed') ?>
            </h2>

            <div class="hero-card__media">
                <?php if ($latestViewedPhoto && !empty($latestViewedPhoto['image_url'])): ?>
                    <img
                        class="hero-card__image"
                        src="<?= h($latestViewedPhoto['image_url']) ?>"
                        alt=""
                        loading="lazy"
                    >
                <?php endif; ?>
            </div>

            <div class="hero-card__content">
                <?php if ($latestViewedPhoto): ?>
                    <?php if (!empty($latestViewedPhoto['title'])): ?>
                        <div class="hero-card__primary">
                            <?= h($latestViewedPhoto['title']) ?>
                        </div>
                        <div class="hero-card__meta">
                            <?= ioa_t('photo') ?> <?= $latestViewedPhoto['photo_id'] !== null
                                ? h($latestViewedPhoto['photo_id'])
                                : '&ndash;'
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="hero-card__primary">
                            <?= ioa_t('photo') ?> <?= $latestViewedPhoto['photo_id'] !== null
                                ? h($latestViewedPhoto['photo_id'])
                                : '&ndash;'
                            ?>
                        </div>
                    <?php endif; ?>

                    <time
                        class="hero-card__meta"
                        datetime="<?= h($latestViewedPhoto['created_at']) ?>"
                    >
                        <?= h($latestViewedPhoto['formatted_created_at']) ?>
                    </time>
                <?php else: ?>
                    <div class="hero-card__primary"><?= ioa_t('no_image_views_yet') ?></div>
                <?php endif; ?>
            </div>
        </section>

    </div>

    <section class="photo-tabs" data-photo-tabs>

        <div class="photo-tabs__list" role="tablist" aria-label="<?= ioa_t('photo_analytics') ?>">

            <a
                class="dashboard-card photo-tab"
                id="photo-tab-visits"
                href="?<?= h(http_build_query([
                    'period' => $period,
                    'include_admin' => $includeAdmin ? '1' : '0',
                    'photo_tab' => 'visits'
                ])) ?>"
                role="tab"
                aria-selected="<?= $photoTab === 'visits' ? 'true' : 'false' ?>"
                aria-controls="photo-panel-visits"
                tabindex="<?= $photoTab === 'visits' ? '0' : '-1' ?>"
                data-photo-tab="visits"
            >
                <span class="photo-tab__mobile-label"><?= ioa_t('metric_visits') ?></span>
                <h2 class="photo-tab__title"><?= ioa_t('metric_visits') ?></h2>

                <?php if ($visitSummary['visits'] > 0): ?>
                    <div class="photo-item photo-item--featured">
                        <?php if ($latestVisitImage !== null): ?>
                            <img class="photo-item__thumbnail photo-item__thumbnail--featured" src="<?= h($latestVisitImage) ?>" alt="" loading="lazy">
                        <?php endif; ?>
                        <div class="photo-item__body">
                            <div class="photo-item__id">
                                <?= h(formatCountLabel($visitSummary['visits'], 'one_visit', 'visits_count', '1 visit', '%d visits')) ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="photo-item__meta"><?= ioa_t('no_data_for_filter') ?></div>
                <?php endif; ?>
            </a>

            <a
                class="dashboard-card photo-tab"
                id="photo-tab-views"
                href="?<?= h(http_build_query([
                    'period' => $period,
                    'include_admin' => $includeAdmin ? '1' : '0',
                    'photo_tab' => 'views'
                ])) ?>"
                role="tab"
                aria-selected="<?= $photoTab === 'views' ? 'true' : 'false' ?>"
                aria-controls="photo-panel-views"
                tabindex="<?= $photoTab === 'views' ? '0' : '-1' ?>"
                data-photo-tab="views"
            >
                <span class="photo-tab__mobile-label"><?= ioa_t('tab_viewed_mobile') ?></span>
                <h2 class="photo-tab__title"><?= ioa_t('tab_most_viewed') ?></h2>

                <?php if ($mostViewedPhoto): ?>
                    <div class="photo-item photo-item--featured">
                        <?php if (!empty($mostViewedPhoto['image_url'])): ?>
                            <img
                                class="photo-item__thumbnail photo-item__thumbnail--featured"
                                src="<?= h($mostViewedPhoto['image_url']) ?>"
                                alt=""
                                loading="lazy"
                            >
                        <?php endif; ?>

                        <div class="photo-item__body">
                            <div class="photo-item__id">
                                <?= h(formatCountLabel($mostViewedPhoto['views'], 'one_view', 'views_count', '1 view', '%d views')) ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="photo-item__meta"><?= ioa_t('no_data_for_filter') ?></div>
                <?php endif; ?>
            </a>

            <a
                class="dashboard-card photo-tab"
                id="photo-tab-downloads"
                href="?<?= h(http_build_query([
                    'period' => $period,
                    'include_admin' => $includeAdmin ? '1' : '0',
                    'photo_tab' => 'downloads'
                ])) ?>"
                role="tab"
                aria-selected="<?= $photoTab === 'downloads' ? 'true' : 'false' ?>"
                aria-controls="photo-panel-downloads"
                tabindex="<?= $photoTab === 'downloads' ? '0' : '-1' ?>"
                data-photo-tab="downloads"
            >
                <span class="photo-tab__mobile-label"><?= ioa_t('tab_downloads_mobile') ?></span>
                <h2 class="photo-tab__title"><?= ioa_t('tab_most_downloaded') ?></h2>

                <?php if ($mostDownloadedPhoto): ?>
                    <div class="photo-item photo-item--featured">
                        <?php if (!empty($mostDownloadedPhoto['image_url'])): ?>
                            <img
                                class="photo-item__thumbnail photo-item__thumbnail--featured"
                                src="<?= h($mostDownloadedPhoto['image_url']) ?>"
                                alt=""
                                loading="lazy"
                            >
                        <?php endif; ?>

                        <div class="photo-item__body">
                            <div class="photo-item__id">
                                <?= h(formatCountLabel($mostDownloadedPhoto['downloads'], 'one_download', 'downloads_count', '1 download', '%d downloads')) ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="photo-item__meta"><?= ioa_t('no_data_for_filter') ?></div>
                <?php endif; ?>
            </a>

        </div>

        <div class="dashboard-card panel photo-tabs__content">

            <div
                class="photo-tabs__panel"
                id="photo-panel-views"
                role="tabpanel"
                aria-labelledby="photo-tab-views"
                tabindex="0"
                <?= $photoTab === 'views' ? '' : 'hidden' ?>
                data-photo-panel="views"
            >
                <div class="panel-header">
                    <h2><?= ioa_t('most_viewed_images') ?></h2>
                    <span class="panel-hint">
                        <?= ioa_t('top_20') ?> · <?= h($allowedPeriods[$period]) ?>
                    </span>
                </div>

                <?php if (count($topPhotos) === 0): ?>
                    <div class="empty">
                        <?= ioa_t('no_statistics_yet') ?>
                    </div>
                <?php else: ?>
                    <div class="ranking-list">
                        <table class="ranking-table">

                <?php foreach ($topPhotos as $photo): ?>


                    <tbody class="ranking-item">
                    <tr class="ranking-item__main">

                        <td>

                            <div class="photo-item">

                                <?php if (!empty($photo['image_url'])): ?>

                                    <a
                                        class="thumbnail-link"
                                        href="<?= h($photo['image_url']) ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >

                                        <img
                                            class="photo-item__thumbnail"
                                            src="<?= h($photo['image_url']) ?>"
                                            alt=""
                                            loading="lazy"
                                        >

                                    </a>

                                <?php endif; ?>

                                <div class="photo-item__body">

                                    <span class="ranking-primary" title="<?= h(formatCountLabel($photo['views'], 'one_view', 'views_count', '%d view', '%d views')) ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <span aria-hidden="true"><?= (int)$photo['views'] ?></span>
                                        <span class="ranking-primary__accessible"><?= h(formatCountLabel($photo['views'], 'one_view', 'views_count', '%d view', '%d views')) ?></span>
                                    </span>
                                    <?php if (!empty($photo['title'])): ?>
                                        <div class="photo-item__id photo-item__meta--truncate">
                                            <?= h($photo['title']) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="photo-item__id">
                                            <?= ioa_t('photo') ?> <?= h($photo['photo_id']) ?>
                                        </div>
                                    <?php endif; ?>

                                </div>

                            </div>

                        </td>



                    </tr>

                    </tbody>
                <?php endforeach; ?>

                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div
                class="photo-tabs__panel"
                id="photo-panel-downloads"
                role="tabpanel"
                aria-labelledby="photo-tab-downloads"
                tabindex="0"
                <?= $photoTab === 'downloads' ? '' : 'hidden' ?>
                data-photo-panel="downloads"
            >
                <div class="panel-header">
                    <h2><?= ioa_t('most_downloaded_images') ?></h2>
                    <span class="panel-hint">
                        <?= ioa_t('top_20') ?> · <?= h($allowedPeriods[$period]) ?>
                    </span>
                </div>

                <?php if (count($topDownloadedPhotos) === 0): ?>
                    <div class="empty">
                        <?= ioa_t('no_statistics_yet') ?>
                    </div>
                <?php else: ?>
                    <div class="ranking-list">
                        <table class="ranking-table">
                            <?php foreach ($topDownloadedPhotos as $photo): ?>
                                <tbody class="ranking-item">
                                <tr class="ranking-item__main">
                                    <td>
                                        <div class="photo-item">
                                            <?php if (!empty($photo['image_url'])): ?>
                                                <a
                                                    class="thumbnail-link"
                                                    href="<?= h($photo['image_url']) ?>"
                                                    target="_blank"
                                                    rel="noopener"
                                                >
                                                    <img
                                                        class="photo-item__thumbnail"
                                                        src="<?= h($photo['image_url']) ?>"
                                                        alt=""
                                                        loading="lazy"
                                                    >
                                                </a>
                                            <?php endif; ?>

                                            <div class="photo-item__body">
                                                <span class="ranking-primary" title="<?= h(formatCountLabel($photo['downloads'], 'one_download', 'downloads_count', '%d download', '%d downloads')) ?>">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3v12m-4-4 4 4 4-4M5 17v4h14v-4"/></svg>
                                                    <span aria-hidden="true"><?= (int)$photo['downloads'] ?></span>
                                                    <span class="ranking-primary__accessible"><?= h(formatCountLabel($photo['downloads'], 'one_download', 'downloads_count', '%d download', '%d downloads')) ?></span>
                                                </span>
                                                <?php if (!empty($photo['title'])): ?>
                                                    <div class="photo-item__id photo-item__meta--truncate">
                                                        <?= h($photo['title']) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="photo-item__id">
                                                        <?= ioa_t('photo') ?> <?= h($photo['photo_id']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                </tr>
                                </tbody>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div
                class="photo-tabs__panel"
                id="photo-panel-visits"
                role="tabpanel"
                aria-labelledby="photo-tab-visits"
                tabindex="0"
                <?= $photoTab === 'visits' ? '' : 'hidden' ?>
                data-photo-panel="visits"
            >
                <div class="panel-header">
                    <h2><?= ioa_t('latest_visits') ?></h2>
                    <span class="panel-hint">
                        <?= ioa_t('latest_20') ?> · <?= h($allowedPeriods[$period]) ?>
                    </span>
                </div>

                <?php if (count($recentVisits) === 0): ?>
                    <div class="empty">
                        <?= ioa_t('no_visits_for_filter') ?>
                    </div>
                <?php else: ?>
                    <div class="visit-list">
                        <?php foreach ($recentVisits as $visit): ?>
                            <?php
                            $isExpandable = count($visit['events']) > 1;
                            $visitTag = $isExpandable ? 'details' : 'div';
                            $headerTag = $isExpandable ? 'summary' : 'div';
                            ?>
                            <<?= $visitTag ?> class="visit-item">
                                <<?= $headerTag ?> class="visit-summary"<?= $isExpandable ? ' data-disclosure-label="' . h(ioa_translate('visit_details') === 'visit_details' ? 'Details' : ioa_translate('visit_details')) . '"' : '' ?>>
                                    <?php if ($visit['hero_image'] !== null): ?>
                                        <img class="photo-item__thumbnail" src="<?= h($visit['hero_image']) ?>" alt="" loading="lazy">
                                    <?php else: ?>
                                        <span class="photo-item__thumbnail" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <span class="visit-summary__content">
                                        <span class="visit-summary__metrics">
                                            <?php
                                            $headerMetrics = [
                                                [
                                                    'value' => $visit['unique_photos_viewed'],
                                                    'label' => formatCountLabel($visit['unique_photos_viewed'], 'one_photo_viewed', 'photos_viewed_count', '%d photo viewed', '%d photos viewed'),
                                                    'icon' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>'
                                                ],
                                                [
                                                    'value' => $visit['downloads'],
                                                    'label' => formatCountLabel($visit['downloads'], 'one_photo_downloaded', 'photos_downloaded_count', '%d photo downloaded', '%d photos downloaded'),
                                                    'icon' => '<path d="M12 3v12m-4-4 4 4 4-4M5 17v4h14v-4"/>'
                                                ],
                                                [
                                                    'value' => $visit['basket_actions'],
                                                    'label' => formatCountLabel($visit['basket_actions'], 'one_basket_action', 'basket_actions_count', '%d basket action', '%d basket actions'),
                                                    'icon' => '<path d="m8 3-4 6m12-6 4 6M2 9h20l-3 12H5L2 9Zm7 4v4m6-4v4"/>'
                                                ]
                                            ];
                                            ?>
                                            <?php foreach ($headerMetrics as $metric): ?>
                                                <?php if ($metric['value'] <= 0) continue; ?>
                                                <span class="visit-summary__metric" title="<?= h($metric['label']) ?>">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $metric['icon'] ?></svg>
                                                    <span class="visit-summary__value" aria-hidden="true"><?= (int)$metric['value'] ?></span>
                                                    <span class="visit-summary__accessible"><?= h($metric['label']) ?></span>
                                                </span>
                                            <?php endforeach; ?>
                                        </span>
                                        <span class="visit-summary__identity">
                                            <time class="visit-summary__time" datetime="<?= h($visit['latest_activity']) ?>">
                                                <?= h($visit['formatted_latest_activity']) ?>
                                            </time>
                                            <?php if ($visit['activity_span'] !== null): ?>
                                                <?php $spanLabel = ioa_translate('recorded_activity_span') . ': ' . $visit['activity_span']; ?>
                                                <span class="visit-summary__metric visit-summary__metric--span" title="<?= h($spanLabel) ?>">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                                    <span class="visit-summary__value" aria-hidden="true"><?= h(strtr($visit['activity_span'], [' min' => 'm', ' hr' => 'h', ' d' => 'd'])) ?></span>
                                                    <span class="visit-summary__accessible"><?= h($spanLabel) ?></span>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="visit-summary__controls">
                                            <span class="visit-summary__id">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 16v-2.38C4 11.5 2.97 10.5 3 8c.03-2.72 1.49-6 4-6 1.5 0 2 1.5 2 3 0 2.73-1 5-1 7v4H4ZM20 20v-2.38c0-2.12 1.03-3.12 1-5.62-.03-2.72-1.49-6-4-6-1.5 0-2 1.5-2 3 0 2.73 1 5 1 7v4h4ZM4 20a2 2 0 0 0 4 0v-1H4v1ZM16 22h4a2 2 0 0 1-4 0Z"/></svg>
                                                <span class="visit-summary__accessible"><?= h(sprintf(ioa_translate('visit_id'), $visit['visit_id'])) ?></span>
                                                <span aria-hidden="true"><?= (int)$visit['visit_id'] ?></span>
                                            </span>
                                            <?php if ($isExpandable): ?>
                                                <span class="visit-summary__disclosure"><?= h(ioa_translate('visit_details') === 'visit_details' ? 'Details' : ioa_translate('visit_details')) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </<?= $headerTag ?>>

                                <?php if ($isExpandable): ?>
                                <div class="visit-highlights">
                                    <?php foreach ($visit['highlights'] as $group => $photos): ?>
                                        <section class="visit-highlight-group">
                                            <h3><?= ioa_t(['views' => 'tab_most_viewed', 'downloads' => 'tab_most_downloaded', 'basket' => 'basket_activity'][$group]) ?></h3>
                                            <?php foreach ($photos as $photo): ?>
                                                <div class="visit-highlight-photo">
                                                    <?php if (isset($visitImages[$photo['photo_id']])): ?>
                                                        <img class="visit-highlight-image" src="<?= h($visitImages[$photo['photo_id']]) ?>" alt="" loading="lazy">
                                                    <?php else: ?>
                                                        <span class="visit-highlight-image visit-image-placeholder" role="img" aria-label="<?= ioa_t('no_preview') ?>">&ndash;</span>
                                                    <?php endif; ?>
                                                    <div class="visit-highlight-caption visit-highlight-caption--ranking">
                                                        <?php if ($group === 'basket'): ?>
                                                            <?php
                                                            $basketActionLabel = ioa_translate($photo['event_type'] === 'basket_add' ? 'basket_action_added' : 'basket_action_removed');
                                                            ?>
                                                            <span class="ranking-primary visit-highlight-basket-action">
                                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m8 3-4 6m12-6 4 6M2 9h20l-3 12H5L2 9Zm7 4v4m6-4v4"/></svg>
                                                                <span><?= h($basketActionLabel) ?></span>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($group !== 'basket'): ?>
                                                            <?php
                                                            $highlightMetricLabel = $group === 'views'
                                                                ? formatCountLabel($photo['views'], 'one_view', 'views_count', '%d view', '%d views')
                                                                : formatCountLabel($photo['downloads'], 'one_download', 'downloads_count', '%d download', '%d downloads');
                                                            ?>
                                                            <span class="ranking-primary" title="<?= h($highlightMetricLabel) ?>">
                                                                <?php if ($group === 'views'): ?>
                                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                                                <?php else: ?>
                                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3v12m-4-4 4 4 4-4M5 17v4h14v-4"/></svg>
                                                                <?php endif; ?>
                                                                <span aria-hidden="true"><?= (int)$photo[$group] ?></span>
                                                                <span class="ranking-primary__accessible"><?= h($highlightMetricLabel) ?></span>
                                                            </span>
                                                        <?php endif; ?>
                                                        <div class="visit-highlight-title"><?= h($photoTitles[$photo['photo_id']] ?? (ioa_translate('photo') . ' ' . $photo['photo_id'])) ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if ($group === 'basket' && $visit['more_basket_actions'] > 0): ?>
                                                <div class="photo-item__meta"><?= h(sprintf(ioa_translate('more_basket_actions'), $visit['more_basket_actions'])) ?></div>
                                            <?php endif; ?>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                                <details class="visit-journey">
                                    <summary><?= ioa_t('show_activity') ?></summary>
                                <div class="visit-timeline visit-activity-summary">
                                    <?php foreach ($visit['context_sections'] as $section): ?>
                                        <section class="visit-context-section">
                                            <h3 class="visit-context-section__title"><?= h($section['title'] ?? ioa_translate('activity_context_unknown')) ?></h3>
                                            <div class="visit-timeline__meta">
                                                <?= h(formatActivityRange($section['first_activity'], $section['latest_activity'])) ?>
                                            </div>
                                            <?php foreach ($section['items'] as $item): ?>
                                                <?php
                                                $actionLabels = [
                                                    'photo_view' => ['one_photo_viewed', 'photos_viewed_count'],
                                                    'photo_download' => ['activity_one_direct_download', 'activity_direct_downloads'],
                                                    'basket_add' => ['activity_one_basket_add', 'activity_basket_adds'],
                                                    'basket_remove' => ['activity_one_basket_remove', 'activity_basket_removes']
                                                ];
                                                $photoIds = array_values($item['photo_ids']);
                                                $photoCount = count($photoIds);
                                                $displayCount = $item['type'] === 'photo_view' ? $photoCount : $item['actions'];
                                                // Presentation only: retain first occurrence order, including photos without images.
                                                $galleryPhotos = [];
                                                foreach ($photoIds as $rawPhotoId) {
                                                    $galleryPhotoId = (int)$rawPhotoId;
                                                    if ($galleryPhotoId <= 0 || isset($galleryPhotos[$galleryPhotoId])) continue;
                                                    $galleryPhotos[$galleryPhotoId] = [
                                                        'id' => (string)$galleryPhotoId,
                                                        'title' => $photoTitles[$galleryPhotoId] ?? (ioa_translate('photo') . ' ' . $galleryPhotoId),
                                                        'image_url' => $visitImages[$galleryPhotoId] ?? null
                                                    ];
                                                }
                                                $galleryPhotos = array_values($galleryPhotos);
                                                $initialPhotos = array_slice($galleryPhotos, 0, 50);
                                                $remainingPhotos = array_slice($galleryPhotos, 50);
                                                ?>
                                                <div class="visit-activity-summary__item">
                                                    <div class="visit-timeline__action">
                                                        <?php if (isset($actionLabels[$item['type']])): ?>
                                                            <?= h(sprintf(ioa_translate($actionLabels[$item['type']][$displayCount === 1 ? 0 : 1]), $displayCount)) ?>
                                                        <?php else: ?>
                                                            <?= ioa_t($item['type']) ?>
                                                        <?php endif; ?>
                                                        <?php if ($item['type'] === 'selection_download'): ?>
                                                            &middot; <?= h(sprintf(ioa_translate($photoCount === 1 ? 'one_selected_photo' : 'selected_photos_count'), $photoCount)) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($initialPhotos): ?>
                                                        <div data-visit-gallery data-visit-id="<?= (int)$visit['visit_id'] ?>">
                                                            <div class="visit-photo-gallery" data-gallery-grid>
                                                                <?php foreach ($initialPhotos as $galleryPhoto): ?>
                                                                    <button type="button" class="visit-photo-trigger" data-visit-photo data-photo-id="<?= h($galleryPhoto['id']) ?>" data-visit-id="<?= (int)$visit['visit_id'] ?>" aria-haspopup="dialog" aria-expanded="false" aria-controls="visit-photo-popover" aria-label="<?= h('Inspect ' . $galleryPhoto['title'] . ' in this Visit') ?>" title="<?= h($galleryPhoto['title']) ?>">
                                                                        <?php if ($galleryPhoto['image_url'] !== null): ?>
                                                                            <img src="<?= h($galleryPhoto['image_url']) ?>" alt="" loading="lazy">
                                                                        <?php else: ?>
                                                                            <span aria-hidden="true">&ndash;</span>
                                                                        <?php endif; ?>
                                                                    </button>
                                                                <?php endforeach; ?>
                                                            <?php if ($remainingPhotos): ?>
                                                                <button type="button" class="visit-gallery-more" data-gallery-more aria-label="Show <?= min(50, count($remainingPhotos)) ?> more photos">+<?= min(50, count($remainingPhotos)) ?></button>
                                                            <?php endif; ?>
                                                            </div>
                                                            <?php if ($remainingPhotos): ?>
                                                                <script type="application/json" data-gallery-remaining><?= json_encode($remainingPhotos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
                                                            <?php endif; ?>
                                                            <span class="visit-summary__accessible" data-gallery-status role="status" aria-live="polite"></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                                </details>
                                <?php endif; ?>
                            </<?= $visitTag ?>>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </section>

    <footer class="dashboard-footer">
        <?= ioa_t('app_name') ?><?php if (is_string($installedVersion)): ?> · v<?= h($installedVersion) ?><?php endif; ?>
        ·
        <a href="https://jesperalvermark.se/ioa">Website</a>
        ·
        <a href="mailto:ioa@jesperalvermark.se">
            <?= ioa_t('feedback') ?>: ioa@jesperalvermark.se
        </a>
    </footer>

</div>

<dialog class="photo-inspector-modal" data-photo-inspector-modal aria-labelledby="photo-inspector-modal-title">
    <header>
        <h2 id="photo-inspector-modal-title">Photo inspector</h2>
        <button type="button" class="photo-inspector-modal-close" data-inspector-close aria-label="Close Photo inspector" autofocus>&times;</button>
    </header>
    <div class="photo-inspector" data-modal-inspector hidden></div>
    <p class="photo-inspector-modal-status" role="status" aria-live="polite"></p>
</dialog>
<template data-visit-popover-icons>
<svg data-icon="views" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
<svg data-icon="downloads" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3v12m-4-4 4 4 4-4M5 17v4h14v-4"/></svg>
<svg data-icon="basket" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m8 3-4 6m12-6 4 6M2 9h20l-3 12H5L2 9Zm7 4v4m6-4v4"/></svg>
</template>
<div id="visit-photo-popover" class="visit-photo-popover" data-visit-photo-popover role="dialog" aria-label="Photo activity in this Visit" tabindex="-1" hidden>
    <div class="visit-photo-popover-surface">
        <button type="button" class="visit-photo-popover-close" data-popover-close aria-label="Close photo activity">&times;</button>
        <div class="photo-inspector" data-popover-content></div>
        <button type="button" class="visit-photo-popover-full" data-popover-full>Full inspector <span aria-hidden="true">→</span></button>
        <span class="photo-inspector-modal-status" data-popover-status role="status" aria-live="polite"></span>
    </div>
</div>
<script src="assets/photo-inspector.js" defer></script>
<script src="assets/photo-search.js" defer></script>
<script src="assets/photo-inspector-modal.js" defer></script>
<script src="assets/visit-photo-popover.js" defer></script>
<script src="assets/visit-photo-gallery.js" defer></script>
<script>
    (function () {
        const component = document.querySelector('[data-photo-tabs]');

        if (!component) {
            return;
        }

        const tabs = Array.from(component.querySelectorAll('[data-photo-tab]'));
        const panels = Array.from(component.querySelectorAll('[data-photo-panel]'));

        function activateTab(tab, updateUrl) {
            const tabName = tab.dataset.photoTab;

            tabs.forEach(function (candidate) {
                const isActive = candidate === tab;
                candidate.setAttribute('aria-selected', isActive ? 'true' : 'false');
                candidate.setAttribute('tabindex', isActive ? '0' : '-1');
            });

            panels.forEach(function (panel) {
                panel.hidden = panel.dataset.photoPanel !== tabName;
            });

            document.querySelectorAll('.filters a.filter').forEach(function (link) {
                const url = new URL(link.href);
                url.searchParams.set('photo_tab', tabName);
                link.href = url.href;
            });

            if (updateUrl) {
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('photo_tab', tabName);
                    window.history.replaceState(null, '', url);
                } catch (error) {
                    // The href remains a functional non-JavaScript fallback.
                }
            }
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                activateTab(tab, true);
            });

            tab.addEventListener('keydown', function (event) {
                let nextIndex = null;

                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    nextIndex = (index + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    nextIndex = (index - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = tabs.length - 1;
                }

                if (nextIndex !== null) {
                    event.preventDefault();
                    activateTab(tabs[nextIndex], true);
                    tabs[nextIndex].focus();
                }
            });
        });
    }());

</script>

</body>
</html>
