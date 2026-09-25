<?php

// Global exception & error handler — registered once from database.php
// (the one file every page loads), catches any uncaught exception or
// fatal error and renders a styled error page instead of a raw 500.
//
// In production (APP_ENV=production), only a friendly message shows;
// in dev, the full exception details are rendered so the developer
// can fix things without tailing the error log.

function garageos_handle_exception(Throwable $e): void
{
    // Always log the real error, regardless of environment.
    error_log('[GarageOS] Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // Roll back any dangling transaction — PDO exceptions thrown mid-
    // transaction leave it open, and PHP's implicit rollback on script
    // end doesn't fire reliably with output buffering or custom error
    // handlers in play. The global $pdo isn't always available (e.g.
    // if the error happened *during* database.php itself), so this is
    // best-effort only.
    try {
        global $pdo;
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $ignore) {
        // Already logging the primary error — a failed rollback isn't
        // worth masking it.
    }

    // If output was already partially sent (rare, since most pages do
    // all their DB work before rendering), wipe whatever's in the
    // buffer so the error page renders cleanly instead of appending
    // to a half-painted layout.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Don't try to render HTML for AJAX/fetch requests — a JSON body
    // is more useful for the caller, and avoids breaking client-side
    // JSON.parse() on what it expects to be an API response.
    if (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Something went wrong. Please try again.']);
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $isDev = (env('APP_ENV', 'production') !== 'production');

    require __DIR__ . '/../app/View/error.php';
}

// PHP errors (notices, warnings, deprecations) that aren't exceptions
// by default — promote them to ErrorException so they flow through the
// same handler above. This means a stray "undefined variable" in dev
// shows the nice error page instead of a white screen with a one-liner,
// and in production it logs + shows the friendly page instead of nothing.
function garageos_handle_error(int $severity, string $message, string $file, int $line): bool
{
    // Honour the error_reporting() level — suppressed errors (via @)
    // should stay suppressed, not promoted to exceptions.
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
}

// Fatal errors (out-of-memory, max execution time) bypass both
// set_error_handler and set_exception_handler — the only way to catch
// them is a shutdown function that checks error_get_last().
function garageos_handle_shutdown(): void
{
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
        garageos_handle_exception(
            new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
        );
    }
}

set_exception_handler('garageos_handle_exception');
set_error_handler('garageos_handle_error');
register_shutdown_function('garageos_handle_shutdown');
