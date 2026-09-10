<?php

$pdo = require __DIR__ . '/../../config/database.php';

$organizationId = $pdo
    ->query("SELECT id FROM organizations WHERE code = 'DEMO' LIMIT 1")
    ->fetchColumn();

$branchId = $pdo
    ->query("SELECT id FROM branches WHERE organization_id = {$organizationId} AND code = 'MAIN' LIMIT 1")
    ->fetchColumn();

if (!$organizationId || !$branchId) {
    exit("No DEMO organization/branch found. Run 001_create_demo_data.php first.\n");
}

// Parts + starting stock

$statement = $pdo->prepare("
    INSERT INTO parts (organization_id, name, sku, cost_price, selling_price, unit, reorder_level)
    VALUES (:organization_id, :name, :sku, :cost_price, :selling_price, :unit, :reorder_level)
    ON CONFLICT (organization_id, sku) DO UPDATE SET name = EXCLUDED.name
    RETURNING id
");

$parts = [
    ['5W-30 Engine Oil (1L)', 'OIL-5W30-1L', 320, 480, 'ltr', 10],
    ['Oil Filter', 'FLT-OIL-STD', 150, 250, 'pcs', 15],
    ['Front Brake Pad Set', 'BRK-PAD-FR', 900, 1450, 'set', 5],
    ['12V Battery 45Ah', 'BAT-45AH', 3200, 4200, 'pcs', 3]
];

$partIds = [];

foreach ($parts as [$name, $sku, $cost, $price, $unit, $reorder]) {

    $statement->execute([
        'organization_id' => $organizationId,
        'name' => $name,
        'sku' => $sku,
        'cost_price' => $cost,
        'selling_price' => $price,
        'unit' => $unit,
        'reorder_level' => $reorder
    ]);

    $partIds[$sku] = $statement->fetchColumn();
}

$stockStatement = $pdo->prepare("
    INSERT INTO inventory (part_id, branch_id, quantity)
    VALUES (:part_id, :branch_id, :quantity)
    ON CONFLICT (part_id, branch_id) DO UPDATE SET quantity = EXCLUDED.quantity
");

$openingStock = [
    'OIL-5W30-1L' => 25,
    'FLT-OIL-STD' => 20,
    'BRK-PAD-FR' => 8,
    'BAT-45AH' => 4
];

foreach ($openingStock as $sku => $quantity) {
    $stockStatement->execute([
        'part_id' => $partIds[$sku],
        'branch_id' => $branchId,
        'quantity' => $quantity
    ]);
}


// A demo customer + vehicle so the job card flow can be tried immediately

$statement = $pdo->prepare("
    INSERT INTO customers (organization_id, name, code, phone)
    VALUES (:organization_id, 'Walk-in Customer', 'CUST-0001', '9999999999')
    ON CONFLICT (organization_id, code) DO UPDATE SET name = EXCLUDED.name
    RETURNING id
");

$statement->execute(['organization_id' => $organizationId]);
$customerId = $statement->fetchColumn();

$statement = $pdo->prepare("
    INSERT INTO vehicles (organization_id, customer_id, registration_no, make, model, year, fuel_type)
    VALUES (:organization_id, :customer_id, 'KL-14-AB-1234', 'Maruti Suzuki', 'Swift', 2021, 'petrol')
    ON CONFLICT (organization_id, registration_no) DO UPDATE SET make = EXCLUDED.make
");

$statement->execute([
    'organization_id' => $organizationId,
    'customer_id' => $customerId
]);

echo "Seeded " . count($parts) . " parts with opening stock, and a demo customer + vehicle.\n";
