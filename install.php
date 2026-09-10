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
// Current schema requirements. Extend this list when application needs change.
// Existing incompatible definitions are reported, never silently converted.
// --------------------------------------------------

function ioaSchemaRequirements(): array
{
    return [
        'id' => ['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', '/^bigint(?:\(\d+\))? unsigned$/i', false],
        'created_at' => ['DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', '/^datetime$/i', false],
        'event_type' => ['VARCHAR(50) NOT NULL', '/^varchar\((\d+)\)$/i', false, 50],
        'page_path' => ['VARCHAR(500) DEFAULT NULL', '/^varchar\((\d+)\)$/i', true, 500],
        'photo_id' => ['BIGINT UNSIGNED DEFAULT NULL', '/^bigint(?:\(\d+\))? unsigned$/i', true],
        'image_url' => ['TEXT DEFAULT NULL', '/^(?:mediumtext|longtext|text)$/i', true],
        'download_url' => ['TEXT DEFAULT NULL', '/^(?:mediumtext|longtext|text)$/i', true],
        'batch_data' => ['JSON DEFAULT NULL', '/^(?:json|longtext)$/i', true],
        'session_id' => ['VARCHAR(64) DEFAULT NULL', '/^varchar\((\d+)\)$/i', true, 64],
        'is_admin' => ['TINYINT(1) NOT NULL DEFAULT 0', '/^tinyint(?:\(\d+\))?(?: unsigned)?$/i', false],
    ];
}

function ioaRequiredIndexes(): array
{
    return ['idx_event_type' => 'event_type', 'idx_photo_id' => 'photo_id',
        'idx_created_at' => 'created_at', 'idx_session_id' => 'session_id'];
}

function ioaInspectSchema(mysqli $db): array
{
    $requirements = ioaSchemaRequirements();
    $table = $db->query("SELECT TABLE_TYPE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ioa_events'")->fetch_assoc();
    if (!$table) {
        $definitions = [];
        foreach ($requirements as $name => $requirement) {
            $definitions[] = "`{$name}` {$requirement[0]}";
        }
        $definitions[] = 'PRIMARY KEY (`id`)';
        foreach (ioaRequiredIndexes() as $name => $column) {
            $definitions[] = "INDEX `{$name}` (`{$column}`)";
        }
        return ['exists' => false, 'issues' => [], 'operations' => [
            'CREATE TABLE `ioa_events` (' . implode(', ', $definitions) .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ]];
    }
    if ($table['TABLE_TYPE'] !== 'BASE TABLE') {
        return ['exists' => true, 'issues' => ['ioa_events is not a base table.'], 'operations' => []];
    }

    $columns = [];
    foreach ($db->query('SHOW FULL COLUMNS FROM `ioa_events`') as $column) {
        $columns[$column['Field']] = $column;
    }
    $indexes = [];
    foreach ($db->query('SHOW INDEX FROM `ioa_events`') as $index) {
        $indexes[$index['Key_name']][(int)$index['Seq_in_index']] = $index;
    }
    $issues = [];
    $additions = [];
    $hasRows = $db->query('SELECT 1 FROM `ioa_events` LIMIT 1')->num_rows > 0;
    foreach ($requirements as $name => $requirement) {
        if (!isset($columns[$name])) {
            if ($hasRows && in_array($name, ['id', 'created_at', 'event_type'], true)) {
                $issues[] = "Missing {$name} in a populated table; historical values cannot be safely reconstructed.";
            } else {
                $additions[] = "ADD COLUMN `{$name}` {$requirement[0]}";
            }
            continue;
        }
        $column = $columns[$name];
        $matches = [];
        $compatible = preg_match($requirement[1], $column['Type'], $matches)
            && (!isset($requirement[3]) || (int)$matches[1] >= $requirement[3])
            && $column['Null'] === ($requirement[2] ? 'YES' : 'NO')
            && !preg_match('/(?:VIRTUAL|STORED|PERSISTENT) GENERATED/i', $column['Extra']);
        if ($name === 'id') {
            $compatible = $compatible && stripos($column['Extra'], 'auto_increment') !== false;
        } elseif (stripos($column['Extra'], 'auto_increment') !== false) {
            $compatible = false;
        }
        if ($name === 'created_at') {
            $compatible = $compatible && preg_match('/^current_timestamp(?:\(\))?$/i', (string)$column['Default'])
                && stripos($column['Extra'], 'on update') === false;
        } elseif ($name === 'is_admin') {
            $compatible = $compatible && (string)$column['Default'] === '0';
        } elseif ($requirement[2]) {
            $compatible = $compatible && $column['Default'] === null;
        }
        if (!$compatible) $issues[] = "Incompatible definition for {$name}; no automatic conversion was attempted.";
    }
    // Extra required columns could prevent the collector from inserting events.
    foreach ($columns as $name => $column) {
        if (!isset($requirements[$name]) && $column['Null'] === 'NO' && $column['Default'] === null
            && !preg_match('/auto_increment|(?:VIRTUAL|STORED|PERSISTENT) GENERATED/i', $column['Extra'])) {
            $issues[] = "Extra column {$name} requires a value the collector does not supply.";
        }
    }

    if (isset($indexes['PRIMARY'])) {
        if (count($indexes['PRIMARY']) !== 1 || $indexes['PRIMARY'][1]['Column_name'] !== 'id') {
            $issues[] = 'Existing primary key is incompatible; it will not be replaced.';
        }
    } else {
        $additions[] = 'ADD PRIMARY KEY (`id`)';
    }
    foreach ($indexes as $name => $parts) {
        if ((int)$parts[1]['Non_unique'] === 0
            && !(count($parts) === 1 && $parts[1]['Column_name'] === 'id')) {
            $issues[] = "Unique index {$name} may reject repeated analytics events; it will not be removed automatically.";
        }
    }
    foreach (ioaRequiredIndexes() as $name => $column) {
        $covered = false;
        foreach ($indexes as $parts) {
            if ($parts[1]['Column_name'] === $column && $parts[1]['Sub_part'] === null
                && strtoupper($parts[1]['Index_type']) === 'BTREE'
                && ($parts[1]['Visible'] ?? 'YES') === 'YES'
                && ($parts[1]['Ignored'] ?? 'NO') === 'NO') $covered = true;
        }
        if (!$covered) {
            if (isset($indexes[$name])) {
                $issues[] = "Index name {$name} is already used by an incompatible index.";
            } else {
                $additions[] = "ADD INDEX `{$name}` (`{$column}`)";
            }
        }
    }
    return ['exists' => true, 'issues' => $issues, 'operations' => $additions
        ? ['ALTER TABLE `ioa_events` ' . implode(', ', $additions)] : []];
}

