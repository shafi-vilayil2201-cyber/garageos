<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'suppliers.view');

$organizationId = $user['organization_id'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'suppliers.manage');

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($name === '') {
        $error = 'Supplier name is required.';
    } else {

        $statement = $pdo->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING(code FROM 5) AS INT)), 0) + 1
            FROM suppliers WHERE organization_id = :organization_id
        ");
        $statement->execute(['organization_id' => $organizationId]);
        $nextNumber = (int) $statement->fetchColumn();
        $code = 'SUP-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        $statement = $pdo->prepare("
            INSERT INTO suppliers (organization_id, name, code, phone)
            VALUES (:organization_id, :name, :code, :phone)
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'phone' => $phone ?: null
        ]);

        header('Location: /suppliers.php');
        exit;
    }
}

$statement = $pdo->prepare("
    SELECT name, code, phone, email
    FROM suppliers
    WHERE organization_id = :organization_id
    ORDER BY created_at DESC
");
$statement->execute(['organization_id' => $organizationId]);
$suppliers = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'suppliers';
$topbarTitle = 'Suppliers';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Suppliers</title>

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
                    <h1 class="page-title">Suppliers</h1>
                    <p class="page-description">Who you buy parts from.</p>
                </div>
            </div>

            <div class="content-grid" style="grid-template-columns: 1fr 380px;">

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('warehouse', 15) ?></span>
                            All suppliers
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($suppliers)): ?>
                            <div class="empty-state">
                                <?= icon('warehouse', 28) ?>
                                No suppliers yet.
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr><th>Name</th><th>Phone</th></tr>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($supplier['name']) ?></strong>
                                                <div class="result-meta"><?= htmlspecialchars($supplier['code']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($supplier['phone'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('plus', 15) ?></span>
                            Add a supplier
                        </div>
                    </div>
                    <div class="card-body">

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
                                    <label>Phone (optional)</label>
                                    <input type="tel" name="phone">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="button"><?= icon('check', 16) ?> Save supplier</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
