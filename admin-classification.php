<?php

// Classification only. Never use this result to authorize dashboard/API access.
function ioaClassifyAdmin(array $cookies): int
{
    $token = $cookies['refreshtoken'] ?? null;
    if (!is_string($token) || $token === '') return 0;

    try {
        $autoload = __DIR__ . '/../../../admin/sys/Autoload.php';
        if (!is_file($autoload)) return 0;
        require_once $autoload;
        $service = new AuthenticationService(CMS_SECRETKEY, CMS_SECRETKEY, 'HS256', dirname(__DIR__, 3));
        // readUserToken verifies the signature without issuing cookies or tokens.
        $claims = $service->readUserToken($token);
        return ioaAdminClaimsValid($claims) ? 1 : 0;
    } catch (Throwable $e) {
        // Authentication failure must not interrupt collection or expose credentials.
        return 0;
    }
}

function ioaAdminClaimsValid($claims): bool
{
    return is_array($claims)
        && ($claims['type'] ?? null) === 'refresh'
        && is_string($claims['mail'] ?? null)
        && filter_var($claims['mail'], FILTER_VALIDATE_EMAIL) !== false
        && is_int($claims['exp'] ?? null)
        && $claims['exp'] > time();
}
