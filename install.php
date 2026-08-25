<?php

session_start();

require_once __DIR__ . '/../../system/config.php';
require_once __DIR__ . '/../../../admin/sys/Autoload.php';
require_once __DIR__ . '/localization.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// --------------------------------------------------
// Helpers
// --------------------------------------------------

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function currentInstallerUrl()
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/storage/custom/io200-analytics/install.php';

    return strtok($uri, '?');
}

// --------------------------------------------------
// IO200 admin authentication
// --------------------------------------------------

$authenticated = false;

try {

    $AuthenticationService = new AuthenticationService(
        CMS_SECRETKEY,
        CMS_SECRETKEY,
        'HS256',
        dirname(__DIR__, 3)
    );

    $refreshToken = $_COOKIE['refreshtoken'] ?? null;

    if ($refreshToken) {

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
        '[IO200 Analytics] Authentication check failed: ' .
        $e->getMessage()
    );
}

// --------------------------------------------------
// CSRF
// --------------------------------------------------

if (empty($_SESSION['ioa_installer_csrf'])) {
    $_SESSION['ioa_installer_csrf'] = bin2hex(random_bytes(32));
}

// --------------------------------------------------
// Installer state
// --------------------------------------------------

$tableExists = false;
$adminColumnExists = false;
$dbConnected = false;
$installedNow = false;
$error = null;

