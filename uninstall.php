<?php

session_start();

require_once __DIR__ . '/../../system/config.php';
require_once __DIR__ . '/../../../admin/sys/Autoload.php';

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

function currentUninstallerUrl()
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/storage/custom/io200-analytics/uninstall.php';

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
        '[IO200 Analytics] Uninstaller authentication check failed: ' .
        $e->getMessage()
    );
}

// --------------------------------------------------
// CSRF
// --------------------------------------------------

if (empty($_SESSION['ioa_uninstaller_csrf'])) {
    $_SESSION['ioa_uninstaller_csrf'] = bin2hex(random_bytes(32));
}

// --------------------------------------------------
// Uninstaller state
// --------------------------------------------------

$tableExists = false;
$dbConnected = false;
$dataDeleted = false;
$error = null;

if ($authenticated) {
    try {
        $deleteRequested = $_SERVER['REQUEST_METHOD'] === 'POST';

        if ($deleteRequested) {
            $csrf = $_POST['csrf'] ?? '';
            $action = $_POST['action'] ?? '';
            $confirmation = $_POST['confirmation'] ?? '';

            if (
                !is_string($csrf) ||
                !$csrf ||
                !hash_equals($_SESSION['ioa_uninstaller_csrf'], $csrf) ||
                $action !== 'delete_analytics_data' ||
                $confirmation !== 'DELETE'
            ) {
                throw new Exception('Invalid uninstaller request.');
            }
        }

        $mysqli = new mysqli(
            CMS_DB_HOSTNAME,
            CMS_DB_USERNAME,
            CMS_DB_PASSWORD,
            CMS_DB_DATABASE
        );

        $mysqli->set_charset('utf8mb4');
        $dbConnected = true;

        if ($deleteRequested) {
            // Explicit allowlist: ioa_events is currently the only table
            // created and owned by IO200 Analytics.
            $mysqli->query('DROP TABLE IF EXISTS `ioa_events`');

            $dataDeleted = true;
            $tableExists = false;
            $_SESSION['ioa_uninstaller_csrf'] = bin2hex(random_bytes(32));
        } else {
            $result = $mysqli->query("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = 'ioa_events'
            ");
            $row = $result->fetch_row();
            $tableExists = (int)($row[0] ?? 0) > 0;
        }

        $mysqli->close();
    } catch (Throwable $e) {
        error_log(
            '[IO200 Analytics] Uninstaller error: ' .
            $e->getMessage()
        );

        $error = 'Avinstallationen kunde inte slutföras. Kontrollera uppgifterna och försök igen. Mer information finns i serverloggen.';
    }
}

?>
<!doctype html>
<html lang="sv">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Avinstallera IO200 Analytics</title>

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
            box-shadow: 0 6px 18px rgba(0, 0, 0, .14);
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
            box-shadow: 0 18px 55px rgba(0, 0, 0, .08);
        }

        .card-main {
            padding: 38px;
        }

        h1,
        h2 {
            line-height: 1.2;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 32px;
        }

        h2 {
            margin: 0 0 9px;
            font-size: 20px;
        }

        p,
        li {
            line-height: 1.6;
        }

        .lead {
            margin: 0 0 28px;
            color: #6e7177;
            font-size: 17px;
        }

        .components,
        .option,
        .final-steps {
            margin-top: 24px;
            padding: 20px;
            background: #f7f8f9;
            border: 1px solid #eceef0;
            border-radius: 12px;
        }

        .components ul,
        .final-steps ol {
            margin: 10px 0 0;
            padding-left: 23px;
        }

        .option p:last-child,
        .final-steps p:last-child {
            margin-bottom: 0;
        }

        .danger {
            background: #fff5f5;
            border-color: #efcccc;
        }

        .warning {
            color: #8b2929;
            font-weight: 700;
        }

        .field {
            margin-top: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 650;
        }

        input[type="text"] {
            width: 100%;
            min-height: 48px;
            padding: 10px 12px;
            border: 1px solid #cfd1d5;
            border-radius: 9px;
            background: white;
            color: #202124;
            font: inherit;
        }

        input[type="text"]:focus {
            border-color: #202124;
            outline: 2px solid rgba(32, 33, 36, .16);
            outline-offset: 1px;
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
            transition: transform .12s ease, opacity .12s ease;
        }

        .button:hover {
            opacity: .9;
            transform: translateY(-1px);
        }

        .button.secondary {
            background: #eceef1;
            color: #202124;
        }

        .button.danger-button {
            background: #9d2d2d;
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 24px;
        }

        .status-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 24px 0;
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
            background: #def3e5;
            color: #17703b;
            font-size: 13px;
            font-weight: bold;
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
            margin-top: 24px;
            padding: 22px;
            background: #eff9f2;
            border: 1px solid #d4eadb;
            border-radius: 12px;
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

        code {
            padding: 2px 5px;
            border-radius: 5px;
            background: #eceef1;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
            font-size: .9em;
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
        <div class="brand-icon" aria-hidden="true">&#128202;</div>

        <div class="brand-text">
            <strong>IO200 Analytics</strong>
            <span>Avinstallation</span>
        </div>
    </div>

    <div class="card">
        <div class="card-main">
            <?php if (!$authenticated): ?>
                <div class="auth-box">
                    <div class="auth-symbol" aria-hidden="true">&#128274;</div>

                    <h1>Admininloggning krävs</h1>

                    <p class="lead">
                        IO200 Analytics använder din befintliga
                        IO200-administratörsinloggning.
                    </p>

                    <p>
                        Logga in i IO200 Admin och kom sedan tillbaka hit.
                        Avinstalleraren behöver inga egna användarkonton eller
                        lösenord.
                    </p>

                    <div class="button-row">
                        <a
                            class="button"
                            href="/admin/"
                            target="_blank"
                            rel="noopener"
                        >
                            Öppna IO200 Admin
                        </a>

                        <a
                            class="button secondary"
                            href="<?= h(currentUninstallerUrl()) ?>"
                        >
                            Jag är inloggad – försök igen
                        </a>
                    </div>

                    <p class="hint">
                        Admin öppnas i en ny flik så att avinstalleraren kan
                        ligga kvar här.
                    </p>
                </div>
            <?php else: ?>
                <h1>Avinstallera Analytics</h1>

                <p class="lead">
                    Välj om analysdata ska sparas för en framtida installation
                    eller tas bort permanent.
                </p>

                <div class="status-row">
                    <span class="status-icon" aria-hidden="true">&#10003;</span>
                    <span>IO200-admin autentiserad</span>
                </div>

                <?php if ($error): ?>
                    <div class="error-box" role="alert">
                        <?= h($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($dataDeleted): ?>
                    <div class="success" role="status">
                        <h2>Analytics-data har tagits bort</h2>
                        <p>
                            Tabellen <code>ioa_events</code> har tagits bort.
                            Inga IO200-kärntabeller eller filer har ändrats.
                        </p>
                    </div>

                    <div class="final-steps">
                        <h2>Slutför avinstallationen manuellt</h2>
                        <ol>
                            <li>
                                Ta bort IO200 Analytics script-tagg från
                                IO200 Code Injection.
                            </li>
                            <li>
                                Radera katalogen
                                <code>/storage/custom/io200-analytics/</code>.
                            </li>
                        </ol>
                    </div>
                <?php else: ?>
                    <div class="components">
                        <h2>Det här ingår i IO200 Analytics</h2>
                        <ul>
                            <li>
                                Pluginfiler under
                                <code>/storage/custom/io200-analytics/</code>
                            </li>
                            <li>
                                Referensen till <code>analytics.js</code> i
                                IO200 Code Injection
                            </li>
                            <li>
                                Analytics-tabellen <code>ioa_events</code>
                            </li>
                        </ul>
                    </div>

                    <section class="option">
                        <h2>1. Behåll analytics-data</h2>
                        <p>
                            Databasen ändras inte. Historiken i
                            <code>ioa_events</code> kan återanvändas om IO200
                            Analytics installeras igen senare.
                        </p>
                        <p>
                            Ta bort IOA:s script-tagg från IO200 Code Injection
                            och radera sedan katalogen
                            <code>/storage/custom/io200-analytics/</code>
                            manuellt.
                        </p>
                    </section>

                    <section class="option danger">
                        <h2>2. Radera analytics-data permanent</h2>
                        <p class="warning">
                            Detta tar permanent bort all insamlad IO200
                            Analytics-data och kan inte ångras.
                        </p>

                        <?php if ($dbConnected && $tableExists): ?>
                            <p>
                                Endast tabellen <code>ioa_events</code> tas
                                bort. IO200:s kärntabeller påverkas inte.
                            </p>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?= h($_SESSION['ioa_uninstaller_csrf']) ?>"
                                >
                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete_analytics_data"
                                >

                                <div class="field">
                                    <label for="confirmation">
                                        Skriv DELETE för att bekräfta
                                    </label>
                                    <input
                                        id="confirmation"
                                        name="confirmation"
                                        type="text"
                                        required
                                        pattern="DELETE"
                                        spellcheck="false"
                                        autocapitalize="characters"
                                    >
                                </div>

                                <div class="button-row">
                                    <button
                                        class="button danger-button"
                                        type="submit"
                                    >
                                        Radera analytics-data permanent
                                    </button>
                                </div>
                            </form>
                        <?php elseif ($dbConnected): ?>
                            <p>
                                Tabellen <code>ioa_events</code> finns inte.
                                Ingen analytics-data behöver tas bort.
                            </p>

                            <div class="final-steps">
                                <h2>Slutför avinstallationen manuellt</h2>
                                <ol>
                                    <li>
                                        Ta bort IO200 Analytics script-tagg
                                        från IO200 Code Injection.
                                    </li>
                                    <li>
                                        Radera katalogen
                                        <code>/storage/custom/io200-analytics/</code>.
                                    </li>
                                </ol>
                            </div>
                        <?php else: ?>
                            <p>
                                Databasstatus kunde inte kontrolleras. Ingen
                                data har tagits bort.
                            </p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="footer">
            IO200 Analytics · endast uttryckligen ägda dataobjekt kan tas bort
        </div>
    </div>
</div>
</body>
</html>
