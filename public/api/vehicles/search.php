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
        'vehicles' => []
    ]);

    exit;
}

$statement = $pdo->prepare("
    SELECT
        v.id,
        v.registration_no,
        v.make,
        v.model,
        v.year,
        c.id AS customer_id,
        c.name AS customer_name,
        c.phone AS customer_phone
    FROM vehicles v
    INNER JOIN customers c ON c.id = v.customer_id
    WHERE v.organization_id = :organization_id
      AND (
          v.registration_no ILIKE :query
          OR c.phone ILIKE :query
          OR c.name ILIKE :query
      )
    ORDER BY v.created_at DESC
    LIMIT 10
");

$searchQuery = '%' . $query . '%';

$statement->execute([
    'organization_id' => $user['organization_id'],
    'query' => $searchQuery
]);

$vehicles = $statement->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'vehicles' => $vehicles
]);
