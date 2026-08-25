<?php

require_once __DIR__ . '/../../system/config.php';
require_once __DIR__ . '/../../../admin/sys/Autoload.php';
require_once __DIR__ . '/localization.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function renderAuthenticationRequired(): void
{
    $retryUrl = htmlspecialchars(
        $_SERVER['REQUEST_URI'] ?? 'dashboard.php',
        ENT_QUOTES,
        'UTF-8'
    );

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

            :root {
                color-scheme: light;
            }

            body {
                margin: 0;
                min-height: 100vh;
                padding: 48px 20px;

                background:
                    radial-gradient(
                        circle at top left,
                        #ffffff 0,
                        #f5f6f8 42%,
                        #eef0f3 100%
                    );
                color: #202124;

                font-family:
                    -apple-system,
                    BlinkMacSystemFont,
                    "Segoe UI",
                    Roboto,
                    Arial,
                    sans-serif;
            }

            .auth-shell {
                width: 100%;
                max-width: 760px;
                margin: 0 auto;
            }

            .auth-brand {
                display: flex;
                align-items: center;
                gap: 14px;

                margin-bottom: 22px;
            }

            .auth-brand-icon {
                display: flex;
                align-items: center;
                justify-content: center;

                width: 48px;
                height: 48px;

                border-radius: 14px;

                background: #202124;
                color: white;

                font-size: 24px;

                box-shadow: 0 6px 18px rgba(0, 0, 0, .14);
            }

            .auth-brand-text strong {
                display: block;

                font-size: 20px;
                line-height: 1.2;
            }

            .auth-brand-text span {
                color: #74777c;

                font-size: 14px;
            }

            .auth-card {
                overflow: hidden;

                background: rgba(255, 255, 255, .96);

                border: 1px solid rgba(0, 0, 0, .06);
                border-radius: 18px;

                box-shadow: 0 18px 55px rgba(0, 0, 0, .08);
            }

            .auth-card-main {
                padding: 38px;
            }

            .auth-symbol {
                margin-bottom: 18px;

                font-size: 44px;
            }

            h1 {
                margin: 0 0 10px;

                font-size: 32px;
                line-height: 1.15;
            }

            .auth-lead {
                margin: 0 0 10px;

                color: #6e7177;

                font-size: 17px;
                line-height: 1.6;
            }

            .auth-message {
                margin: 0;

                line-height: 1.6;
            }

            .auth-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;

                margin-top: 30px;
            }

            .auth-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;

                min-height: 48px;
                padding: 0 20px;

                border: 0;
                border-radius: 9px;

                background: #202124;
                color: white;

                font: inherit;
                font-weight: 650;
                text-decoration: none;

                transition:
                    transform .12s ease,
                    opacity .12s ease;
            }

            .auth-button:hover {
                opacity: .9;
                transform: translateY(-1px);
            }

            .auth-button.secondary {
                background: #eceef1;
                color: #202124;
            }

            .auth-hint {
                margin: 18px 0 0;

                color: #74777c;

                font-size: 14px;
                line-height: 1.55;
            }

            .auth-intro {
                margin: 0 0 24px;
                color: #4d5156;
                font-size: 17px;
                line-height: 1.6;
            }

            .auth-contact {
                margin-top: 22px;
                color: #74777c;
                font-size: 14px;
            }

            .auth-contact a {
                color: inherit;
            }

            @media (max-width: 600px) {
                body {
                    padding: 25px 14px;
                }

                .auth-card-main {
                    padding: 27px 22px;
                }

                h1 {
                    font-size: 27px;
                }

                .auth-actions {
                    flex-direction: column;
                }

                .auth-button {
                    width: 100%;
                }
            }
        </style>
    </head>

    <body>
        <main class="auth-shell">
            <div class="auth-brand">
                <div class="auth-brand-icon" aria-hidden="true">
                    &#128202;
                </div>

                <div class="auth-brand-text">
                    <strong><?= ioa_t('app_name') ?></strong>
                    <span><?= ioa_t('dashboard') ?></span>
                </div>
            </div>

            <div class="auth-card">
                <div class="auth-card-main">
                    <div class="auth-symbol" aria-hidden="true">
                        &#128274;
                    </div>

                    <h1><?= ioa_t('app_name') ?></h1>

                    <p class="auth-intro">
                        <?= ioa_t('product_intro') ?>
                        <?= ioa_t('product_activity_summary') ?>
                    </p>

                    <p class="auth-lead">
                        <?= ioa_t('auth_required_title') ?>
                    </p>

                    <p class="auth-message">
                        <?= ioa_t('auth_required_message') ?>
                    </p>

                    <div class="auth-actions">
                        <a
                            class="auth-button"
                            href="/admin/"
                            target="_blank"
                            rel="noopener"
                        >
                            <?= ioa_t('auth_open_admin') ?>
                        </a>

                        <a
                            class="auth-button secondary"
                            href="#"
                        >
                            <?= ioa_t('get_ioa') ?>
                        </a>

                        <a class="auth-button secondary" href="<?= $retryUrl ?>">
                            <?= ioa_t('auth_retry') ?>
                        </a>
                    </div>

                    <p class="auth-hint">
                        <?= ioa_t('auth_new_tab_hint') ?>
                    </p>

                    <p class="auth-contact">
                        <a href="mailto:ioa@jesperalvermark.se">
                            <?= ioa_t('feedback') ?>: ioa@jesperalvermark.se
                        </a>
                    </p>
                </div>
            </div>
        </main>
    </body>

    </html>
    <?php

    exit;
}

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
    renderAuthenticationRequired();
}

