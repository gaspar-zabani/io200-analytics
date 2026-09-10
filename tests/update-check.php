<?php

// Run with: php tests/update-check.php (no IO200 bootstrap or network required).
require_once __DIR__ . '/../update-check.php';

function check($condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$ordered = ['1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta',
    '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0', '1.1.0-beta.3',
    '1.1.0-beta.3.dev', '1.1.0-beta.4', '1.1.0', '1.2.0', '1.10.0', '2.0.0'];
foreach ($ordered as $i => $left) {
    foreach ($ordered as $j => $right) {
        check(ioaCompareVersions($left, $right) === ($i <=> $j), "$left vs $right");
    }
}
check(ioaCompareVersions('1.2.3+build.1', '1.2.3+build.2') === 0, 'Ignore build metadata');
check(ioaCompareVersions('1.0.0-Z', '1.0.0-a') === -1, 'ASCII prerelease order');
check(ioaCompareVersions('99999999999999999999.0.0', '100000000000000000000.0.0') === -1, 'Large numbers');
foreach (['v1.2.3', '1.2', '01.2.3', '1.2.3-beta.01', '1.2.3-', '1.2.3+', "1.2.3\n", '', null, 123] as $invalid) {
    check(ioaParseVersion($invalid) === null, 'Reject malformed version');
}
foreach (['null', '[]', '"1.2.3"', '{"version":123}', '{"version":"v1.2.3"}', '{}', '{', str_repeat(' ', 16385)] as $invalid) {
    check(ioaManifestVersion($invalid) === null, 'Reject malformed manifest');
}
check(ioaManifestVersion('{"version":"1.2.3-beta.2"}') === '1.2.3-beta.2', 'Version-only manifest');
check(ioaManifestVersion('{"version":"1.2.3","package_url":"ignored"}') === '1.2.3', 'Unused fields');
check(ioaParseVersion(require __DIR__ . '/../version.php') !== null, 'Installed version is valid');
echo "Version and manifest checks passed.\n";
