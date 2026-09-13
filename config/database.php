<?php

require_once __DIR__ . '/../app/Support/Env.php';

load_env(__DIR__ . '/../.env');

// Defaults match the long-standing local dev setup (see README) so a
// checkout with no .env still runs — a real install always provides its
// own .env with its own generated password.
$host = env('DB_HOST', '127.0.0.1');
$port = env('DB_PORT', '5432');
$dbname = env('DB_DATABASE', 'garageos');
$user = env('DB_USERNAME', 'garageos_user');
$password = env('DB_PASSWORD', 'garageos_dev_2026');

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

try {
    $pdo = new PDO($dsn, $user, $password);

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    return $pdo;

} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Something went wrong. Please try again shortly.");
}
