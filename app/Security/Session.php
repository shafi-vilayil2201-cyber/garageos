<?php

// Starts PHP's native session (which only carries the CSRF token and flash
// messages — logins use the garageos_session cookie in Auth.php) with the
// same cookie protections as the login cookie: not readable from JavaScript,
// not sent on cross-site requests, and HTTPS-only whenever the site is served
// over HTTPS (local http:// dev keeps working).
function garageos_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps
    ]);

    session_start();
}
