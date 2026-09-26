<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/Pagination.php';
require_once __DIR__ . '/../app/Domain/Audit.php';
require_once __DIR__ . '/../app/Domain/SupplierLedger.php';

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
$filter = $_GET['filter'] ?? 'all'; // all, due, settled

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'suppliers.manage');

    $action  = $_POST['action'] ?? 'create';
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gstin   = strtoupper(trim($_POST['gstin'] ?? ''));

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
                'name'            => $name,
                'phone'           => $phone ?: null,
                'email'           => $email ?: null,
                'address'         => $address ?: null,
                'gstin'           => $gstin ?: null,
                'id'              => $editingSupplierId,
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
            try {
                $statement = $pdo->prepare("
                    SELECT COALESCE(MAX(CAST(SUBSTRING(code FROM 5) AS INT)), 0) + 1
                    FROM suppliers WHERE organization_id = :organization_id
                ");
                $statement->execute(['organization_id' => $organizationId]);
                $nextNumber = (int) $statement->fetchColumn();
                $code = 'SUP-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

                $statement = $pdo->prepare("
                    INSERT INTO suppliers (organization_id, name, code, phone, email, address, gstin)
                    VALUES (:organization_id, :name, :code, :phone, :email, :address, :gstin)
                    RETURNING id
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'name'            => $name,
                    'code'            => $code,
                    'phone'           => $phone ?: null,
                    'email'           => $email ?: null,
                    'address'         => $address ?: null,
                    'gstin'           => $gstin ?: null
                ]);
                $newSupplierId = (int) $statement->fetchColumn();

                log_audit_event($pdo, $user, 'create', 'supplier', $newSupplierId, "Created supplier $name");

                header('Location: /suppliers.php');
                exit;

            } catch (Throwable $e) {
                $error = 'Could not save supplier. Please check the details and try again.';
                $errorAction = 'create';
            }
        }
    }
}

$ledger = new SupplierLedger($pdo);
$totalOrgPayable = $ledger->getTotalPayable($organizationId);

// Overall Counts & Totals
$statement = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE organization_id = :organization_id AND status = 'active'");
$statement->execute(['organization_id' => $organizationId]);
$totalActiveSuppliers = (int) $statement->fetchColumn();

// Fetch suppliers with their calculated outstanding balance
$sql = "
    SELECT
        s.id, s.name, s.code, s.phone, s.email, s.address, s.gstin, s.created_at,
        COALESCE(SUM(st.debit), 0) - COALESCE(SUM(st.credit), 0) AS outstanding_balance,
        COUNT(DISTINCT p.id) AS purchase_count
    FROM suppliers s
    LEFT JOIN supplier_transactions st
        ON st.supplier_id = s.id
       AND st.organization_id = s.organization_id
    LEFT JOIN purchases p
        ON p.supplier_id = s.id
       AND p.organization_id = s.organization_id
    WHERE s.organization_id = :organization_id
      AND s.status = 'active'
    GROUP BY s.id, s.name, s.code, s.phone, s.email, s.address, s.gstin, s.created_at
";

if ($filter === 'due') {
    $sql .= " HAVING COALESCE(SUM(st.debit), 0) - COALESCE(SUM(st.credit), 0) > 0.009";
} elseif ($filter === 'settled') {
    $sql .= " HAVING COALESCE(SUM(st.debit), 0) - COALESCE(SUM(st.credit), 0) <= 0.009";
}

$sql .= " ORDER BY outstanding_balance DESC, s.name ASC";

$statement = $pdo->prepare($sql);
$statement->execute(['organization_id' => $organizationId]);
$suppliers = $statement->fetchAll(PDO::FETCH_ASSOC);

