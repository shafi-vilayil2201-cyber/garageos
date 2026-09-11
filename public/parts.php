<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/Pagination.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'parts.view');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];
$canManageParts = user_can($user, 'parts.manage');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'parts.manage');

    $name = trim($_POST['name'] ?? '');
    $sku = strtoupper(trim($_POST['sku'] ?? ''));
    $costPrice = (float) ($_POST['cost_price'] ?? 0);
    $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
    $openingStock = (float) ($_POST['opening_stock'] ?? 0);
    $reorderLevel = (float) ($_POST['reorder_level'] ?? 0);

    if ($name === '' || $sku === '') {
        $error = 'Name and SKU are required.';
    } else {

        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare("
                INSERT INTO parts (organization_id, name, sku, cost_price, selling_price, reorder_level)
                VALUES (:organization_id, :name, :sku, :cost_price, :selling_price, :reorder_level)
                RETURNING id
            ");
            $statement->execute([
                'organization_id' => $organizationId,
                'name' => $name,
                'sku' => $sku,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'reorder_level' => $reorderLevel
            ]);
            $partId = $statement->fetchColumn();

            $statement = $pdo->prepare("
                INSERT INTO inventory (part_id, branch_id, quantity)
                VALUES (:part_id, :branch_id, :quantity)
            ");
            $statement->execute([
                'part_id' => $partId,
                'branch_id' => $branchId,
                'quantity' => $openingStock
            ]);

            if ($openingStock > 0) {
                $statement = $pdo->prepare("
                    INSERT INTO inventory_movements (organization_id, branch_id, part_id, quantity, direction, reason, created_by)
                    VALUES (:organization_id, :branch_id, :part_id, :quantity, 'in', 'opening_stock', :created_by)
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'part_id' => $partId,
                    'quantity' => $openingStock,
                    'created_by' => $user['id']
                ]);
            }

            $pdo->commit();

            header('Location: /parts.php');
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'That SKU already exists.';
        }
    }
}

$statement = $pdo->prepare("SELECT COUNT(*) FROM parts WHERE organization_id = :organization_id");
$statement->execute(['organization_id' => $organizationId]);
$totalParts = (int) $statement->fetchColumn();
$page = paginate_page($totalParts);

$statement = $pdo->prepare("
    SELECT p.id, p.name, p.sku, p.selling_price, p.reorder_level,
           COALESCE(i.quantity, 0) AS stock_quantity
    FROM parts p
    LEFT JOIN inventory i ON i.part_id = p.id AND i.branch_id = :branch_id
    WHERE p.organization_id = :organization_id
    ORDER BY p.name
    LIMIT :limit OFFSET :offset
");
$statement->bindValue('organization_id', $organizationId);
$statement->bindValue('branch_id', $branchId);
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$parts = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'parts';
$topbarTitle = 'Parts';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Parts</title>

    <link rel="stylesheet" href="/css/app.css">
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Parts</h1>
                    <p class="page-description">Stock on hand at this branch.</p>
                </div>

                <?php if ($canManageParts): ?>
                    <button type="button" class="button" onclick="openModal('part-modal')"><?= icon('plus', 16) ?> Add Part</button>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('box', 15) ?></span>
                        Inventory
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($parts)): ?>
                        <div class="empty-state">
                            <?= icon('box', 28) ?>
                            No parts yet. Add your first one.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Part</th><th>SKU</th><th>Price</th><th>Stock</th></tr>
                                <?php foreach ($parts as $part): ?>
                                    <?php $low = $part['stock_quantity'] <= $part['reorder_level']; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($part['name']) ?></td>
                                        <td><?= htmlspecialchars($part['sku']) ?></td>
                                        <td class="num">₹<?= number_format($part['selling_price'], 2) ?></td>
                                        <td class="num">
                                            <span class="badge <?= $low ? 'badge-on_hold' : 'badge-ready' ?>">
                                                <?php if ($low): ?><?= icon('alert-triangle', 12) ?><?php endif; ?>
                                                <?= rtrim(rtrim(number_format($part['stock_quantity'], 2), '0'), '.') ?>
                                                <?= $low ? ' — reorder' : '' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <?= render_pagination($page, $totalParts) ?>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

<?php if ($canManageParts): ?>

    <div class="modal-backdrop<?= $error ? ' open' : '' ?>" id="part-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('box', 16) ?></span>
                    Add Part
                </div>
                <button type="button" class="modal-close" data-close-modal="part-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Name</label>
                            <input type="text" name="name" required>
                        </div>
                        <div class="form-field">
                            <label>SKU</label>
                            <input type="text" name="sku" required>
                        </div>
                        <div class="form-field">
                            <label>Cost price</label>
                            <input type="number" name="cost_price" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-field">
                            <label>Selling price</label>
                            <input type="number" name="selling_price" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-field">
                            <label>Opening stock</label>
                            <input type="number" name="opening_stock" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-field">
                            <label>Reorder level</label>
                            <input type="number" name="reorder_level" step="0.01" min="0" value="0">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save part</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="/js/modal.js"></script>

<?php endif; ?>

</body>
</html>