$tokenData = $AuthenticationService->readUserToken($refreshToken);

if (
    ErrorInfo::isError($tokenData) ||
    !is_array($tokenData) ||
    ($tokenData['type'] ?? null) !== 'refresh' ||
    empty($tokenData['mail'])
) {
    http_response_code(403);
    renderAuthenticationRequired();
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
    'today' => ioa_translate('period_today'),
    '7'   => ioa_translate('period_7_days'),
    '30'  => ioa_translate('period_30_days'),
    '90'  => ioa_translate('period_90_days'),
    'all' => ioa_translate('period_all_time')
];

if (!array_key_exists($period, $allowedPeriods)) {
    $period = '30';
}

$allowedSorts = [
    'views' => ioa_translate('views'),
    'sessions' => ioa_translate('metric_sessions'),
    'basket' => ioa_translate('metric_basket'),
    'downloads' => ioa_translate('metric_downloads')
];

$sort = $_GET['sort'] ?? 'views';
$direction = $_GET['direction'] ?? 'desc';

if (!array_key_exists($sort, $allowedSorts)) {
    $sort = 'views';
}

if (!in_array($direction, ['asc', 'desc'], true)) {
    $direction = 'desc';
}

$allowedPhotoTabs = ['latest', 'views', 'downloads', 'visits'];
$photoTab = $_GET['photo_tab'] ?? 'latest';

