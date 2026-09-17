<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'purchases.manage');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $partIds = $_POST['part_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unitCosts = $_POST['unit_cost'] ?? [];

    $lines = [];

    foreach ($partIds as $index => $partId) {
        $partId = (int) $partId;
        $quantity = (float) ($quantities[$index] ?? 0);
        $unitCost = (float) ($unitCosts[$index] ?? 0);

        if ($partId && $quantity > 0) {
            $lines[] = [
                'part_id' => $partId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost
            ];
        }
    }

    if (!$supplierId || empty($lines)) {
        $error = 'Select a supplier and add at least one part with a quantity.';
    } else {

        $pdo->beginTransaction();

        try {
            $subtotal = 0;
            $taxAmount = 0;
            $preparedLines = [];

            $statement = $pdo->prepare("SELECT tax_rate FROM parts WHERE id = :id AND organization_id = :organization_id");

            foreach ($lines as $line) {
                $statement->execute(['id' => $line['part_id'], 'organization_id' => $organizationId]);
                $taxRate = (float) $statement->fetchColumn();

                $lineTotal = $line['quantity'] * $line['unit_cost'];
                $lineTax = $lineTotal * ($taxRate / 100);

                $subtotal += $lineTotal;
                $taxAmount += $lineTax;

                $preparedLines[] = $line + [
                    'tax_rate' => $taxRate,
                    'total' => $lineTotal + $lineTax
                ];
            }

            $total = $subtotal + $taxAmount;

            $statement = $pdo->prepare("
                SELECT COALESCE(MAX(CAST(SUBSTRING(purchase_no FROM 5) AS INT)), 0) + 1
                FROM purchases WHERE branch_id = :branch_id
            ");
            $statement->execute(['branch_id' => $branchId]);
            $nextNumber = (int) $statement->fetchColumn();
            $purchaseNo = 'PUR-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            $statement = $pdo->prepare("
                INSERT INTO purchases (organization_id, branch_id, supplier_id, purchase_no, subtotal, tax_amount, total)
                VALUES (:organization_id, :branch_id, :supplier_id, :purchase_no, :subtotal, :tax_amount, :total)
                RETURNING id
            ");
            $statement->execute([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'supplier_id' => $supplierId,
                'purchase_no' => $purchaseNo,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total
            ]);
            $purchaseId = $statement->fetchColumn();

            $itemStatement = $pdo->prepare("
                INSERT INTO purchase_items (purchase_id, part_id, quantity, unit_cost, tax_rate, total)
                VALUES (:purchase_id, :part_id, :quantity, :unit_cost, :tax_rate, :total)
            ");

            $inventoryStatement = $pdo->prepare("
                INSERT INTO inventory (part_id, branch_id, quantity)
                VALUES (:part_id, :branch_id, :quantity)
                ON CONFLICT (part_id, branch_id)
                DO UPDATE SET quantity = inventory.quantity + EXCLUDED.quantity, updated_at = CURRENT_TIMESTAMP
            ");

            $movementStatement = $pdo->prepare("
                INSERT INTO inventory_movements (organization_id, branch_id, part_id, quantity, direction, reason, reference_type, reference_id, created_by)
                VALUES (:organization_id, :branch_id, :part_id, :quantity, 'in', 'purchase', 'purchase', :reference_id, :created_by)
            ");

            foreach ($preparedLines as $line) {

                $itemStatement->execute([
                    'purchase_id' => $purchaseId,
                    'part_id' => $line['part_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'tax_rate' => $line['tax_rate'],
                    'total' => $line['total']
                ]);

                $inventoryStatement->execute([
                    'part_id' => $line['part_id'],
                    'branch_id' => $branchId,
                    'quantity' => $line['quantity']
                ]);

                $movementStatement->execute([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'part_id' => $line['part_id'],
                    'quantity' => $line['quantity'],
                    'reference_id' => $purchaseId,
                    'created_by' => $user['id']
                ]);
            }

            $statement = $pdo->prepare("SELECT name FROM suppliers WHERE id = :id");
            $statement->execute(['id' => $supplierId]);
            $purchaseSupplierName = $statement->fetchColumn();

            log_audit_event(
                $pdo, $user, 'create', 'purchase', (int) $purchaseId,
                "Created purchase order $purchaseNo from $purchaseSupplierName"
            );

            $pdo->commit();

            header('Location: /purchases.php');
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$statement = $pdo->prepare("SELECT id, name FROM suppliers WHERE organization_id = :organization_id ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$suppliers = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("SELECT id, name, sku, cost_price FROM parts WHERE organization_id = :organization_id AND status = 'active' ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$parts = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'purchases';
$topbarTitle = 'New Purchase';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — New Purchase</title>

    <link rel="stylesheet" href="/css/app.css">
    <?= favicon_tag($user['organization_logo_url'] ?? null) ?>
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <div class="page-header">
                <div>
                    <h1 class="page-title">New Purchase</h1>
                    <p class="page-description">Record parts received from a supplier — stock updates immediately.</p>
                </div>
            </div>

            <?php if (empty($suppliers)): ?>
                <div class="card">
                    <div class="empty-state">
                        <?= icon('warehouse', 28) ?>
                        Add a supplier before recording a purchase.
                        <a href="/suppliers.php" class="button secondary"><?= icon('plus', 16) ?> Add a supplier</a>
                    </div>
                </div>
            <?php elseif (empty($parts)): ?>
                <div class="card">
                    <div class="empty-state">
                        <?= icon('box', 28) ?>
                        Add a part before recording a purchase.
                        <a href="/parts.php" class="button secondary"><?= icon('plus', 16) ?> Add a part</a>
                    </div>
                </div>
            <?php else: ?>

                <div class="card" style="max-width: 760px;">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('truck', 15) ?></span>
                            Purchase details
                        </div>
                    </div>
                    <div class="card-body">

                        <?php if ($error): ?>
                            <div class="form-error"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" action="" id="purchase-form">

                            <?= csrf_field() ?>

                            <div class="form-grid single" style="margin-bottom:20px;">
                                <div class="form-field">
                                    <label>Supplier</label>
                                    <select name="supplier_id" required>
                                        <option value="">Select supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?= (int) $supplier['id'] ?>"><?= htmlspecialchars($supplier['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <table class="data-table" style="margin-bottom:12px;">
                                <thead>
                                    <tr><th>Part</th><th>Qty</th><th>Unit cost</th><th></th></tr>
                                </thead>
                                <tbody id="line-items"></tbody>
                            </table>

                            <button type="button" class="button secondary" id="add-line-button"><?= icon('plus', 16) ?> Add line</button>

                            <div class="form-actions">
                                <button type="submit" class="button"><?= icon('check', 16) ?> Save purchase</button>
                            </div>

                        </form>

                    </div>
                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

<script>
    window.GARAGEOS_PARTS = <?= json_encode($parts) ?>;
</script>
<script src="/js/purchase-new.js"></script>
</body>
</html>
