<?php

// Every page requires this file first, so this is the one place that
// guarantees it runs before any page computes "today"/"now". Without
// it, PHP defaults to UTC while Postgres runs in Asia/Kolkata (see
// organizations.timezone) — between 12:00am and 5:29am IST, PHP's
// date('Y-m-d')/date('Y-m') would report the previous calendar day or
// month, silently defaulting things like Finance/Payroll's "this
// month" or a new expense's date to the wrong day during that window.
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/Support/Env.php';

load_env(__DIR__ . '/../.env');

// Global error handler — registered before anything else so even a
// database connection failure (the very next thing that happens) renders
// a styled error page instead of a raw die(). Catches uncaught
// exceptions, promotes PHP errors to exceptions, and handles fatal
// shutdown errors. See ErrorHandler.php for details.
require_once __DIR__ . '/../app/Support/ErrorHandler.php';

// Defaults match the long-standing local dev setup (see README) so a
// checkout with no .env still runs — a real install always provides its
// own .env with its own generated password.
$host = env('DB_HOST', '127.0.0.1');
$port = env('DB_PORT', '5432');
$dbname = env('DB_DATABASE', 'garageos');
$user = env('DB_USERNAME', 'garageos_user');
$password = env('DB_PASSWORD', 'garageos_dev_2026');

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

$pdo = new PDO($dsn, $user, $password);

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

return $pdo;