// --------------------------------------------------
// Inspect on GET; apply only on authenticated, CSRF-validated POST.
// --------------------------------------------------

$schema = null;
$success = null;
$error = null;
$diagnostic = null;
$mysqli = null;
$lockHeld = false;

if ($authenticated) {
    try {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) {
            $csrf = $_POST['csrf'] ?? '';
            if (!is_string($csrf) || !$csrf || !hash_equals($_SESSION['ioa_installer_csrf'], $csrf)) {
                throw new RuntimeException('Invalid setup request. Reload this page and try again.');
            }
        }
        $mysqli = new mysqli(CMS_DB_HOSTNAME, CMS_DB_USERNAME, CMS_DB_PASSWORD, CMS_DB_DATABASE);
        $mysqli->set_charset('utf8mb4');
        if ($isPost) {
            // Serialize setup requests for this database; release on connection close too.
            $lockName = 'ioa_setup_' . substr(hash('sha256', CMS_DB_DATABASE), 0, 40);
            $stmt = $mysqli->prepare('SELECT GET_LOCK(?, 5)');
            $stmt->bind_param('s', $lockName);
            $stmt->execute();
            $stmt->bind_result($lockResult);
            $stmt->fetch();
            $stmt->close();
            $lockHeld = (int)$lockResult === 1;
            if (!$lockHeld) throw new RuntimeException('Another setup is running. Try again shortly.');
        }
        $schema = ioaInspectSchema($mysqli);
        if ($schema['issues']) throw new RuntimeException(implode("\n", $schema['issues']));
        if ($isPost && $schema['operations']) {
            $wasInstalled = $schema['exists'];
            foreach ($schema['operations'] as $sql) $mysqli->query($sql);
            $schema = ioaInspectSchema($mysqli);
            if ($schema['issues'] || $schema['operations']) {
                throw new RuntimeException('Schema verification did not pass after setup. ' . implode(' ', $schema['issues']));
            }
            $success = $wasInstalled
                ? 'IO200 Analytics was updated successfully.'
                : 'IO200 Analytics was installed successfully.';
        } elseif (!$schema['operations']) {
            $success = 'IO200 Analytics is ready to use.';
        }
    } catch (Throwable $e) {
        error_log('[IO200 Analytics] Installer error: ' . $e->getMessage());
        $error = 'IO200 Analytics could not complete the database setup.';
        // SQL/connection exceptions may include credentials or event data: log only.
        $diagnostic = $e instanceof mysqli_sql_exception
            ? 'Database operation failed (code ' . (int)$e->getCode() . '). See the server error log for details.'
            : $e->getMessage();
    } finally {
        if ($mysqli instanceof mysqli) $mysqli->close();
    }
}