if ($authenticated) {

    try {

        $mysqli = new mysqli(
            CMS_DB_HOSTNAME,
            CMS_DB_USERNAME,
            CMS_DB_PASSWORD,
            CMS_DB_DATABASE
        );

        $mysqli->set_charset('utf8mb4');

        $dbConnected = true;

        $result = $mysqli->query("
            SHOW TABLES LIKE 'ioa_events'
        ");

        $tableExists = $result->num_rows > 0;

        if ($tableExists) {
            $result = $mysqli->query("
                SHOW COLUMNS FROM `ioa_events` LIKE 'is_admin'
            ");

            $adminColumnExists = $result->num_rows > 0;
        }

        // --------------------------------------------------
        // Installation POST
        // --------------------------------------------------

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $csrf = $_POST['csrf'] ?? '';

            if (
                !$csrf ||
                !hash_equals($_SESSION['ioa_installer_csrf'], $csrf)
            ) {
                throw new Exception('Invalid installer request.');
            }

            if (!$tableExists) {

                $mysqli->query("
                    CREATE TABLE `ioa_events` (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `event_type` VARCHAR(50) NOT NULL,
                        `page_path` VARCHAR(500) DEFAULT NULL,
                        `photo_id` BIGINT UNSIGNED DEFAULT NULL,
                        `image_url` TEXT DEFAULT NULL,
                        `download_url` TEXT DEFAULT NULL,
                        `batch_data` JSON DEFAULT NULL,
                        `session_id` VARCHAR(64) DEFAULT NULL,
                        `is_admin` TINYINT(1) NOT NULL DEFAULT 0,

                        PRIMARY KEY (`id`),

                        INDEX `idx_event_type` (`event_type`),
                        INDEX `idx_photo_id` (`photo_id`),
                        INDEX `idx_created_at` (`created_at`),
                        INDEX `idx_session_id` (`session_id`)

                    ) ENGINE=InnoDB
                      DEFAULT CHARSET=utf8mb4
                      COLLATE=utf8mb4_unicode_ci
                ");

                $tableExists = true;
                $adminColumnExists = true;
                $installedNow = true;

            } elseif (!$adminColumnExists) {

                $mysqli->query("
                    ALTER TABLE `ioa_events`
                    ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0
                    AFTER `session_id`
                ");

                $adminColumnExists = true;
                $installedNow = true;
            }
        }

        $mysqli->close();

    } catch (Throwable $e) {

        error_log(
            '[IO200 Analytics] Installer error: ' .
            $e->getMessage()
        );

        $error = 'Installation could not be completed. Check the server log for more information.';
    }
}

?>
<!doctype html>
<html lang="sv">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= ioa_t('app_name') ?> Installer</title>

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

        .shell {
            width: 100%;
            max-width: 760px;

            margin: 0 auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;

            margin-bottom: 22px;
        }

        .brand-icon {
            display: flex;
            align-items: center;
            justify-content: center;

            width: 48px;
            height: 48px;

            border-radius: 14px;

            background: #202124;
            color: white;

            font-size: 24px;

            box-shadow:
                0 6px 18px rgba(0, 0, 0, .14);
        }

        .brand-text strong {
            display: block;

            font-size: 20px;
            line-height: 1.2;
        }

        .brand-text span {
            color: #74777c;

            font-size: 14px;
        }

        .card {
            overflow: hidden;

            background: rgba(255, 255, 255, .96);

            border: 1px solid rgba(0, 0, 0, .06);
            border-radius: 18px;

            box-shadow:
                0 18px 55px rgba(0, 0, 0, .08);
        }

        .card-main {
            padding: 38px;
        }

        h1 {
            margin: 0 0 10px;

            font-size: 32px;
            line-height: 1.15;
        }

        h2 {
            margin-top: 0;
        }

        p {
            line-height: 1.6;
        }

        .lead {
            margin-top: 0;
            margin-bottom: 30px;

            color: #6e7177;

            font-size: 17px;
        }

        .status {
            display: grid;
            gap: 10px;

            margin: 28px 0;
        }

        .status-row {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 13px 15px;

            background: #f7f8f9;

            border-radius: 10px;
        }

        .status-icon {
            display: flex;
            align-items: center;
            justify-content: center;

            flex: 0 0 24px;

            width: 24px;
            height: 24px;

            border-radius: 50%;

            font-size: 13px;
            font-weight: bold;
        }

        .ok .status-icon {
            background: #def3e5;
            color: #17703b;
        }

        .waiting .status-icon {
            background: #f5ecd2;
            color: #8a6600;
        }

        .error-box {
            margin: 24px 0;
            padding: 17px 18px;

            background: #fff0f0;

            border: 1px solid #f2caca;
            border-radius: 10px;

            color: #922f2f;
        }

        .success {
            margin-top: 30px;
            padding: 22px;

            background: #eff9f2;

            border: 1px solid #d4eadb;
            border-radius: 12px;
        }

        .success-title {
            margin-bottom: 6px;

            font-size: 19px;
            font-weight: 700;
        }

        .button {
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

            cursor: pointer;

            transition:
                transform .12s ease,
                opacity .12s ease;
        }

        .button:hover {
            opacity: .9;
            transform: translateY(-1px);
        }

        .button.secondary {
            background: #eceef1;
            color: #202124;
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;

            gap: 10px;

            margin-top: 24px;
        }

        form {
            margin: 0;
        }

        code {
            display: block;

            margin: 15px 0 0;
            padding: 15px 16px;

            overflow-x: auto;

            background: #202124;
            color: #f5f5f5;

            border-radius: 9px;

            font-family:
                "SFMono-Regular",
                Consolas,
                "Liberation Mono",
                monospace;

            font-size: 13px;
            line-height: 1.55;
        }

        .auth-box {
            padding: 8px 0 2px;
        }

        .auth-symbol {
            margin-bottom: 18px;

            font-size: 44px;
        }

        .hint {
            margin-top: 18px;

            color: #74777c;

            font-size: 14px;
        }

        .footer {
            padding: 18px 38px;

            background: #f8f9fa;

            border-top: 1px solid #eceef0;

            color: #85888d;

            font-size: 13px;
        }

        @media (max-width: 600px) {

            body {
                padding: 25px 14px;
            }

            .card-main {
                padding: 27px 22px;
            }

            h1 {
                font-size: 27px;
            }

            .button-row {
                flex-direction: column;
            }

            .button {
                width: 100%;
            }

            .footer {
                padding: 16px 22px;
            }
        }

    </style>

</head>

<body>

<div class="shell">

    <div class="brand">

        <div class="brand-icon">
            📊
        </div>

        <div class="brand-text">
            <strong><?= ioa_t('app_name') ?></strong>
            <span>Installation</span>
        </div>

    </div>

    <div class="card">

        <div class="card-main">

            <?php if (!$authenticated): ?>

                <div class="auth-box">

                    <div class="auth-symbol">
                        🔐
                    </div>

                    <h1>Admin login required</h1>

                    <p class="lead">
                        IO200 Analytics uses your existing IO200 Admin login.
                    </p>

                    <p>
                        Log in to IO200 Admin and then return here. The installer
                        does not require separate accounts or passwords.
                    </p>

                    <div class="button-row">

                        <a
                            class="button"
                            href="/admin"
                            target="_blank"
                            rel="noopener"
                        >
                            Log in to IO200 Admin
                        </a>

                        <a
                            class="button secondary"
                            href="<?= h(currentInstallerUrl()) ?>"
                        >
                            I am logged in – try again
                        </a>

                    </div>

                    <p class="hint">
                        Admin opens in a new tab so the installer can remain open here.
                    </p>

                </div>

            <?php else: ?>

                <h1>
                    Install Analytics
                </h1>

                <p class="lead">
                    Set up lightweight photo analytics for IO200 in a few seconds. 📷
                </p>

                <?php if ($error): ?>

                    <div class="error-box">
                        <?= h($error) ?>
                    </div>

                <?php else: ?>

                    <div class="status">

                        <div class="status-row ok">

                            <span class="status-icon">
                                ✓
                            </span>

                            <span>
                                IO200 Admin authenticated
                            </span>

                        </div>

                        <div class="status-row <?= $dbConnected ? 'ok' : 'waiting' ?>">

                            <span class="status-icon">
                                <?= $dbConnected ? '✓' : '○' ?>
                            </span>

                            <span>
                                Database connection
                                <?= $dbConnected ? 'working' : 'waiting' ?>
                            </span>

                        </div>

                        <div class="status-row <?= $tableExists ? 'ok' : 'waiting' ?>">

                            <span class="status-icon">
                                <?= $tableExists ? '✓' : '○' ?>
                            </span>

                            <span>
                                <?= $tableExists
                                    ? 'Analytics table ioa_events exists'
                                    : 'Analytics table needs to be created'
                                ?>
                            </span>

                        </div>

                        <div class="status-row <?= $adminColumnExists ? 'ok' : 'waiting' ?>">

                            <span class="status-icon">
                                <?= $adminColumnExists ? '✓' : '○' ?>
                            </span>

                            <span>
                                <?= $adminColumnExists
                                    ? 'Admin traffic can be identified'
                                    : 'Analytics table needs to be updated'
                                ?>
                            </span>

                        </div>

                    </div>

                    <?php if (!$tableExists || !$adminColumnExists): ?>

                        <form method="post">

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= h($_SESSION['ioa_installer_csrf']) ?>"
                            >

                            <button
                                class="button"
                                type="submit"
                            >
                                <?= $tableExists
                                    ? 'Update IO200 Analytics'
                                    : 'Install IO200 Analytics'
                                ?>
                            </button>

                        </form>

                    <?php else: ?>

                        <div class="success">

                            <div class="success-title">

                                <?php if ($installedNow): ?>
                                    Analytics is installed! 🎉
                                <?php else: ?>
                                    Analytics is already installed. ✓
                                <?php endif; ?>

                            </div>

                            <p>
                                The database is ready. Add the following line under
                                <strong>IO200 → Settings → Code Injection</strong>:
                            </p>

                            <code>&lt;script src="/storage/custom/io200-analytics/analytics.js"&gt;&lt;/script&gt;</code>

                            <div class="button-row">

                                <a
                                    class="button"
                                    href="dashboard.php"
                                >
                                    Open Analytics
                                </a>

                                <a
                                    class="button secondary"
                                    href="/admin"
                                >
                                    Open IO200 Admin
                                </a>

                            </div>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            <?php endif; ?>

        </div>

        <div class="footer">
            IO200 Analytics · isolated from IO200 core · no separate database credentials
        </div>

    </div>

</div>

</body>
</html>
