<?php

require_once __DIR__ . '/../../system/config.php';
require_once __DIR__ . '/../../../admin/sys/Autoload.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// --------------------------------------------------
// IO200 admin authentication
// --------------------------------------------------

$AuthenticationService = new AuthenticationService(
    CMS_SECRETKEY,
    CMS_SECRETKEY,
    'HS256',
    dirname(__DIR__, 3)
);

$refreshToken = $_COOKIE['refreshtoken'] ?? null;

if (!$refreshToken) {
    http_response_code(403);
    die('IO200 Analytics: authentication required.');
}

$tokenData = $AuthenticationService->readUserToken($refreshToken);

if (
    ErrorInfo::isError($tokenData) ||
    !is_array($tokenData) ||
    ($tokenData['type'] ?? null) !== 'refresh' ||
    empty($tokenData['mail'])
) {
    http_response_code(403);
    die('IO200 Analytics: authentication required.');
}

// --------------------------------------------------
// Helpers
// --------------------------------------------------

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function percent($part, $total)
{
    if ($total <= 0) {
        return 0;
    }

    return round(($part / $total) * 100, 1);
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

// --------------------------------------------------
// Period filter
// --------------------------------------------------

$period = $_GET['period'] ?? '30';

$allowedPeriods = [
    '7'   => '7 dagar',
    '30'  => '30 dagar',
    '90'  => '90 dagar',
    'all' => 'All tid'
];

if (!array_key_exists($period, $allowedPeriods)) {
    $period = '30';
}

$allowedSorts = [
    'views' => 'Visningar',
    'sessions' => 'Sessioner',
    'basket' => 'Basket',
    'downloads' => 'Downloads'
];

$sort = $_GET['sort'] ?? 'views';
$direction = $_GET['direction'] ?? 'desc';

if (!array_key_exists($sort, $allowedSorts)) {
    $sort = 'views';
}

if (!in_array($direction, ['asc', 'desc'], true)) {
    $direction = 'desc';
}

$allowedPhotoTabs = ['latest', 'views', 'downloads'];
$photoTab = $_GET['photo_tab'] ?? 'latest';

if (!in_array($photoTab, $allowedPhotoTabs, true)) {
    $photoTab = 'latest';
}

$includeAdmin = ($_GET['include_admin'] ?? '') === '1';

$whereAdmin = $includeAdmin
    ? ''
    : 'AND is_admin = 0';

$whereDate = '';

if ($period !== 'all') {
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

    $sessions = getSingleValue(
        $mysqli,
        "
        SELECT COUNT(DISTINCT session_id)
        FROM ioa_events
        WHERE session_id IS NOT NULL
          AND session_id <> ''
          {$whereAdmin}
          {$whereDate}
        "
    );

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
        SELECT batch_data
        FROM ioa_events
        WHERE event_type = 'batch_download'
          AND batch_data IS NOT NULL
          {$whereAdmin}
          {$whereDate}
    ");

    $batchDownloads = 0;

    while ($row = $batchResult->fetch_assoc()) {

        $batch = json_decode($row['batch_data'], true);

        if (
            is_array($batch) &&
            isset($batch['photo_ids']) &&
            is_array($batch['photo_ids'])
        ) {
            $batchDownloads += count($batch['photo_ids']);
        }
    }

    $downloads = $singleDownloads + $batchDownloads;

    // --------------------------------------------------
    // Latest viewed photo
    // --------------------------------------------------

    $latestViewedPhoto = null;

    $result = $mysqli->query("
        SELECT
            photo_id,
            image_url,
            page_path,
            created_at
        FROM ioa_events
        WHERE event_type = 'photo_view'
          {$whereAdmin}
          {$whereDate}
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");

    $latestViewedPhoto = $result->fetch_assoc();

    if ($latestViewedPhoto) {
        // No stable album lookup is available here, so retain the event's own
        // context and turn its path into a human-readable breadcrumb.
        $latestViewedPhoto['page_context'] = readablePagePath(
            $latestViewedPhoto['page_path'] ?? null
        );
    }

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
              {$whereDate}
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");

        $imageRow = $result->fetch_assoc();

        if ($imageRow) {
            $latestViewedPhoto['image_url'] = $imageRow['image_url'];
        }
    }

    // --------------------------------------------------
    // Recent photo views
    // --------------------------------------------------

    $recentPhotoViews = [];

    $result = $mysqli->query("
        SELECT
            photo_id,
            image_url,
            page_path,
            created_at
        FROM ioa_events
        WHERE event_type = 'photo_view'
          {$whereAdmin}
          {$whereDate}
        ORDER BY created_at DESC, id DESC
        LIMIT 20
    ");

    while ($row = $result->fetch_assoc()) {
        $row['page_context'] = readablePagePath($row['page_path'] ?? null);

        if (empty($row['image_url']) && !empty($row['photo_id'])) {
            $photoId = (int)$row['photo_id'];

            $imageResult = $mysqli->query("
                SELECT image_url
                FROM ioa_events
                WHERE photo_id = {$photoId}
                  AND image_url IS NOT NULL
                  AND image_url <> ''
                  {$whereAdmin}
                  {$whereDate}
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");

            $imageRow = $imageResult->fetch_assoc();

            if ($imageRow) {
                $row['image_url'] = $imageRow['image_url'];
            }
        }

        $recentPhotoViews[] = $row;
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
            COUNT(DISTINCT session_id) AS sessions,
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
            'sessions' => (int)$row['sessions'],
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
                'sessions' => 0,
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
                'sessions' => 0,
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

        foreach ($batch['photo_ids'] as $photoId) {

            $photoId = (string)$photoId;

            if (!isset($photoStats[$photoId])) {
                $photoStats[$photoId] = [
                    'photo_id' => $photoId,
                    'views' => 0,
                    'sessions' => 0,
                    'basket' => 0,
                    'downloads' => 0,
                    'image_url' => null
                ];
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

    // Preserve the existing user-selected sort for the views table.
    usort($photoStats, function ($a, $b) use ($sort, $direction) {

        if ($a[$sort] !== $b[$sort]) {
            return $direction === 'asc'
                ? $a[$sort] <=> $b[$sort]
                : $b[$sort] <=> $a[$sort];
        }

        return (int)$a['photo_id'] <=> (int)$b['photo_id'];
    });

    $topPhotos = array_slice($photoStats, 0, 20);

    $mysqli->close();

} catch (Throwable $e) {

    error_log(
        '[IO200 Analytics] Dashboard error: ' .
        $e->getMessage()
    );

    http_response_code(500);

    die('IO200 Analytics: could not load dashboard.');
}

?>
<!doctype html>
<html lang="sv">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>IO200 Analytics</title>

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

        .browser-exclusion {
            width: 100%;
            margin-top: 5px;
        }

        .browser-exclusion .filter {
            cursor: pointer;
        }

        .browser-exclusion-status {
            margin-left: 8px;
            color: #6e7177;
            font-size: 13px;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);

            gap: 16px;

            margin-bottom: 30px;
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

        th,
        td {
            padding: 13px 10px;

            border-bottom: 1px solid #eeeeef;

            text-align: left;
            vertical-align: middle;
        }

        th {
            color: #777a80;

            font-size: 13px;
            font-weight: 600;
        }

        .sort-link {
            color: inherit;
            text-decoration: none;
        }

        .sort-link:hover {
            color: #202124;
            text-decoration: underline;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .table-scroll {
            overflow-x: auto;
        }

        .metric-value {
            font-size: 16px;
            font-weight: 650;
        }

        .metric-meta {
            margin-top: 3px;

            color: #8a8d92;

            font-size: 12px;
        }

        .empty {
            padding: 30px 10px;

            color: #777;
            text-align: center;
        }

        @media (max-width: 850px) {

            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .photo-tabs__list {
                grid-template-columns: 1fr;
                gap: 6px;
            }

            .photo-tab {
                border-radius: 10px;
            }

            .photo-tab[aria-selected="true"] {
                margin-bottom: 0;

                border-bottom-color: #e4e5e7;
            }

            .photo-tabs__content {
                margin-top: 8px;

                border-radius: 12px;
            }

        }

        @media (max-width: 650px) {

            body {
                padding: 24px 12px;
            }

            .photo-tab {
                padding: 13px;
            }

            .photo-tab .photo-item__meta--truncate {
                max-width: 62vw;
            }

            table {
                min-width: 760px;
            }

        }

    </style>

</head>

<body>

<div class="dashboard">

    <div class="topbar">

        <div>

            <h1>IO200 Analytics</h1>

            <p class="subtitle">
                Vilka bilder fångar faktiskt publikens intresse? 📷
            </p>

        </div>

        <div class="filters">

            <?php foreach ($allowedPeriods as $value => $label): ?>

                <a
                    class="filter <?= $period === $value ? 'active' : '' ?>"
                    href="?<?= h(http_build_query([
                        'period' => $value,
                        'sort' => $sort,
                        'direction' => $direction,
                        'include_admin' => $includeAdmin ? '1' : '0',
                        'photo_tab' => $photoTab
                    ])) ?>"
                >
                    <?= h($label) ?>
                </a>

            <?php endforeach; ?>

            <a
                class="filter <?= $includeAdmin ? 'active' : '' ?>"
                href="?<?= h(http_build_query([
                    'period' => $period,
                    'sort' => $sort,
                    'direction' => $direction,
                    'include_admin' => $includeAdmin ? '0' : '1',
                    'photo_tab' => $photoTab
                ])) ?>"
            >
                <?= $includeAdmin
                    ? 'Admintrafik inkluderad'
                    : 'Inkludera admintrafik'
                ?>
            </a>

            <div class="browser-exclusion">
                <button
                    type="button"
                    class="filter"
                    id="browser-exclusion-toggle"
                    aria-pressed="false"
                >
                    Exkludera den h&auml;r webbl&auml;saren
                </button>

                <span
                    class="browser-exclusion-status"
                    id="browser-exclusion-status"
                    aria-live="polite"
                ></span>
            </div>

        </div>

    </div>

    <div class="kpi-grid">

        <div class="dashboard-card kpi-card">

            <span class="kpi-card__value">
                <?= number_format($photoViews, 0, ',', ' ') ?>
            </span>

            <span class="kpi-card__label">
                Bildvisningar
            </span>

        </div>

        <div class="dashboard-card kpi-card">

            <span class="kpi-card__value">
                <?= number_format($sessions, 0, ',', ' ') ?>
            </span>

            <span class="kpi-card__label">
                Sessioner
            </span>

        </div>

        <div class="dashboard-card kpi-card">

            <span class="kpi-card__value">
                <?= number_format($basketAdds, 0, ',', ' ') ?>
            </span>

            <span class="kpi-card__label">
                Tillagda i basket
            </span>

        </div>

        <div class="dashboard-card kpi-card">

            <span class="kpi-card__value">
                <?= number_format($downloads, 0, ',', ' ') ?>
            </span>

            <span class="kpi-card__label">
                Nedladdade bilder
            </span>

        </div>

    </div>

    <section class="photo-tabs" data-photo-tabs>

        <div class="photo-tabs__list" role="tablist" aria-label="Bildanalys">

            <a
                class="dashboard-card photo-tab"
                id="photo-tab-latest"
                href="?<?= h(http_build_query([
                    'period' => $period,
                    'sort' => $sort,
                    'direction' => $direction,
                    'include_admin' => $includeAdmin ? '1' : '0',
                    'photo_tab' => 'latest'
                ])) ?>"
                role="tab"
                aria-selected="<?= $photoTab === 'latest' ? 'true' : 'false' ?>"
                aria-controls="photo-panel-latest"
                tabindex="<?= $photoTab === 'latest' ? '0' : '-1' ?>"
                data-photo-tab="latest"
            >
                <h2 class="photo-tab__title">Senaste visningarna</h2>

                <?php if ($latestViewedPhoto): ?>
                    <div class="photo-item photo-item--featured">
                        <?php if (!empty($latestViewedPhoto['image_url'])): ?>
                            <img
                                class="photo-item__thumbnail photo-item__thumbnail--featured"
                                src="<?= h($latestViewedPhoto['image_url']) ?>"
                                alt=""
                                loading="lazy"
                            >
                        <?php endif; ?>

                        <div class="photo-item__body">
                            <div class="photo-item__id">
                                Photo <?= $latestViewedPhoto['photo_id'] !== null
                                    ? h($latestViewedPhoto['photo_id'])
                                    : '&ndash;'
                                ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="photo-item__meta">Ingen data för valt filter.</div>
                <?php endif; ?>
            </a>

            <a
                class="dashboard-card photo-tab"
                id="photo-tab-views"
                href="?<?= h(http_build_query([
                    'period' => $period,
                    'sort' => $sort,
                    'direction' => $direction,
                    'include_admin' => $includeAdmin ? '1' : '0',
                    'photo_tab' => 'views'
                ])) ?>"
                role="tab"
                aria-selected="<?= $photoTab === 'views' ? 'true' : 'false' ?>"
                aria-controls="photo-panel-views"
                tabindex="<?= $photoTab === 'views' ? '0' : '-1' ?>"
                data-photo-tab="views"
            >
                <h2 class="photo-tab__title">Mest visade</h2>

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
                                Photo <?= h($mostViewedPhoto['photo_id']) ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="photo-item__meta">Ingen data för valt filter.</div>
                <?php endif; ?>
            </a>

            <a
                class="dashboard-card photo-tab"
                id="photo-tab-downloads"
                href="?<?= h(http_build_query([
                    'period' => $period,
                    'sort' => $sort,
                    'direction' => $direction,
                    'include_admin' => $includeAdmin ? '1' : '0',
                    'photo_tab' => 'downloads'
                ])) ?>"
                role="tab"
                aria-selected="<?= $photoTab === 'downloads' ? 'true' : 'false' ?>"
                aria-controls="photo-panel-downloads"
                tabindex="<?= $photoTab === 'downloads' ? '0' : '-1' ?>"
                data-photo-tab="downloads"
            >
                <h2 class="photo-tab__title">Mest nedladdade</h2>

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
                                Photo <?= h($mostDownloadedPhoto['photo_id']) ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="photo-item__meta">Ingen data för valt filter.</div>
                <?php endif; ?>
            </a>

        </div>

        <div class="dashboard-card panel photo-tabs__content">

            <div
                class="photo-tabs__panel"
                id="photo-panel-latest"
                role="tabpanel"
                aria-labelledby="photo-tab-latest"
                tabindex="0"
                <?= $photoTab === 'latest' ? '' : 'hidden' ?>
                data-photo-panel="latest"
            >
                <div class="panel-header">
                    <h2>Senaste bildvisningarna</h2>
                    <span class="panel-hint">
                        Senaste 20 · <?= h($allowedPeriods[$period]) ?>
                    </span>
                </div>

                <?php if (count($recentPhotoViews) === 0): ?>
                    <div class="empty">
                        Inga bildvisningar för valt filter.
                    </div>
                <?php else: ?>
                    <div class="photo-list">
                        <?php foreach ($recentPhotoViews as $recentView): ?>
                            <div class="photo-item photo-item--compact">
                                <?php if (!empty($recentView['image_url'])): ?>
                                    <a
                                        class="thumbnail-link"
                                        href="<?= h($recentView['image_url']) ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <img
                                            class="photo-item__thumbnail photo-item__thumbnail--compact"
                                            src="<?= h($recentView['image_url']) ?>"
                                            alt=""
                                            loading="lazy"
                                        >
                                    </a>
                                <?php endif; ?>

                                <div class="photo-item__body">
                                    <div class="photo-item__primary">
                                        <span class="photo-item__id">
                                            Photo <?= $recentView['photo_id'] !== null
                                                ? h($recentView['photo_id'])
                                                : '&ndash;'
                                            ?>
                                        </span>
                                        <time
                                            class="photo-item__meta"
                                            datetime="<?= h($recentView['created_at']) ?>"
                                        >
                                            <?= h($recentView['created_at']) ?>
                                        </time>
                                    </div>
                                    <div class="photo-item__meta photo-item__meta--truncate">
                                        Album/sida:
                                        <?= $recentView['page_context'] !== null
                                            ? h($recentView['page_context'])
                                            : '&ndash;'
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

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
                    <h2>Mest visade bilderna</h2>
                    <span class="panel-hint">
                        Topp 20 · <?= h($allowedPeriods[$period]) ?>
                    </span>
                </div>

                <?php if (count($topPhotos) === 0): ?>
                    <div class="empty">
                        Ingen statistik ännu.
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>

                <thead>

                    <tr>
                        <th>Bild</th>

                        <?php foreach ($allowedSorts as $sortValue => $sortLabel): ?>

                            <?php

                            $nextDirection =
                                $sort === $sortValue && $direction === 'desc'
                                    ? 'asc'
                                    : 'desc';

                            $sortIndicator = $sort === $sortValue
                                ? ($direction === 'asc' ? ' ↑' : ' ↓')
                                : '';

                            ?>

                            <th>
                                <a
                                    class="sort-link"
                                    href="?<?= h(http_build_query([
                                        'period' => $period,
                                        'sort' => $sortValue,
                                        'direction' => $nextDirection,
                                        'include_admin' => $includeAdmin ? '1' : '0',
                                        'photo_tab' => 'views'
                                    ])) ?>"
                                >
                                    <?= h($sortLabel . $sortIndicator) ?>
                                </a>
                            </th>

                        <?php endforeach; ?>
                    </tr>

                </thead>

                <tbody>

                <?php foreach ($topPhotos as $photo): ?>

                    <?php

                    $basketRate = percent(
                        $photo['basket'],
                        $photo['views']
                    );

                    $downloadRate = percent(
                        $photo['downloads'],
                        $photo['views']
                    );

                    ?>

                    <tr>

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

                                    <div class="photo-item__id">
                                        Photo <?= h($photo['photo_id']) ?>
                                    </div>

                                    <div class="photo-item__meta">
                                        IO200 photo ID
                                    </div>

                                </div>

                            </div>

                        </td>

                        <td>

                            <div class="metric-value">
                                <?= (int)$photo['views'] ?>
                            </div>

                        </td>

                        <td>

                            <div class="metric-value">
                                <?= (int)$photo['sessions'] ?>
                            </div>

                        </td>

                        <td>

                            <div class="metric-value">
                                <?= (int)$photo['basket'] ?>
                            </div>

                            <div class="metric-meta">
                                <?= h($basketRate) ?> % av views
                            </div>

                        </td>

                        <td>

                            <div class="metric-value">
                                <?= (int)$photo['downloads'] ?>
                            </div>

                            <div class="metric-meta">
                                <?= h($downloadRate) ?> % av views
                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

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
                    <h2>Mest nedladdade bilderna</h2>
                    <span class="panel-hint">
                        Topp 20 · <?= h($allowedPeriods[$period]) ?>
                    </span>
                </div>

                <?php if (count($topDownloadedPhotos) === 0): ?>
                    <div class="empty">
                        Ingen statistik ännu.
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Bild</th>
                                    <th>Nedladdningar</th>
                                    <th>Visningar</th>
                                    <th>Sessioner</th>
                                    <th>Basket</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($topDownloadedPhotos as $photo): ?>
                                <?php
                                $basketRate = percent($photo['basket'], $photo['views']);
                                $downloadRate = percent($photo['downloads'], $photo['views']);
                                ?>
                                <tr>
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
                                                <div class="photo-item__id">
                                                    Photo <?= h($photo['photo_id']) ?>
                                                </div>
                                                <div class="photo-item__meta">
                                                    IO200 photo ID
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="metric-value">
                                            <?= (int)$photo['downloads'] ?>
                                        </div>
                                        <div class="metric-meta">
                                            <?= h($downloadRate) ?> % av views
                                        </div>
                                    </td>
                                    <td>
                                        <div class="metric-value">
                                            <?= (int)$photo['views'] ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="metric-value">
                                            <?= (int)$photo['sessions'] ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="metric-value">
                                            <?= (int)$photo['basket'] ?>
                                        </div>
                                        <div class="metric-meta">
                                            <?= h($basketRate) ?> % av views
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </section>

