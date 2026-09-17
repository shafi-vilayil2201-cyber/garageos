<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/View/Pagination.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'purchases.view');

$organizationId = $user['organization_id'];

$statement = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE organization_id = :organization_id");
$statement->execute(['organization_id' => $organizationId]);
$totalPurchases = (int) $statement->fetchColumn();
$page = paginate_page($totalPurchases);

$statement = $pdo->prepare("
    SELECT
        pu.id,
        pu.purchase_no,
        pu.status,
        pu.total,
        pu.created_at,
        s.name AS supplier_name
    FROM purchases pu
    INNER JOIN suppliers s ON s.id = pu.supplier_id
    WHERE pu.organization_id = :organization_id
    ORDER BY pu.created_at DESC
    LIMIT :limit OFFSET :offset
");
$statement->bindValue('organization_id', $organizationId);
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$purchases = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'purchases';
$topbarTitle = 'Purchases';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Purchases</title>

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
                    <h1 class="page-title">Purchases</h1>
                    <p class="page-description">Restocking parts from your suppliers.</p>
                </div>

                <a href="/purchase-new.php" class="button"><?= icon('plus', 16) ?> New Purchase</a>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('truck', 15) ?></span>
                        All purchases
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($purchases)): ?>
                        <div class="empty-state">
                            <?= icon('truck', 28) ?>
                            No purchases recorded yet.
                            <?php
                                $statement = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE organization_id = :organization_id");
                                $statement->execute(['organization_id' => $organizationId]);
                                $hasSuppliers = (int) $statement->fetchColumn() > 0;
                            ?>
                            <?php if (!$hasSuppliers): ?>
                                <a href="/suppliers.php" class="link-action"><?= icon('plus', 14) ?> Add a supplier first</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr>
                                    <th>Purchase #</th>
                                    <th>Supplier</th>
                                    <th>Date</th>
                                    <th>Total</th>
                                </tr>
                                <?php foreach ($purchases as $purchase): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($purchase['purchase_no']) ?></strong></td>
                                        <td><?= htmlspecialchars($purchase['supplier_name']) ?></td>
                                        <td><?= htmlspecialchars(date('d M Y', strtotime($purchase['created_at']))) ?></td>
                                        <td class="num">₹<?= number_format($purchase['total'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <?= render_pagination($page, $totalPurchases) ?>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

</body>
</html>
