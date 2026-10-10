<?php
// Exercise the production helper against the supplied IO200 JWT implementation.
$root = sys_get_temp_dir() . '/ioa-auth-test-' . bin2hex(random_bytes(8));
$addon = $root . '/storage/custom/ioa';
mkdir($addon, 0700, true);
mkdir($root . '/admin/sys', 0700, true);
function copyAuthFixture(string $from, string $to): void {
    foreach (scandir($from) as $name) {
        if ($name === '.' || $name === '..') continue;
        if (is_dir($from . '/' . $name)) {
            mkdir($to . '/' . $name);
            copyAuthFixture($from . '/' . $name, $to . '/' . $name);
        } else copy($from . '/' . $name, $to . '/' . $name);
    }
}
function removeAuthFixture(string $path): void {
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child)) removeAuthFixture($child); else unlink($child);
    }
    rmdir($path);
}
try {
    copyAuthFixture(__DIR__ . '/../References/admin/sys', $root . '/admin/sys');
    copy(__DIR__ . '/../admin-classification.php', $addon . '/admin-classification.php');
    require $addon . '/admin-classification.php';
    define('CMS_SECRETKEY', 'ioa-regression-secret');
    require $root . '/admin/sys/Autoload.php';
    $jwt = new JWTService;
    $claims = ['type' => 'refresh', 'mail' => 'admin@example.com', 'exp' => time() + 3600];
    $cases = [
        [$claims, CMS_SECRETKEY, 1],
        [array_replace($claims, ['exp' => time() - 1]), CMS_SECRETKEY, 0],
        [$claims, 'wrong-signing-key', 0],
        [array_replace($claims, ['type' => 'access']), CMS_SECRETKEY, 0],
        [array_diff_key($claims, ['exp' => true]), CMS_SECRETKEY, 0],
        [array_replace($claims, ['mail' => '']), CMS_SECRETKEY, 0],
    ];
    foreach ($cases as [$payload, $key, $expected]) {
        $token = $jwt->encode($payload, $key, 'HS256');
        if (ioaClassifyAdmin(['refreshtoken' => $token]) !== $expected) throw new RuntimeException('Classification failed');
    }
    foreach ([[], ['refreshtoken' => 'invalid'], ['refreshtoken' => 'eyJhbGciOiJub25lIn0.eyJ0eXBlIjoicmVmcmVzaCJ9.'], ['refreshtoken' => []], ['is_admin' => 1]] as $cookies) {
        if (ioaClassifyAdmin($cookies) !== 0) throw new RuntimeException('Invalid input accepted');
    }
    rename($root . '/admin/sys', $root . '/admin/unavailable');
    if (ioaClassifyAdmin(['refreshtoken' => 'invalid']) !== 0) throw new RuntimeException('Unavailable auth failed');
    $collector = file_get_contents(__DIR__ . '/../collect.php');
    if (strpos($collector, '$isAdmin = ioaClassifyAdmin($_COOKIE);') === false
        || strpos($collector, "$" . "data['is_admin']") !== false
        || strpos($collector, '$stmt->execute();') === false) throw new RuntimeException('Collector compatibility changed');
    echo "Admin classification regression cases passed\n";
} finally {
    removeAuthFixture($root);
}