$suppliersWithDuesCount = count(array_filter($suppliers, fn($s) => (float) $s['outstanding_balance'] > 0.009));

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
    <style>
        .chip-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .filter-chip {
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid var(--border);
            font-size: 13px;
            text-decoration: none;
            color: var(--text);
            background: var(--surface);
            transition: all 0.15s ease;
        }
        .filter-chip:hover {
            background: var(--surface-sunken);
            color: var(--text);
        }
        .filter-chip.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            font-weight: 600;
        }
        .filter-chip.active:hover {
            background: var(--primary-dark);
            color: #ffffff;
            border-color: var(--primary-dark);
        }
    </style>
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Suppliers & Accounts Payable</h1>
                    <p class="page-description">Manage parts suppliers, purchase bills, and account balances.</p>
                </div>

                <?php if ($canManageSuppliers): ?>
                    <button type="button" class="button" onclick="openModal('supplier-modal')">
                        <?= icon('plus', 16) ?> Add Supplier
                    </button>
                <?php endif; ?>
            </div>

            <!-- Stats Bar -->
            <div class="stats" style="margin-bottom:20px;">
                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Total Suppliers</div>
                            <div class="stat-value"><?= $totalActiveSuppliers ?></div>
                        </div>
                        <div class="stat-icon"><?= icon('warehouse', 17) ?></div>
                    </div>
                    <div class="stat-meta">Active vendor accounts</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Total Payable</div>
                            <div class="stat-value" style="color:<?= $totalOrgPayable > 0.009 ? 'var(--danger)' : 'var(--success)' ?>;">
                                ₹<?= number_format($totalOrgPayable, 2) ?>
                            </div>
                        </div>
                        <div class="stat-icon <?= $totalOrgPayable > 0.009 ? 'warning' : '' ?>"><?= icon('wallet', 17) ?></div>
                    </div>
                    <div class="stat-meta">Outstanding across all vendors</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Pending Settlements</div>
                            <div class="stat-value"><?= $suppliersWithDuesCount ?></div>
                        </div>
                        <div class="stat-icon <?= $suppliersWithDuesCount > 0 ? 'warning' : '' ?>"><?= icon('alert-triangle', 17) ?></div>
                    </div>
                    <div class="stat-meta">Suppliers with active dues</div>
                </div>
            </div>

            <!-- Filter Chips -->
            <div class="chip-group">
                <a href="?filter=all" class="filter-chip <?= $filter === 'all' ? 'active' : '' ?>">All Suppliers (<?= $totalActiveSuppliers ?>)</a>
                <a href="?filter=due" class="filter-chip <?= $filter === 'due' ? 'active' : '' ?>">Has Balance Due (<?= $suppliersWithDuesCount ?>)</a>
                <a href="?filter=settled" class="filter-chip <?= $filter === 'settled' ? 'active' : '' ?>">Settled / Clear</a>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('warehouse', 15) ?></span>
                        Suppliers Directory
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($suppliers)): ?>
                        <div class="empty-state">
                            <?= icon('warehouse', 28) ?>
                            No suppliers match the current view.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr>
                                    <th>Supplier Name</th>
                                    <th>Contact</th>
                                    <th>GSTIN</th>
                                    <th style="text-align:right;">Outstanding Due</th>
                                    <th style="text-align:right;"></th>
                                </tr>
                                <?php foreach ($suppliers as $s): ?>
                                    <?php
                                    $due = (float) $s['outstanding_balance'];
                                    $hasDue = $due > 0.009;
                                    ?>
                                    <tr class="clickable" data-href="/supplier.php?id=<?= (int) $s['id'] ?>">
                                        <td>
                                            <a href="/supplier.php?id=<?= (int) $s['id'] ?>" style="font-weight:600; color:var(--text); text-decoration:none; font-size:14px;">
                                                <?= htmlspecialchars($s['name']) ?>
                                            </a>
                                            <div class="result-meta" style="font-size:11px; margin-top:2px;">
                                                <span class="badge" style="background:var(--hover); font-size:10px;"><?= htmlspecialchars($s['code']) ?></span>
                                                · <?= (int) $s['purchase_count'] ?> purchase<?= (int) $s['purchase_count'] === 1 ? '' : 's' ?>
                                            </div>
                                        </td>
                                        <td style="font-size:13px;">
                                            <?php if ($s['phone']): ?>
                                                <div>📞 <?= htmlspecialchars($s['phone']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($s['email']): ?>
                                                <div style="color:var(--muted); font-size:12px;"><?= htmlspecialchars($s['email']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!$s['phone'] && !$s['email']): ?>
                                                <span style="color:var(--muted);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px;">
                                            <?= $s['gstin'] ? htmlspecialchars($s['gstin']) : '<span style="color:var(--muted);">Unregistered</span>' ?>
                                        </td>
                                        <td class="num" style="font-weight:700; font-size:14px; color: <?= $hasDue ? 'var(--danger)' : 'var(--success)' ?>;">
                                            ₹<?= number_format($due, 2) ?>
                                            <div style="font-size:10px; font-weight:500; text-transform:uppercase; color: <?= $hasDue ? 'var(--danger)' : 'var(--success)' ?>;">
                                                <?= $hasDue ? 'Due' : 'Settled' ?>
                                            </div>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <a href="/supplier.php?id=<?= (int) $s['id'] ?>" class="button secondary sm" style="margin-right: 6px;">
                                                <?= icon('receipt', 13) ?> Ledger & Account
                                            </a>
                                            <?php if ($canManageSuppliers): ?>
                                                <a href="?edit=<?= (int) $s['id'] ?>" class="link-action" style="font-size:13px;">
                                                    <?= icon('settings', 13) ?> Edit
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

<?php if ($canManageSuppliers): ?>

    <!-- Add Supplier Modal -->
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
                            <label>Supplier / Business Name</label>
                            <input type="text" name="name" required placeholder="e.g. Metro Spares & Accessories">
                        </div>
                        <div class="form-field">
                            <label>Phone Number (Optional)</label>
                            <input type="tel" name="phone" placeholder="e.g. +91 98765 43210">
                        </div>
                        <div class="form-field">
                            <label>Email Address (Optional)</label>
                            <input type="email" name="email" placeholder="e.g. vendor@example.com">
                        </div>
                        <div class="form-field">
                            <label>GSTIN (Optional — For Tax Input Credit)</label>
                            <input type="text" name="gstin" placeholder="e.g. 27AABCM1234F1Z5">
                        </div>
                        <div class="form-field">
                            <label>Address / Warehouse Location (Optional)</label>
                            <textarea name="address" placeholder="e.g. Shop 12, Auto Market, Mumbai"></textarea>
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top:16px;">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save Supplier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Supplier Modal -->
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
                                <label>Supplier Name</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($editingSupplier['name']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Phone</label>
                                <input type="tel" name="phone" value="<?= htmlspecialchars($editingSupplier['phone'] ?? '') ?>">
                            </div>
                            <div class="form-field">
                                <label>Email</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($editingSupplier['email'] ?? '') ?>">
                            </div>
                            <div class="form-field">
                                <label>GSTIN</label>
                                <input type="text" name="gstin" value="<?= htmlspecialchars($editingSupplier['gstin'] ?? '') ?>">
                            </div>
                            <div class="form-field">
                                <label>Address</label>
                                <textarea name="address"><?= htmlspecialchars($editingSupplier['address'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="form-actions" style="margin-top:16px;">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Save Changes</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php endif; ?>

<script src="/js/clickable-rows.js"></script>
</body>
</html>