if (!in_array($photoTab, $allowedPhotoTabs, true)) {
    $photoTab = 'latest';
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

    $result = $mysqli->query("
        SELECT
            COUNT(DISTINCT session_id) AS visits,
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
        'visits' => (int)($visitSummaryRow['visits'] ?? 0),
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
    // Recent visits (existing session_id values)
    // --------------------------------------------------

    $recentVisits = [];

    $result = $mysqli->query("
        SELECT
            session_id,
            MIN(created_at) AS first_activity,
            MAX(created_at) AS latest_activity,
            MAX(id) AS latest_event_id,
            SUM(event_type = 'photo_view') AS photo_views,
            SUM(event_type = 'basket_add') AS basket_adds,
            SUM(event_type = 'photo_download') AS single_downloads
        FROM ioa_events
        WHERE session_id IS NOT NULL
          AND session_id <> ''
          {$whereAdmin}
          {$whereDate}
        GROUP BY session_id
        ORDER BY latest_activity DESC, latest_event_id DESC
        LIMIT 20
    ");

    while ($row = $result->fetch_assoc()) {
        $sessionId = (string)$row['session_id'];

        $recentVisits[$sessionId] = [
            'session_id' => $sessionId,
            'first_activity' => $row['first_activity'],
            'latest_activity' => $row['latest_activity'],
            'photo_views' => (int)$row['photo_views'],
            'basket_adds' => (int)$row['basket_adds'],
            'downloads' => (int)$row['single_downloads'],
            'page_paths' => []
        ];
    }

    if ($recentVisits) {
        $escapedSessionIds = array_map(
            static function ($sessionId) use ($mysqli) {
                return "'" . $mysqli->real_escape_string($sessionId) . "'";
            },
            array_keys($recentVisits)
        );

        $result = $mysqli->query("
            SELECT
                session_id,
                event_type,
                page_path,
                batch_data
            FROM ioa_events
            WHERE session_id IN (" . implode(', ', $escapedSessionIds) . ")
              {$whereAdmin}
              {$whereDate}
        ");

        while ($row = $result->fetch_assoc()) {
            $sessionId = (string)$row['session_id'];

            if (!isset($recentVisits[$sessionId])) {
                continue;
            }

            if (is_string($row['page_path']) && trim($row['page_path']) !== '') {
                $pagePath = trim($row['page_path']);
                $pageContext = readablePagePath($pagePath);

                if ($pageContext !== null) {
                    $recentVisits[$sessionId]['page_paths'][$pagePath] = $pageContext;
                }
            }

            if ($row['event_type'] === 'batch_download' && $row['batch_data'] !== null) {
                $batch = json_decode($row['batch_data'], true);

                if (
                    is_array($batch) &&
                    isset($batch['photo_ids']) &&
                    is_array($batch['photo_ids'])
                ) {
                    $recentVisits[$sessionId]['downloads'] += count($batch['photo_ids']);
                }
            }
        }
    }

    foreach ($recentVisits as &$visit) {
        $visit['page_contexts'] = array_values($visit['page_paths']);
        $visit['page_context_count'] = count($visit['page_paths']);
        unset($visit['page_paths']);
    }
    unset($visit);

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
            grid-template-columns: repeat(4, minmax(0, 1fr));
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

        .visit-preview {
            display: grid;
            gap: 5px;
        }

        .visit-list {
            display: grid;
        }

        .visit-item {
            padding: 10px 0;
        }

        .visit-item:first-child {
            padding-top: 0;
        }

        .visit-item:last-child {
            padding-bottom: 0;
        }

        .visit-item + .visit-item {
            border-top: 1px solid #f0f0f1;
        }

        .visit-item__facts {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px 18px;
        }

        .visit-fact {
            display: grid;
            gap: 3px;
        }

        .visit-fact__label {
            color: #85888d;

            font-size: 12px;
        }

        .visit-fact__value {
            font-size: 14px;
            font-weight: 650;
        }

        .visit-item__contexts {
            margin-top: 7px;
        }

        .visit-context-list {
            display: flex;
            flex-wrap: wrap;
            gap: 3px 14px;

            margin-top: 4px;
        }

        .visit-context-list span {
            color: #5f6368;

            font-size: 12px;
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

            .visit-item__facts {
                grid-template-columns: repeat(2, minmax(0, 1fr));
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

            .visit-item__facts {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            table {
                min-width: 760px;
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

    </style>

</head>

<body>

<div class="dashboard">

    <div class="topbar">

        <div>

            <h1><?= ioa_t('app_name') ?></h1>

            <p class="subtitle">
                <?= ioa_t('dashboard_subtitle') ?>
            </p>

        </div>

        <div class="filters">

            <?php foreach ($allowedPeriods as $value => $label): ?>

                <a
                    class="filter <?= $period === (string)$value ? 'active' : '' ?>"
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

        </div>

    </div>

    <div class="kpi-grid">

        <div class="dashboard-card kpi-card">

            <span class="kpi-card__value">
                <?= number_format($photoViews, 0, ',', ' ') ?>
            </span>

            <span class="kpi-card__label">
                <?= ioa_t('metric_image_views') ?>
            </span>

        </div>

        <div class="dashboard-card kpi-card">

            <span class="kpi-card__value">
                <?= number_format($sessions, 0, ',', ' ') ?>
            </span>

            <span class="kpi-card__label">
                <?= ioa_t('metric_sessions') ?>
            </span>

        </div>

        <div class="dashboard-card kpi-card">

            <span class="kpi-card__value">
                <?= number_format($basketAdds, 0, ',', ' ') ?>
            </span>

            <span class="kpi-card__label">
                <?= ioa_t('metric_added_to_basket') ?>
            </span>

        </div>

        <div class="dashboard-card kpi-card">

            <span class="kpi-card__value">
                <?= number_format($downloads, 0, ',', ' ') ?>
            </span>

            <span class="kpi-card__label">
                <?= ioa_t('metric_downloaded_images') ?>
            </span>

        </div>

    </div>

    <section class="photo-tabs" data-photo-tabs>

        <div class="photo-tabs__list" role="tablist" aria-label="<?= ioa_t('photo_analytics') ?>">

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
                <h2 class="photo-tab__title"><?= ioa_t('tab_latest_views') ?></h2>

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
                                <?= ioa_t('photo') ?> <?= $latestViewedPhoto['photo_id'] !== null
                                    ? h($latestViewedPhoto['photo_id'])
                                    : '&ndash;'
                                ?>
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
                                <?= ioa_t('photo') ?> <?= h($mostViewedPhoto['photo_id']) ?>
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
                                <?= ioa_t('photo') ?> <?= h($mostDownloadedPhoto['photo_id']) ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="photo-item__meta"><?= ioa_t('no_data_for_filter') ?></div>
                <?php endif; ?>
            </a>

            <a
                class="dashboard-card photo-tab"
                id="photo-tab-visits"
                href="?<?= h(http_build_query([
                    'period' => $period,
                    'sort' => $sort,
                    'direction' => $direction,
                    'include_admin' => $includeAdmin ? '1' : '0',
                    'photo_tab' => 'visits'
                ])) ?>"
                role="tab"
                aria-selected="<?= $photoTab === 'visits' ? 'true' : 'false' ?>"
                aria-controls="photo-panel-visits"
                tabindex="<?= $photoTab === 'visits' ? '0' : '-1' ?>"
                data-photo-tab="visits"
            >
                <h2 class="photo-tab__title"><?= ioa_t('metric_visits') ?></h2>

                <?php if ($visitSummary['visits'] > 0): ?>
                    <div class="visit-preview">
                        <div class="photo-item__id">
                            <?= number_format($visitSummary['visits'], 0, ',', ' ') ?> <?= ioa_t('visits_lowercase') ?>
                        </div>
                        <div class="photo-item__meta">
                            <?= number_format($visitSummary['photo_views'], 0, ',', ' ') ?> <?= ioa_t('image_views_lowercase') ?>
                            &middot;
                            <?= number_format($visitSummary['downloads'], 0, ',', ' ') ?> <?= ioa_t('downloads_lowercase') ?>
                            <?php if ($visitSummary['basket_adds'] > 0): ?>
                                &middot;
                                <?= number_format($visitSummary['basket_adds'], 0, ',', ' ') ?> <?= ioa_t('in_basket') ?>
                            <?php endif; ?>
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
                id="photo-panel-latest"
                role="tabpanel"
                aria-labelledby="photo-tab-latest"
                tabindex="0"
                <?= $photoTab === 'latest' ? '' : 'hidden' ?>
                data-photo-panel="latest"
            >
                <div class="panel-header">
                    <h2><?= ioa_t('latest_image_views') ?></h2>
                    <span class="panel-hint">
                        <?= ioa_t('latest_20') ?> · <?= h($allowedPeriods[$period]) ?>
                    </span>
                </div>

                <?php if (count($recentPhotoViews) === 0): ?>
                    <div class="empty">
                        <?= ioa_t('no_image_views_for_filter') ?>
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
                                            <?= ioa_t('photo') ?> <?= $recentView['photo_id'] !== null
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
                                        <?= ioa_t('album_page') ?>:
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
                    <div class="table-scroll">
                        <table>

                <thead>

                    <tr>
                        <th><?= ioa_t('image') ?></th>

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
                                        <?= ioa_t('photo') ?> <?= h($photo['photo_id']) ?>
                                    </div>

                                    <div class="photo-item__meta">
                                        <?= ioa_t('ioa_photo_id') ?>
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
                                <?= h($basketRate) ?> <?= ioa_t('percent_of_views') ?>
                            </div>

                        </td>

                        <td>

                            <div class="metric-value">
                                <?= (int)$photo['downloads'] ?>
                            </div>

                            <div class="metric-meta">
                                <?= h($downloadRate) ?> <?= ioa_t('percent_of_views') ?>
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
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th><?= ioa_t('image') ?></th>
                                    <th><?= ioa_t('metric_downloads') ?></th>
                                    <th><?= ioa_t('views') ?></th>
                                    <th><?= ioa_t('metric_sessions') ?></th>
                                    <th><?= ioa_t('metric_basket') ?></th>
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
                                                    <?= ioa_t('photo') ?> <?= h($photo['photo_id']) ?>
                                                </div>
                                                <div class="photo-item__meta">
                                                    <?= ioa_t('ioa_photo_id') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="metric-value">
                                            <?= (int)$photo['downloads'] ?>
                                        </div>
                                        <div class="metric-meta">
                                            <?= h($downloadRate) ?> <?= ioa_t('percent_of_views') ?>
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
                                            <?= h($basketRate) ?> <?= ioa_t('percent_of_views') ?>
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
                            $visibleContexts = array_slice(
                                $visit['page_contexts'],
                                0,
                                3
                            );
                            $additionalContexts =
                                $visit['page_context_count'] - count($visibleContexts);
                            ?>

                            <article class="visit-item">
                                <div class="visit-item__facts">
                                    <div class="visit-fact">
                                        <span class="visit-fact__label">
                                            <?= ioa_t('first_activity') ?>
                                        </span>
                                        <time
                                            class="visit-fact__value"
                                            datetime="<?= h($visit['first_activity']) ?>"
                                        >
                                            <?= h($visit['first_activity']) ?>
                                        </time>
                                    </div>

                                    <div class="visit-fact">
                                        <span class="visit-fact__label">
                                            <?= ioa_t('latest_activity') ?>
                                        </span>
                                        <time
                                            class="visit-fact__value"
                                            datetime="<?= h($visit['latest_activity']) ?>"
                                        >
                                            <?= h($visit['latest_activity']) ?>
                                        </time>
                                    </div>

                                    <div class="visit-fact">
                                        <span class="visit-fact__label">
                                            <?= ioa_t('metric_image_views') ?>
                                        </span>
                                        <span class="visit-fact__value">
                                            <?= (int)$visit['photo_views'] ?>
                                        </span>
                                    </div>

                                    <div class="visit-fact">
                                        <span class="visit-fact__label">
                                            <?= ioa_t('albums_pages') ?>
                                        </span>
                                        <span class="visit-fact__value">
                                            <?= (int)$visit['page_context_count'] ?>
                                        </span>
                                    </div>

                                    <?php if ($visit['basket_adds'] > 0): ?>
                                        <div class="visit-fact">
                                            <span class="visit-fact__label">
                                                <?= ioa_t('metric_basket') ?>
                                            </span>
                                            <span class="visit-fact__value">
                                                <?= (int)$visit['basket_adds'] ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($visit['downloads'] > 0): ?>
                                        <div class="visit-fact">
                                            <span class="visit-fact__label">
                                                <?= ioa_t('metric_downloads') ?>
                                            </span>
                                            <span class="visit-fact__value">
                                                <?= (int)$visit['downloads'] ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="visit-item__contexts">
                                    <div class="visit-fact__label">
                                        <?= ioa_t('albums_pages') ?>
                                    </div>

                                    <?php if ($visibleContexts): ?>
                                        <div class="visit-context-list">
                                            <?php foreach ($visibleContexts as $context): ?>
                                                <span><?= h($context) ?></span>
                                            <?php endforeach; ?>

                                            <?php if ($additionalContexts > 0): ?>
                                                <span>
                                                    +<?= (int)$additionalContexts ?> <?= ioa_t('more') ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="photo-item__meta">&ndash;</div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </section>

    <footer class="dashboard-footer">
        <a href="mailto:ioa@jesperalvermark.se">
            <?= ioa_t('feedback') ?>: ioa@jesperalvermark.se
        </a>
    </footer>

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

</script>

</body>
</html>
