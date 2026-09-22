<?php

require_once __DIR__ . '/../../../app/Auth/Auth.php';
require_once __DIR__ . '/../../../app/Domain/PartUnit.php';

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
        'parts' => []
    ]);

    exit;
}

$statement = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        p.sku,
        p.selling_price,
        p.reorder_level,
        p.tax_rate,
        p.hsn_code,
        p.unit,
        COALESCE(i.quantity, 0) AS stock_quantity
    FROM parts p
    LEFT JOIN inventory i
        ON i.part_id = p.id
        AND i.branch_id = :branch_id
    WHERE p.organization_id = :organization_id
      AND p.status = 'active'
      AND (
          p.name ILIKE :query
          OR p.sku ILIKE :query
      )
    ORDER BY
        CASE WHEN p.name ILIKE :prefix OR p.sku ILIKE :prefix THEN 0 ELSE 1 END,
        p.name
    LIMIT 20
");

$searchQuery = '%' . $query . '%';
$prefixQuery = $query . '%';

$statement->execute([
    'organization_id' => $user['organization_id'],
    'branch_id' => $user['branch_id'],
    'query' => $searchQuery,
    'prefix' => $prefixQuery
]);

$parts = $statement->fetchAll(PDO::FETCH_ASSOC);

// Computed server-side so every consumer of this endpoint (job-card
// add-part search, the Parts page's own live search) gets the same
// short label and whole-number rule without re-implementing the
// PART_UNITS lookup in JS.
foreach ($parts as &$part) {
    $part['unit_short'] = part_unit_short($part['unit']);
    $part['unit_is_whole'] = part_unit_is_whole($part['unit']);
}
unset($part);

echo json_encode([
    'parts' => $parts
]);
