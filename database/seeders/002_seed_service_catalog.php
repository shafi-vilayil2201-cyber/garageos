<?php

$pdo = require __DIR__ . '/../../config/database.php';

$organizationId = $pdo
    ->query("SELECT id FROM organizations WHERE code = 'DEMO' LIMIT 1")
    ->fetchColumn();

if (!$organizationId) {
    exit("No DEMO organization found. Run 001_create_demo_data.php first.\n");
}

$categories = [
    'PERIODIC' => 'Periodic Maintenance',
    'ELECTRICAL' => 'Electrical',
    'BRAKES' => 'Brakes & Suspension',
    'AC' => 'Air Conditioning'
];

$categoryIds = [];

$statement = $pdo->prepare("
    INSERT INTO service_categories (organization_id, name, code)
    VALUES (:organization_id, :name, :code)
    ON CONFLICT (organization_id, code) DO UPDATE SET name = EXCLUDED.name
    RETURNING id
");

foreach ($categories as $code => $name) {

    $statement->execute([
        'organization_id' => $organizationId,
        'name' => $name,
        'code' => $code
    ]);

    $categoryIds[$code] = $statement->fetchColumn();
}

$services = [
    ['PERIODIC', 'OIL_CHANGE', 'Oil & filter change', 899, 30],
    ['PERIODIC', 'GENERAL_SERVICE', 'General service', 1999, 90],
    ['BRAKES', 'BRAKE_PAD', 'Brake pad replacement (per axle)', 1499, 45],
    ['BRAKES', 'WHEEL_ALIGNMENT', 'Wheel alignment & balancing', 799, 40],
    ['ELECTRICAL', 'BATTERY_REPLACE', 'Battery replacement', 499, 20],
    ['AC', 'AC_SERVICE', 'AC gas top-up & service', 1299, 60]
];

$statement = $pdo->prepare("
    INSERT INTO services (
        organization_id,
        service_category_id,
        name,
        code,
        standard_price,
        estimated_minutes
    )
    VALUES (
        :organization_id,
        :service_category_id,
        :name,
        :code,
        :standard_price,
        :estimated_minutes
    )
    ON CONFLICT (organization_id, code) DO UPDATE SET
        name = EXCLUDED.name,
        standard_price = EXCLUDED.standard_price
");

foreach ($services as [$categoryCode, $code, $name, $price, $minutes]) {

    $statement->execute([
        'organization_id' => $organizationId,
        'service_category_id' => $categoryIds[$categoryCode],
        'name' => $name,
        'code' => $code,
        'standard_price' => $price,
        'estimated_minutes' => $minutes
    ]);
}

echo "Seeded " . count($categories) . " service categories and " . count($services) . " services.\n";
