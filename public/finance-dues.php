<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Audit.php';
require_once __DIR__ . '/../app/Domain/SupplierLedger.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'finance.view');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];

$ledger = new SupplierLedger($pdo);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'finance.manage');

    $purchaseId = (int) ($_POST['purchase_id'] ?? 0);
    $method = $_POST['method'] ?? '';
    $amount = (float) ($_POST['amount'] ?? 0);
    $referenceNo = trim($_POST['reference_no'] ?? '');

    $statement = $pdo->prepare("SELECT * FROM purchases WHERE id = :id AND organization_id = :organization_id");
    $statement->execute(['id' => $purchaseId, 'organization_id' => $organizationId]);
    $purchase = $statement->fetch(PDO::FETCH_ASSOC);

    $balanceDue = $purchase ? (float) $purchase['total'] - (float) $purchase['amount_paid'] : 0;

    if (!$purchase) {
        $error = 'Purchase not found.';
    } elseif ($amount <= 0 || $amount > $balanceDue + 0.01) {
        $error = 'Enter a valid amount up to the balance due (₹' . number_format($balanceDue, 2) . ').';
    } else {

        $pdo->beginTransaction();

        try {
            $allocations = [['purchase_id' => $purchaseId, 'amount' => $amount]];
            $ledger->recordPayment(
                $organizationId,
                (int) $purchase['branch_id'],
                (int) $purchase['supplier_id'],
                $amount,
                $method,
                $referenceNo ?: null,
                date('Y-m-d'),
                $allocations,
                $user
            );

            log_audit_event(
                $pdo, $user, 'create', 'supplier_payment', $purchaseId,
                'Recorded payment of ₹' . number_format($amount, 2) . " for purchase {$purchase['purchase_no']}"
            );

            $pdo->commit();

            header('Location: /finance-dues.php');
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Supplier-level payables from ledger
$supplierPayables = $ledger->getOrganizationPayables($organizationId);
$totalPayable = $ledger->getTotalPayable($organizationId);

$statement = $pdo->prepare("
    SELECT p.id, p.purchase_no, p.total, p.amount_paid, p.payment_status, p.created_at, p.supplier_id, s.name AS supplier_name
    FROM purchases p
    INNER JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.organization_id = :organization_id AND p.payment_status != 'paid'
    ORDER BY p.created_at DESC
");
$statement->execute(['organization_id' => $organizationId]);
$payableRows = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("
    SELECT i.id, i.invoice_no, i.total, i.amount_paid, i.status, c.name AS customer_name
    FROM invoices i
    INNER JOIN customers c ON c.id = i.customer_id
    WHERE i.organization_id = :organization_id AND i.status IN ('unpaid', 'partial')
    ORDER BY i.created_at DESC
");
$statement->execute(['organization_id' => $organizationId]);
$receivableRows = $statement->fetchAll(PDO::FETCH_ASSOC);

$totalReceivable = array_sum(array_map(fn($r) => $r['total'] - $r['amount_paid'], $receivableRows));

$activeNav = 'finance_dues';
$topbarTitle = 'Debt';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Debt</title>

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
                    <h1 class="page-title">Debt</h1>
                    <p class="page-description">What you owe suppliers, and what customers owe you.</p>
                </div>
            </div>

            <div class="stats">
                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">You owe suppliers</div>
                            <div class="stat-value">₹<?= number_format($totalPayable, 2) ?></div>
                        </div>
                        <div class="stat-icon <?= $totalPayable > 0.009 ? 'warning' : '' ?>"><?= icon('truck', 17) ?></div>
                    </div>
                    <div class="stat-meta">Across <?= count($payableRows) ?> unpaid purchase<?= count($payableRows) === 1 ? '' : 's' ?></div>
                </div>
                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Owed to you</div>
                            <div class="stat-value">₹<?= number_format($totalReceivable, 2) ?></div>
                        </div>
                        <div class="stat-icon <?= $totalReceivable > 0.009 ? 'warning' : '' ?>"><?= icon('trending-up', 17) ?></div>
                    </div>
                    <div class="stat-meta">Across <?= count($receivableRows) ?> unpaid invoice<?= count($receivableRows) === 1 ? '' : 's' ?></div>
                </div>
            </div>

            <div class="card" style="margin-top:16px;">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('warehouse', 15) ?></span>
                        Supplier Accounts Overview
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($supplierPayables)): ?>
                        <div class="empty-state">
                            <?= icon('check-circle', 28) ?>
                            No outstanding supplier payables.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Supplier</th><th>Code</th><th>Outstanding Balance</th><th></th></tr>
                                <?php foreach ($supplierPayables as $sp): ?>
                                    <tr class="clickable" data-href="/supplier.php?id=<?= (int) $sp['supplier_id'] ?>">
                                        <td>
                                            <a href="/supplier.php?id=<?= (int) $sp['supplier_id'] ?>" style="font-weight: 600; color: var(--text); text-decoration: none;">
                                                <?= htmlspecialchars($sp['supplier_name']) ?>
                                            </a>
                                        </td>
                                        <td><span class="badge badge-subtle"><?= htmlspecialchars($sp['supplier_code'] ?? '—') ?></span></td>
                                        <td class="num" style="font-weight: 600; color: var(--danger);">₹<?= number_format((float) $sp['outstanding_balance'], 2) ?></td>
                                        <td style="text-align: right;">
                                            <a href="/supplier.php?id=<?= (int) $sp['supplier_id'] ?>" class="button secondary sm">
                                                <?= icon('receipt', 14) ?> View Ledger
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="margin-top:16px;">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('truck', 15) ?></span>
                        Unpaid Purchases Breakdown
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($payableRows)): ?>
                        <div class="empty-state">
                            <?= icon('check-circle', 28) ?>
                            No outstanding purchase bills.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Purchase</th><th>Supplier</th><th>Status</th><th>Balance due</th><th></th></tr>
                                <?php foreach ($payableRows as $row): ?>
                                    <?php $rowBalance = (float) $row['total'] - (float) $row['amount_paid']; ?>
                                    <tr class="clickable" data-href="/supplier.php?id=<?= (int) $row['supplier_id'] ?>&tab=bills">
                                        <td><strong><?= htmlspecialchars($row['purchase_no']) ?></strong></td>
                                        <td>
                                            <a href="/supplier.php?id=<?= (int) $row['supplier_id'] ?>" style="text-decoration: none; color: inherit; font-weight: 500;">
                                                <?= htmlspecialchars($row['supplier_name']) ?>
                                            </a>
                                        </td>
                                        <td style="text-transform:capitalize;"><span class="badge badge-warning"><?= htmlspecialchars($row['payment_status']) ?></span></td>
                                        <td class="num" style="font-weight: 600;">₹<?= number_format($rowBalance, 2) ?></td>
                                        <td style="text-align: right;">
                                            <?php if (user_can($user, 'finance.manage')): ?>
                                                <button type="button" class="button secondary sm"
                                                        onclick="openPayableModal(<?= (int) $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['purchase_no'])) ?>', <?= $rowBalance ?>)">
                                                    Record payment
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="margin-top:16px;">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('trending-up', 15) ?></span>
                        Receivables — what customers owe you
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($receivableRows)): ?>
                        <div class="empty-state">
                            <?= icon('check-circle', 28) ?>
                            Everything's paid up.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Invoice</th><th>Customer</th><th>Status</th><th>Balance due</th><th></th></tr>
                                <?php foreach ($receivableRows as $row): ?>
                                    <tr class="clickable" data-href="/invoice.php?id=<?= (int) $row['id'] ?>">
                                        <td><strong><?= htmlspecialchars($row['invoice_no']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                        <td style="text-transform:capitalize;"><span class="badge badge-warning"><?= htmlspecialchars($row['status']) ?></span></td>
                                        <td class="num">₹<?= number_format($row['total'] - $row['amount_paid'], 2) ?></td>
                                        <td style="text-align:right;"><a href="/invoice.php?id=<?= (int) $row['id'] ?>" class="button secondary sm">View</a></td>
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

<div class="modal-backdrop<?= $error ? ' open' : '' ?>" id="record-payable-modal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-title">
                <span class="icon-badge"><?= icon('truck', 16) ?></span>
                Record supplier payment
            </div>
            <button type="button" class="modal-close" data-close-modal="record-payable-modal" aria-label="Close"><?= icon('x', 18) ?></button>
        </div>
        <div class="modal-body">

            <?php if ($error): ?>
                <div class="form-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <p class="result-meta" style="margin-bottom:14px;">
                <span id="payable-modal-purchase-no"></span> — balance due ₹<span id="payable-modal-balance"></span>
            </p>

            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="purchase_id" id="payable-modal-purchase-id">
                <div class="form-grid single">
                    <div class="form-field">
                        <label>Method</label>
                        <select name="method" required>
                            <option value="cash">Cash</option>
                            <option value="upi">UPI</option>
                            <option value="card">Card</option>
                            <option value="bank_transfer">Bank transfer</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>Amount</label>
                        <input type="number" name="amount" id="payable-modal-amount-input" min="0.01" step="0.01" required>
                    </div>
                    <div class="form-field">
                        <label>Reference no. (optional)</label>
                        <input type="text" name="reference_no">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button"><?= icon('check', 16) ?> Record payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openPayableModal(purchaseId, purchaseNo, balance)
{
    document.getElementById('payable-modal-purchase-id').value = purchaseId;
    document.getElementById('payable-modal-purchase-no').textContent = purchaseNo;
    document.getElementById('payable-modal-balance').textContent = balance.toFixed(2);

    const amountInput = document.getElementById('payable-modal-amount-input');
    amountInput.max = balance;
    amountInput.value = balance;

    openModal('record-payable-modal');
}
</script>

<script src="/js/clickable-rows.js"></script>
</body>
</html>