</div>

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

    (function () {
        const storageKey = 'ioa_ignore_browser';
        const toggle = document.getElementById('browser-exclusion-toggle');
        const status = document.getElementById('browser-exclusion-status');

        function isExcluded() {
            try {
                return localStorage.getItem(storageKey) === '1';
            } catch (error) {
                return false;
            }
        }

        function render(excluded) {
            toggle.classList.toggle('active', excluded);
            toggle.setAttribute('aria-pressed', excluded ? 'true' : 'false');
            toggle.textContent = excluded
                ? 'Sluta exkludera den h\u00e4r webbl\u00e4saren'
                : 'Exkludera den h\u00e4r webbl\u00e4saren';
            status.textContent = excluded
                ? 'Trafik fr\u00e5n webbl\u00e4saren exkluderas'
                : 'Trafik fr\u00e5n webbl\u00e4saren r\u00e4knas normalt';
        }

        toggle.addEventListener('click', function () {
            const excluded = !isExcluded();

            try {
                if (excluded) {
                    localStorage.setItem(storageKey, '1');
                } else {
                    localStorage.removeItem(storageKey);
                }
            } catch (error) {
                status.textContent = 'Inst\u00e4llningen kunde inte sparas i webbl\u00e4saren';
                return;
            }

            render(excluded);
        });

        render(isExcluded());
    }());
</script>

</body>
</html>
