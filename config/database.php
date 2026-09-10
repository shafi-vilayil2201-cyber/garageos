<?php

$host = '127.0.0.1';
$port = '5432';
$dbname = 'garageos';
$user = 'garageos_user';
$password = 'garageos_dev_2026';

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

try {
    $pdo = new PDO($dsn, $user, $password);

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    return $pdo;

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
