<?php

// A CSRF token independent of the app's own cookie-based auth session —
// this rides on PHP's native session purely to hold that one value.

require_once __DIR__ . '/Session.php';

garageos_start_session();

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $submitted = $_POST['_csrf'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(419);
        exit('Your session expired. Go back and try again.');
    }
}
