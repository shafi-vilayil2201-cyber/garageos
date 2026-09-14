<?php

require_once __DIR__ . '/../../../app/Auth/Auth.php';

$pdo = require __DIR__ . '/../../../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

header('Content-Type: application/json');

if (!$user) {
    http_response_code(401);

    echo json_encode([
        'error' => 'Unauthenticated'
    ]);

    exit;
}

$query = trim($_GET['q'] ?? '');

if ($query === '') {
    echo json_encode([
        'customers' => []
    ]);

    exit;
}

$statement = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.code,
        c.phone,
        (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id) AS vehicle_count
    FROM customers c
    WHERE c.organization_id = :organization_id
      AND (
          c.name ILIKE :query
          OR c.phone ILIKE :query
          OR c.code ILIKE :query
      )
    ORDER BY
        CASE WHEN c.name ILIKE :prefix OR c.phone ILIKE :prefix THEN 0 ELSE 1 END,
        c.name
    LIMIT 20
");

$searchQuery = '%' . $query . '%';
$prefixQuery = $query . '%';

$statement->execute([
    'organization_id' => $user['organization_id'],
    'query' => $searchQuery,
    'prefix' => $prefixQuery
]);

$customers = $statement->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'customers' => $customers
]);
