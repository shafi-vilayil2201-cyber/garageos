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
        'services' => []
    ]);

    exit;
}

$statement = $pdo->prepare("
    SELECT
        s.id,
        s.name,
        s.code,
        s.standard_price
    FROM services s
    WHERE s.organization_id = :organization_id
      AND s.status = 'active'
      AND (
          s.name ILIKE :query
          OR s.code ILIKE :query
      )
    ORDER BY
        CASE WHEN s.name ILIKE :prefix OR s.code ILIKE :prefix THEN 0 ELSE 1 END,
        s.name
    LIMIT 10
");

$searchQuery = '%' . $query . '%';
$prefixQuery = $query . '%';

$statement->execute([
    'organization_id' => $user['organization_id'],
    'query' => $searchQuery,
    'prefix' => $prefixQuery
]);

$services = $statement->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'services' => $services
]);