?>
<!doctype html>
<html lang="en">

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

        .snippet-box {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
            margin-top: 15px;
            padding-right: 12px;
            background: #202124;
            border-radius: 9px;
        }

        .snippet-box code {
            min-width: 0;
            margin: 0;
            white-space: pre;
        }

        .snippet-copy {
            padding: 5px 9px;
            border: 1px solid #62666d;
            border-radius: 5px;
            background: transparent;
            color: #e1e3e6;
            white-space: nowrap;
            min-width: 76px;
            font: inherit;
            font-size: 12px;
            cursor: pointer;
        }

        .snippet-copy:focus-visible {
            outline: 2px solid #e1e3e6;
            outline-offset: 2px;
        }

        .snippet-copy-status {
            color: #74777c;
            font-size: 12px;
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
            <span>Installation and updates</span>
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

                <h1>Install or update Analytics</h1>
                <p class="lead">Check that IO200 Analytics is ready after installation or replacing its application files. Existing analytics data is preserved.</p>

                <?php if ($error): ?>
                    <div class="error-box"><?= h($error) ?></div>
                    <details>
                        <summary>Diagnostic details</summary>
                        <p><?= nl2br(h($diagnostic)) ?></p>
                    </details>
                    <div class="button-row">
                        <a class="button secondary" href="<?= h(currentInstallerUrl()) ?>">Check again</a>
                    </div>
                <?php elseif ($success): ?>
                    <div class="success">
                        <div class="success-title"><?= h($success) ?></div>
                        <div class="button-row">
                            <a class="button" href="dashboard.php">Open Analytics</a>
                            <a class="button secondary" href="/admin">Open IO200 Admin</a>
                        </div>
                    </div>
                    <p class="hint">For a first installation, add the tracking script in IO200 Settings → Code Injection. When updating, keep that script and change its version query string to the release you uploaded, as described in README.md.</p>
                    <div class="snippet-box">
                        <code id="injection-snippet">&lt;script src="/storage/custom/io200-analytics/analytics.js?v=1.0.0"&gt;&lt;/script&gt;</code>
                        <button type="button" class="snippet-copy" id="copy-snippet" aria-label="Copy Code Injection script" hidden>Copy</button>
                    </div>
                    <span id="snippet-copy-status" class="snippet-copy-status" role="status"></span>
                <?php else: ?>
                    <p><?= $schema['exists']
                        ? 'A database update is needed. Run setup to apply the supported changes and keep your existing analytics data.'
                        : 'Run setup to prepare IO200 Analytics for first use.' ?></p>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['ioa_installer_csrf']) ?>">
                        <button class="button" type="submit"><?= $schema['exists'] ? 'Update IO200 Analytics' : 'Install IO200 Analytics' ?></button>
                    </form>
                <?php endif; ?>

            <?php endif; ?>

        </div>

        <div class="footer">
            IO200 Analytics · isolated from IO200 core · no separate database credentials
        </div>

    </div>

</div>

<script>
(() => {
    const button = document.getElementById('copy-snippet');
    const snippet = document.getElementById('injection-snippet');
    const status = document.getElementById('snippet-copy-status');
    if (!button || !snippet || !status) return;
    button.hidden = false;
    let resetTimer;

    function fallbackCopy(value) {
        const field = document.createElement('textarea');
        field.value = value;
        field.readOnly = true;
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        try {
            field.select();
            return document.execCommand('copy');
        } catch (error) {
            return false;
        } finally {
            field.remove();
            button.focus({preventScroll: true});
        }
    }

    button.addEventListener('click', async () => {
        clearTimeout(resetTimer);
        const value = snippet.textContent;
        button.disabled = true;
        let copied = false;
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(value);
                copied = true;
            }
        } catch (error) {
            // Permission denial and insecure contexts can use the fallback below.
        }
        if (!copied) copied = fallbackCopy(value);
        button.disabled = false;
        button.focus({preventScroll: true});
        button.textContent = copied ? 'Copied ✓' : 'Copy';
        status.textContent = copied ? 'Copied ✓' : 'Copy unavailable. Select the script and copy it manually.';
        if (copied) {
            resetTimer = setTimeout(() => {
                button.textContent = 'Copy';
                status.textContent = '';
            }, 2000);
        }
    });
})();
</script>
</body>
</html>
