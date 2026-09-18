<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/Pagination.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'suppliers.view');

$organizationId = $user['organization_id'];
$canManageSuppliers = user_can($user, 'suppliers.manage');

$error = null;
$errorAction = null;
$editingSupplierId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'suppliers.manage');

    $action = $_POST['action'] ?? 'create';
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gstin = strtoupper(trim($_POST['gstin'] ?? ''));

    if ($action === 'update') {

        $editingSupplierId = (int) ($_POST['supplier_id'] ?? 0);

        if ($name === '') {
            $error = 'Supplier name is required.';
            $errorAction = 'update';
        } else {
            $statement = $pdo->prepare("
                UPDATE suppliers
                SET name = :name, phone = :phone, email = :email, address = :address, gstin = :gstin, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND organization_id = :organization_id
            ");
            $statement->execute([
                'name' => $name,
                'phone' => $phone ?: null,
                'email' => $email ?: null,
                'address' => $address ?: null,
                'gstin' => $gstin ?: null,
                'id' => $editingSupplierId,
                'organization_id' => $organizationId
            ]);

            if ($statement->rowCount() > 0) {
                log_audit_event($pdo, $user, 'update', 'supplier', $editingSupplierId, "Updated supplier $name");
            }

            header('Location: /suppliers.php');
            exit;
        }

    } else {

        if ($name === '') {
            $error = 'Supplier name is required.';
            $errorAction = 'create';
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
                RETURNING id
            ");
            $statement->execute([
                'organization_id' => $organizationId,
                'name' => $name,
                'code' => $code,
                'phone' => $phone ?: null
            ]);
            $newSupplierId = (int) $statement->fetchColumn();

            log_audit_event($pdo, $user, 'create', 'supplier', $newSupplierId, "Created supplier $name");

            header('Location: /suppliers.php');
            exit;
        }
    }
}

$statement = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE organization_id = :organization_id");
$statement->execute(['organization_id' => $organizationId]);
$totalSuppliers = (int) $statement->fetchColumn();
$page = paginate_page($totalSuppliers);

$statement = $pdo->prepare("
    SELECT id, name, code, phone, email
    FROM suppliers
    WHERE organization_id = :organization_id
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
");
$statement->bindValue('organization_id', $organizationId);
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$suppliers = $statement->fetchAll(PDO::FETCH_ASSOC);

$editingSupplier = null;

if ($editingSupplierId) {
    $statement = $pdo->prepare("
        SELECT id, name, phone, email, address, gstin
        FROM suppliers
        WHERE id = :id AND organization_id = :organization_id
    ");
    $statement->execute(['id' => $editingSupplierId, 'organization_id' => $organizationId]);
    $editingSupplier = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$editingSupplier) {
        header('Location: /suppliers.php');
        exit;
    }
}

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
                    <h1 class="page-title">Suppliers</h1>
                    <p class="page-description">Who you buy parts from.</p>
                </div>

                <?php if ($canManageSuppliers): ?>
                    <button type="button" class="button" onclick="openModal('supplier-modal')"><?= icon('plus', 16) ?> Add Supplier</button>
                <?php endif; ?>
            </div>

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
                                <tr><th>Name</th><th>Phone</th><th></th></tr>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($supplier['name']) ?></strong>
                                            <div class="result-meta"><?= htmlspecialchars($supplier['code']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($supplier['phone'] ?? '—') ?></td>
                                        <td>
                                            <?php if ($canManageSuppliers): ?>
                                                <a href="?edit=<?= (int) $supplier['id'] ?>" class="link-action"><?= icon('settings', 14) ?> Edit</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <?= render_pagination($page, $totalSuppliers) ?>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

<?php if ($canManageSuppliers): ?>

    <div class="modal-backdrop<?= $errorAction === 'create' ? ' open' : '' ?>" id="supplier-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('warehouse', 16) ?></span>
                    Add Supplier
                </div>
                <button type="button" class="modal-close" data-close-modal="supplier-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'create' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
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

    <div class="modal-backdrop<?= $editingSupplier ? ' open' : '' ?>" id="edit-supplier-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('warehouse', 16) ?></span>
                    Edit Supplier
                </div>
                <button type="button" class="modal-close" data-close-modal="edit-supplier-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'update' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($editingSupplier): ?>
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="supplier_id" value="<?= (int) $editingSupplier['id'] ?>">
                        <div class="form-grid single">
                            <div class="form-field">
                                <label>Name</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($editingSupplier['name']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Phone (optional)</label>
                                <input type="tel" name="phone" value="<?= htmlspecialchars($editingSupplier['phone'] ?? '') ?>">
                            </div>
                            <div class="form-field">
                                <label>Email (optional)</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($editingSupplier['email'] ?? '') ?>">
                            </div>
                            <div class="form-field">
                                <label>Address (optional)</label>
                                <textarea name="address"><?= htmlspecialchars($editingSupplier['address'] ?? '') ?></textarea>
                            </div>
                            <div class="form-field">
                                <label>GSTIN (optional)</label>
                                <input type="text" name="gstin" placeholder="For input tax credit" value="<?= htmlspecialchars($editingSupplier['gstin'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Save changes</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="/js/modal.js"></script>

<?php endif; ?>

</body>
</html>
