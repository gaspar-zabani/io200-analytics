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
        LIMIT 10
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

    // Sort top photos by the selected metric
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

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);

            gap: 16px;

            margin-bottom: 30px;
        }

        .stat-card {
            padding: 22px;

            background: white;

            border-radius: 12px;

            box-shadow:
                0 2px 8px rgba(0, 0, 0, .06);
        }

        .stat-value {
            display: block;

            margin-bottom: 5px;

            font-size: 32px;
            font-weight: 750;
        }

        .stat-label {
            color: #73767b;

            font-size: 14px;
        }

        .panel {
            padding: 24px;

            background: white;

            border-radius: 12px;

            box-shadow:
                0 2px 8px rgba(0, 0, 0, .06);
        }

        .latest-viewed-panel {
            margin-bottom: 30px;
        }

        .latest-viewed {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .latest-viewed-details {
            display: grid;
            gap: 5px;
        }

        .recent-views {
            margin-top: 20px;
            padding-top: 16px;

            border-top: 1px solid #eeeeef;
        }

        .recent-views-title {
            margin: 0 0 10px;

            color: #73767b;

            font-size: 13px;
            font-weight: 600;
        }

        .recent-views-list {
            display: grid;
            gap: 6px;
        }

        .recent-view {
            display: flex;
            align-items: center;
            gap: 10px;

            min-height: 46px;
        }

        .recent-view-thumbnail {
            display: block;

            width: 60px;
            height: 42px;

            object-fit: cover;

            background: #eee;

            border-radius: 5px;
        }

        .recent-view-details {
            min-width: 0;
        }

        .recent-view-primary {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 10px;
            align-items: baseline;
        }

        .recent-view-context {
            overflow: hidden;

            color: #85888d;

            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
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

        .photo {
            display: flex;
            align-items: center;

            gap: 13px;
        }

        .thumbnail-link {
            display: block;

            flex: 0 0 auto;
        }

        .thumbnail {
            display: block;

            width: 96px;
            height: 68px;

            object-fit: cover;

            background: #eee;

            border-radius: 7px;
        }

        .photo-id {
            font-weight: 700;
        }

        .muted {
            margin-top: 3px;

            color: #85888d;

            font-size: 12px;
        }

        .number {
            font-size: 16px;
            font-weight: 650;
        }

        .rate {
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

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

        }

        @media (max-width: 650px) {

            body {
                padding: 24px 12px;
            }

            .panel {
                overflow-x: auto;
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
                        'include_admin' => $includeAdmin ? '1' : '0'
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
                    'include_admin' => $includeAdmin ? '0' : '1'
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

    <div class="stats">

        <div class="stat-card">

            <span class="stat-value">
                <?= number_format($photoViews, 0, ',', ' ') ?>
            </span>

            <span class="stat-label">
                Bildvisningar
            </span>

        </div>

        <div class="stat-card">

            <span class="stat-value">
                <?= number_format($sessions, 0, ',', ' ') ?>
            </span>

            <span class="stat-label">
                Sessioner
            </span>

        </div>

        <div class="stat-card">

            <span class="stat-value">
                <?= number_format($basketAdds, 0, ',', ' ') ?>
            </span>

            <span class="stat-label">
                Tillagda i basket
            </span>

        </div>

        <div class="stat-card">

            <span class="stat-value">
                <?= number_format($downloads, 0, ',', ' ') ?>
            </span>

            <span class="stat-label">
                Nedladdade bilder
            </span>

        </div>

    </div>

    <div class="panel latest-viewed-panel">

        <div class="panel-header">

            <h2>Senast visade bild</h2>

            <span class="panel-hint">
                <?= h($allowedPeriods[$period]) ?>
            </span>

        </div>

        <?php if (!$latestViewedPhoto): ?>

            <div class="empty">
                Inga bildvisningar f&ouml;r valt filter.
            </div>

        <?php else: ?>

            <div class="latest-viewed">

                <?php if (!empty($latestViewedPhoto['image_url'])): ?>

                    <a
                        class="thumbnail-link"
                        href="<?= h($latestViewedPhoto['image_url']) ?>"
                        target="_blank"
                        rel="noopener"
                    >

                        <img
                            class="thumbnail"
                            src="<?= h($latestViewedPhoto['image_url']) ?>"
                            alt=""
                            loading="lazy"
                        >

                    </a>

                <?php endif; ?>

                <div class="latest-viewed-details">

                    <div class="photo-id">
                        Photo <?= $latestViewedPhoto['photo_id'] !== null
                            ? h($latestViewedPhoto['photo_id'])
                            : '&ndash;'
                        ?>
                    </div>

                    <time
                        class="muted"
                        datetime="<?= h($latestViewedPhoto['created_at']) ?>"
                    >
                        <?= h($latestViewedPhoto['created_at']) ?>
                    </time>

                    <div class="muted">
                        Album/sida:
                        <?= $latestViewedPhoto['page_context'] !== null
                            ? h($latestViewedPhoto['page_context'])
                            : '&ndash;'
                        ?>
                    </div>

                </div>

            </div>

            <div class="recent-views">

                <h3 class="recent-views-title">
                    10 senaste bildvisningarna
                </h3>

                <div class="recent-views-list">

                    <?php foreach ($recentPhotoViews as $recentView): ?>

                        <div class="recent-view">

                            <?php if (!empty($recentView['image_url'])): ?>

                                <a
                                    class="thumbnail-link"
                                    href="<?= h($recentView['image_url']) ?>"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <img
                                        class="recent-view-thumbnail"
                                        src="<?= h($recentView['image_url']) ?>"
                                        alt=""
                                        loading="lazy"
                                    >
                                </a>

                            <?php endif; ?>

                            <div class="recent-view-details">

                                <div class="recent-view-primary">
                                    <span class="photo-id">
                                        Photo <?= $recentView['photo_id'] !== null
                                            ? h($recentView['photo_id'])
                                            : '&ndash;'
                                        ?>
                                    </span>

                                    <time
                                        class="muted"
                                        datetime="<?= h($recentView['created_at']) ?>"
                                    >
                                        <?= h($recentView['created_at']) ?>
                                    </time>
                                </div>

                                <div class="recent-view-context">
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

            </div>

        <?php endif; ?>

    </div>

    <div class="panel">

        <div class="panel-header">

            <h2>Mest visade bilder</h2>

            <span class="panel-hint">
                Topp 20 · <?= h($allowedPeriods[$period]) ?>
            </span>

        </div>

        <?php if (count($topPhotos) === 0): ?>

            <div class="empty">
                Ingen statistik ännu.
            </div>

        <?php else: ?>

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
                                        'include_admin' => $includeAdmin ? '1' : '0'
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

                            <div class="photo">

                                <?php if (!empty($photo['image_url'])): ?>

                                    <a
                                        class="thumbnail-link"
                                        href="<?= h($photo['image_url']) ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >

                                        <img
                                            class="thumbnail"
                                            src="<?= h($photo['image_url']) ?>"
                                            alt=""
                                            loading="lazy"
                                        >

                                    </a>

                                <?php endif; ?>

                                <div>

                                    <div class="photo-id">
                                        Photo <?= h($photo['photo_id']) ?>
                                    </div>

                                    <div class="muted">
                                        IO200 photo ID
                                    </div>

                                </div>

                            </div>

                        </td>

                        <td>

                            <div class="number">
                                <?= (int)$photo['views'] ?>
                            </div>

                        </td>

                        <td>

                            <div class="number">
                                <?= (int)$photo['sessions'] ?>
                            </div>

                        </td>

                        <td>

                            <div class="number">
                                <?= (int)$photo['basket'] ?>
                            </div>

                            <div class="rate">
                                <?= h($basketRate) ?> % av views
                            </div>

                        </td>

                        <td>

                            <div class="number">
                                <?= (int)$photo['downloads'] ?>
                            </div>

                            <div class="rate">
                                <?= h($downloadRate) ?> % av views
                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

</div>

<script>
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
