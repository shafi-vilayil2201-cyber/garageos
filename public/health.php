<?php

// Public health check — deliberately minimal. This is read by monitoring
// (and by the update process before/after a release) and may be hit by
// anyone, so it reports pass/fail only, never connection details, table
// contents, or stack traces. A future admin-only diagnostics view (under
// Settings) is the place for anything more detailed.

require_once __DIR__ . '/../app/Support/Env.php';
require_once __DIR__ . '/../app/Support/Version.php';

load_env(__DIR__ . '/../.env');

header('Content-Type: application/json');

$checks = [
    'php_extensions' => extension_loaded('pdo_pgsql'),
    'database' => false,
    'migrations_applied' => false,
];

try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '5432'),
        env('DB_DATABASE', 'garageos')
    );

    $pdo = new PDO($dsn, env('DB_USERNAME', 'garageos_user'), env('DB_PASSWORD', ''));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $checks['database'] = true;

    $appliedCount = (int) $pdo->query("
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_name = 'migrations'
    ")->fetchColumn();

    if ($appliedCount > 0) {
        $applied = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $onDisk = count(glob(__DIR__ . '/../database/migrations/*.sql'));
        $checks['migrations_applied'] = $applied >= $onDisk;
    }

} catch (Throwable $e) {
    error_log('[health] ' . $e->getMessage());
}

$healthy = !in_array(false, $checks, true);

http_response_code($healthy ? 200 : 503);

echo json_encode([
    'status' => $healthy ? 'ok' : 'error',
    'version' => GARAGEOS_VERSION,
    'checks' => $checks,
]);
