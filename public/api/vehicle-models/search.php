<?php

require_once __DIR__ . '/../../../app/Auth/Auth.php';
require_once __DIR__ . '/../../../app/Domain/VehicleCatalog.php';

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
        'models' => []
    ]);

    exit;
}

// The shop's own history first — most relevant to what they'll actually
// see again — then the built-in common-makes list fills any remaining
// slots with suggestions the shop hasn't serviced before.
$statement = $pdo->prepare("
    SELECT make, model, COUNT(*) AS freq
    FROM vehicles
    WHERE organization_id = :organization_id
      AND (make ILIKE :query OR model ILIKE :query OR (make || ' ' || model) ILIKE :query)
    GROUP BY make, model
    ORDER BY freq DESC, make, model
    LIMIT 6
");
$statement->execute([
    'organization_id' => $user['organization_id'],
    'query' => '%' . $query . '%'
]);
$models = $statement->fetchAll(PDO::FETCH_ASSOC);

$seen = [];

foreach ($models as $model) {
    $seen[strtolower($model['make'] . '|' . $model['model'])] = true;
}

foreach (vehicle_catalog_search($query) as $candidate) {

    if (count($models) >= 10) {
        break;
    }

    $key = strtolower($candidate['make'] . '|' . $candidate['model']);

    if (isset($seen[$key])) {
        continue;
    }

    $seen[$key] = true;
    $models[] = $candidate;
}

echo json_encode([
    'models' => array_map(fn($m) => ['make' => $m['make'], 'model' => $m['model']], $models)
]);
