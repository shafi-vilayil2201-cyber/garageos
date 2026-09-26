<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Audit.php';
require_once __DIR__ . '/../app/Domain/SupplierLedger.php';
require_once __DIR__ . '/../app/View/Pagination.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'suppliers.view');

$organizationId = $user['organization_id'];
$branchId       = $user['branch_id'];
$supplierId     = (int) ($_GET['id'] ?? 0);
$canManage      = user_can($user, 'suppliers.manage');
$canFinance     = user_can($user, 'finance.manage');

// Fetch supplier
$statement = $pdo->prepare("
    SELECT id, name, code, phone, email, address, gstin, status, created_at
    FROM suppliers
    WHERE id = :id AND organization_id = :organization_id
");
$statement->execute(['id' => $supplierId, 'organization_id' => $organizationId]);
$supplier = $statement->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    header('Location: /suppliers.php');
    exit;
}

$ledger = new SupplierLedger($pdo);

$error        = null;
$successModal = null;

// -----------------------------------------------------------------
//  POST actions
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canFinance) {

    csrf_verify();

    $action = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction();

        switch ($action) {

            case 'record_payment':
                $amount      = (float) ($_POST['amount'] ?? 0);
                $method      = $_POST['method'] ?? 'cash';
                $referenceNo = trim($_POST['reference_no'] ?? '');
                $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
                $purchaseId  = (int) ($_POST['purchase_id'] ?? 0);

                if ($amount <= 0) {
                    throw new RuntimeException('Enter a valid amount.');
                }

                $allocations = [];
                if ($purchaseId) {
                    $allocations[] = ['purchase_id' => $purchaseId, 'amount' => $amount];
                }

                $paymentId = $ledger->recordPayment(
                    $organizationId, $branchId, $supplierId,
                    $amount, $method, $referenceNo, $paymentDate,
                    $allocations, $user
                );

                log_audit_event($pdo, $user, 'create', 'supplier_payment', $paymentId,
                    'Recorded payment of ₹' . number_format($amount, 2) . ' to ' . $supplier['name']
                );
                break;

            case 'credit_adjustment':
                $amount = (float) ($_POST['amount'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');

                if ($amount <= 0) {
                    throw new RuntimeException('Enter a valid amount.');
                }
                if ($reason === '') {
                    throw new RuntimeException('A reason is required for adjustments.');
                }

                $txnId = $ledger->recordCreditAdjustment($organizationId, $supplierId, $amount, $reason, $user);

                log_audit_event($pdo, $user, 'create', 'supplier_transaction', $txnId,
                    'Credit adjustment of ₹' . number_format($amount, 2) . ' for ' . $supplier['name'] . ': ' . $reason
                );
                break;

            case 'debit_adjustment':
                $amount = (float) ($_POST['amount'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');

                if ($amount <= 0) {
                    throw new RuntimeException('Enter a valid amount.');
                }
                if ($reason === '') {
                    throw new RuntimeException('A reason is required for adjustments.');
                }

                $txnId = $ledger->recordDebitAdjustment($organizationId, $supplierId, $amount, $reason, $user);

                log_audit_event($pdo, $user, 'create', 'supplier_transaction', $txnId,
                    'Debit adjustment of ₹' . number_format($amount, 2) . ' for ' . $supplier['name'] . ': ' . $reason
                );
                break;

            case 'opening_balance':
                $amount  = (float) ($_POST['amount'] ?? 0);
                $desc    = trim($_POST['description'] ?? '');
                $asOfDate = $_POST['as_of_date'] ?? date('Y-m-d');

                if ($amount <= 0) {
                    throw new RuntimeException('Enter a valid amount.');
                }

                $txnId = $ledger->recordOpeningBalance($organizationId, $supplierId, $amount, $desc, $asOfDate, $user);

                log_audit_event($pdo, $user, 'create', 'supplier_transaction', $txnId,
                    'Opening balance of ₹' . number_format($amount, 2) . ' for ' . $supplier['name']
                );
                break;

            case 'create_plan':
                $planAmount   = (float) ($_POST['plan_amount'] ?? 0);
                $frequency    = $_POST['frequency'] ?? 'monthly';
                $planMethod   = $_POST['plan_method'] ?? null;
                $startDate    = $_POST['start_date'] ?? '';
                $endDate      = $_POST['end_date'] ?? null;
                $totalPlanned = (float) ($_POST['total_planned'] ?? 0) ?: null;
                $notes        = trim($_POST['plan_notes'] ?? '');

                if ($planAmount <= 0) {
                    throw new RuntimeException('Enter a valid installment amount.');
                }
                if (!$startDate) {
                    throw new RuntimeException('Start date is required.');
                }

                $planId = $ledger->createPaymentPlan(
                    $organizationId, $supplierId,
                    $planAmount, $frequency, $planMethod ?: null,
                    $startDate, $endDate, $totalPlanned, $notes, $user
                );

                log_audit_event($pdo, $user, 'create', 'supplier_payment_plan', $planId,
                    'Created payment plan of ₹' . number_format($planAmount, 2) . '/' . $frequency . ' for ' . $supplier['name']
                );
                break;

            case 'update_plan_status':
                $planId    = (int) ($_POST['plan_id'] ?? 0);
                $newStatus = $_POST['plan_status'] ?? '';

                if (!in_array($newStatus, ['active', 'paused', 'completed', 'cancelled'], true)) {
                    throw new RuntimeException('Invalid plan status.');
                }

                $ledger->updatePaymentPlanStatus($organizationId, $planId, $newStatus);

                log_audit_event($pdo, $user, 'update', 'supplier_payment_plan', $planId,
                    'Updated payment plan status to ' . $newStatus . ' for ' . $supplier['name']
                );
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }

        $pdo->commit();
        header('Location: /supplier.php?id=' . $supplierId);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// -----------------------------------------------------------------
//  GET data
// -----------------------------------------------------------------

$summary = $ledger->getSupplierSummary($organizationId, $supplierId);

// Ledger filters
$dateFrom   = $_GET['from'] ?? null;
$dateTo     = $_GET['to'] ?? null;
$typeFilter = $_GET['type'] ?? null;

$ledgerPage = max(1, (int) ($_GET['lp'] ?? 1));
$perPage    = 25;
$ledgerOffset = ($ledgerPage - 1) * $perPage;

[$ledgerEntries, $ledgerTotal] = $ledger->getLedgerEntries(
    $organizationId, $supplierId,
    $dateFrom, $dateTo, $typeFilter,
    $perPage, $ledgerOffset
);

$ledgerTotalPages = max(1, (int) ceil($ledgerTotal / $perPage));

$unpaidPurchases = $ledger->getUnpaidPurchases($organizationId, $supplierId);
$paymentPlans    = $ledger->getPaymentPlans($organizationId, $supplierId);

$txnTypeLabels = [
    'OPENING_BALANCE'    => 'Opening Balance',
    'PURCHASE'           => 'Purchase',
    'PAYMENT'            => 'Payment',
    'PURCHASE_RETURN'    => 'Return',
    'CREDIT_ADJUSTMENT'  => 'Credit',
    'DEBIT_ADJUSTMENT'   => 'Debit',
    'REFUND'             => 'Refund',
    'PAYMENT_REVERSAL'   => 'Pay. Reversal',
    'PURCHASE_REVERSAL'  => 'Pur. Reversal'
];

$txnTypeBadge = [
    'OPENING_BALANCE'    => 'badge-info',
    'PURCHASE'           => 'badge-warning',
    'PAYMENT'            => 'badge-success',
    'PURCHASE_RETURN'    => 'badge-success',
    'CREDIT_ADJUSTMENT'  => 'badge-success',
    'DEBIT_ADJUSTMENT'   => 'badge-warning',
    'REFUND'             => 'badge-success',
    'PAYMENT_REVERSAL'   => 'badge-danger',
    'PURCHASE_REVERSAL'  => 'badge-danger'
];

// Frequency label helper
function frequency_label(string $freq): string {
    return match ($freq) {
        'weekly'    => 'Weekly',
        'monthly'   => 'Monthly',
        'quarterly' => 'Quarterly',
        default     => ucfirst($freq)
    };
}

// Next payment date helper
function next_payment_date(array $plan): ?string {
    $start = new DateTime($plan['start_date']);
    $now   = new DateTime();

    if ($plan['end_date'] && new DateTime($plan['end_date']) < $now) {
        return null;
    }

    $interval = match ($plan['frequency']) {
        'weekly'    => new DateInterval('P1W'),
        'quarterly' => new DateInterval('P3M'),
        default     => new DateInterval('P1M')
    };

    $date = clone $start;
    while ($date < $now) {
        $date->add($interval);
    }

    if ($plan['end_date'] && $date > new DateTime($plan['end_date'])) {
        return null;
    }

    return $date->format('d M Y');
}

$activeNav = 'suppliers';
$topbarTitle = $supplier['name'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — <?= htmlspecialchars($supplier['name']) ?></title>

    <link rel="stylesheet" href="/css/app.css">
    <?= favicon_tag($user['organization_logo_url'] ?? null) ?>
    <style>
        .supplier-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .summary-item {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }
        .summary-item .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .summary-item .value {
            font-size: 20px;
            font-weight: 700;
        }
        .summary-item .value.outstanding {
            color: var(--danger);
        }
        .summary-item .value.success {
            color: var(--success);
        }

        .action-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }

        .ledger-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .ledger-filters input,
        .ledger-filters select {
            padding: 6px 10px;
            font-size: 13px;
            min-width: 0;
        }

        .badge-info    { background: #e0f2fe; color: #0369a1; }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-warning { background: #fef9c3; color: #a16207; }
        .badge-danger  { background: #fee2e2; color: #b91c1c; }

        .ledger-table td.debit  { color: var(--danger); font-weight: 600; }
        .ledger-table td.credit { color: var(--success); font-weight: 600; }

        .plan-card {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 10px;
        }
        .plan-card .plan-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .plan-card .plan-detail {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .ledger-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 14px;
        }
        .ledger-pagination a {
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            color: var(--text);
        }
        .ledger-pagination a:hover { background: var(--hover); }
        .ledger-pagination .current {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <a href="/suppliers.php" class="link-action" style="margin-bottom:16px;"><?= icon('arrow-left', 14) ?> Back to Suppliers</a>

            <div class="page-header">
                <div>
                    <h1 class="page-title"><?= htmlspecialchars($supplier['name']) ?></h1>
                    <p class="page-description">
                        <?= htmlspecialchars($supplier['code']) ?>
                        <?= $supplier['phone'] ? ' · ' . htmlspecialchars($supplier['phone']) : '' ?>
                        <?= $supplier['email'] ? ' · ' . htmlspecialchars($supplier['email']) : '' ?>
                        <?= $supplier['gstin'] ? ' · GSTIN: ' . htmlspecialchars($supplier['gstin']) : '' ?>
                    </p>
                </div>

                <div style="display:flex; gap:10px; align-items:center;">
                    <?php if ($canManage): ?>
                        <a href="/suppliers.php?edit=<?= (int) $supplier['id'] ?>" class="button secondary"><?= icon('settings', 16) ?> Edit</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="form-error" style="margin-bottom:16px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- ─── Financial Summary ─── -->
            <div class="supplier-summary">
                <div class="summary-item">
                    <div class="label">Total Purchases</div>
                    <div class="value">₹<?= number_format((float) $summary['total_purchases'], 2) ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Total Payments</div>
                    <div class="value success">₹<?= number_format((float) $summary['total_payments'], 2) ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Credits / Returns</div>
                    <div class="value">₹<?= number_format((float) $summary['total_credits'], 2) ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Debit Adjustments</div>
                    <div class="value">₹<?= number_format((float) $summary['total_debits'], 2) ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Outstanding Balance</div>
                    <div class="value <?= (float) $summary['outstanding_balance'] > 0.009 ? 'outstanding' : 'success' ?>">₹<?= number_format((float) $summary['outstanding_balance'], 2) ?></div>
                </div>
            </div>

            <!-- ─── Action Buttons ─── -->
            <?php if ($canFinance): ?>
                <div class="action-bar">
                    <button type="button" class="button" onclick="openModal('payment-modal')"><?= icon('wallet', 16) ?> Record Payment</button>
                    <button type="button" class="button secondary" onclick="openModal('credit-modal')"><?= icon('trending-up', 16) ?> Credit Adjustment</button>
                    <button type="button" class="button secondary" onclick="openModal('debit-modal')"><?= icon('trending-up', 16) ?> Debit Adjustment</button>
                    <button type="button" class="button secondary" onclick="openModal('opening-modal')"><?= icon('file-text', 16) ?> Opening Balance</button>
                    <button type="button" class="button secondary" onclick="openModal('plan-modal')"><?= icon('calendar', 16) ?> Payment Plan</button>
                    <a href="/api/supplier-statement-export.php?id=<?= $supplierId ?><?= $dateFrom ? '&from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&to=' . urlencode($dateTo) : '' ?>" class="button secondary"><?= icon('download', 16) ?> Export Statement</a>
                </div>
            <?php endif; ?>

            <div class="content-grid content-grid-aside">

                <div class="stack">

                    <!-- ─── Ledger ─── -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                                Transaction Ledger
                            </div>
                            <div class="ledger-filters">
                                <form method="GET" action="" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                    <input type="hidden" name="id" value="<?= $supplierId ?>">
                                    <input type="date" name="from" value="<?= htmlspecialchars($dateFrom ?? '') ?>" placeholder="From" title="From date">
                                    <input type="date" name="to" value="<?= htmlspecialchars($dateTo ?? '') ?>" placeholder="To" title="To date">
                                    <select name="type" title="Type filter">
                                        <option value="">All types</option>
                                        <?php foreach ($txnTypeLabels as $code => $label): ?>
                                            <option value="<?= $code ?>" <?= $typeFilter === $code ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button secondary sm"><?= icon('search', 14) ?> Filter</button>
                                    <?php if ($dateFrom || $dateTo || $typeFilter): ?>
                                        <a href="/supplier.php?id=<?= $supplierId ?>" class="link-action">Clear</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <?php if (empty($ledgerEntries)): ?>
                                <div class="empty-state">
                                    <?= icon('receipt', 28) ?>
                                    No transactions recorded yet.
                                </div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="data-table ledger-table">
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Reference</th>
                                            <th>Description</th>
                                            <th>Debit</th>
                                            <th>Credit</th>
                                            <th>Balance</th>
                                        </tr>
                                        <?php foreach ($ledgerEntries as $entry): ?>
                                            <tr>
                                                <td><?= htmlspecialchars(date('d M Y', strtotime($entry['transaction_date']))) ?></td>
                                                <td>
                                                    <span class="badge <?= $txnTypeBadge[$entry['transaction_type']] ?? '' ?>" style="font-size:11px;">
                                                        <?= htmlspecialchars($txnTypeLabels[$entry['transaction_type']] ?? $entry['transaction_type']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($entry['reference_type'] === 'purchase' && $entry['reference_id']): ?>
                                                        <a href="/purchases.php" class="link-action"><?= htmlspecialchars($entry['reference_no'] ?? '—') ?></a>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($entry['reference_no'] ?? '—') ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($entry['description']) ?></td>
                                                <td class="num <?= (float) $entry['debit'] > 0 ? 'debit' : '' ?>">
                                                    <?= (float) $entry['debit'] > 0 ? '₹' . number_format((float) $entry['debit'], 2) : '—' ?>
                                                </td>
                                                <td class="num <?= (float) $entry['credit'] > 0 ? 'credit' : '' ?>">
                                                    <?= (float) $entry['credit'] > 0 ? '₹' . number_format((float) $entry['credit'], 2) : '—' ?>
                                                </td>
                                                <td class="num" style="font-weight:600;">
                                                    ₹<?= number_format((float) $entry['running_balance'], 2) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>

                                <?php if ($ledgerTotalPages > 1): ?>
                                    <div class="ledger-pagination">
                                        <?php
                                        $baseUrl = '/supplier.php?id=' . $supplierId;
                                        if ($dateFrom) $baseUrl .= '&from=' . urlencode($dateFrom);
                                        if ($dateTo)   $baseUrl .= '&to=' . urlencode($dateTo);
                                        if ($typeFilter) $baseUrl .= '&type=' . urlencode($typeFilter);
                                        ?>
                                        <?php if ($ledgerPage > 1): ?>
                                            <a href="<?= $baseUrl ?>&lp=<?= $ledgerPage - 1 ?>">← Previous</a>
                                        <?php endif; ?>

                                        <?php
                                        $start = max(1, $ledgerPage - 2);
                                        $end   = min($ledgerTotalPages, $ledgerPage + 2);
                                        for ($i = $start; $i <= $end; $i++):
                                        ?>
                                            <a href="<?= $baseUrl ?>&lp=<?= $i ?>" class="<?= $i === $ledgerPage ? 'current' : '' ?>"><?= $i ?></a>
                                        <?php endfor; ?>

                                        <?php if ($ledgerPage < $ledgerTotalPages): ?>
                                            <a href="<?= $baseUrl ?>&lp=<?= $ledgerPage + 1 ?>">Next →</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="stack">

                    <!-- ─── Contact Info ─── -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('warehouse', 15) ?></span>
                                Supplier Info
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($supplier['phone']): ?>
                                <p class="result-meta" style="margin-bottom:4px;">Phone</p>
                                <p style="margin-bottom:14px;"><?= htmlspecialchars($supplier['phone']) ?></p>
                            <?php endif; ?>

                            <?php if ($supplier['email']): ?>
                                <p class="result-meta" style="margin-bottom:4px;">Email</p>
                                <p style="margin-bottom:14px;"><?= htmlspecialchars($supplier['email']) ?></p>
                            <?php endif; ?>

                            <?php if ($supplier['address']): ?>
                                <p class="result-meta" style="margin-bottom:4px;">Address</p>
                                <p style="margin-bottom:14px;"><?= nl2br(htmlspecialchars($supplier['address'])) ?></p>
                            <?php endif; ?>

                            <?php if ($supplier['gstin']): ?>
                                <p class="result-meta" style="margin-bottom:4px;">GSTIN</p>
                                <p style="margin-bottom:14px;"><?= htmlspecialchars($supplier['gstin']) ?></p>
                            <?php endif; ?>

                            <p class="result-meta" style="margin-bottom:4px;">Supplier since</p>
                            <p><?= htmlspecialchars(date('d M Y', strtotime($supplier['created_at']))) ?></p>
                        </div>
                    </div>

                    <!-- ─── Payment Plans ─── -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('calendar', 15) ?></span>
                                Payment Plans
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($paymentPlans)): ?>
                                <div class="empty-state" style="padding:14px 0;">
                                    <?= icon('calendar', 22) ?>
                                    <span style="font-size:13px;">No payment plans.</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($paymentPlans as $plan): ?>
                                    <div class="plan-card">
                                        <div class="plan-header">
                                            <strong>₹<?= number_format((float) $plan['amount_per_installment'], 2) ?> / <?= frequency_label($plan['frequency']) ?></strong>
                                            <span class="badge <?= $plan['status'] === 'active' ? 'badge-success' : 'badge-warning' ?>" style="font-size:11px;">
                                                <?= ucfirst($plan['status']) ?>
                                            </span>
                                        </div>
                                        <?php if ($plan['payment_method']): ?>
                                            <div class="plan-detail">Method: <?= ucfirst(str_replace('_', ' ', $plan['payment_method'])) ?></div>
                                        <?php endif; ?>
                                        <div class="plan-detail">Start: <?= date('d M Y', strtotime($plan['start_date'])) ?></div>
                                        <?php if ($plan['end_date']): ?>
                                            <div class="plan-detail">End: <?= date('d M Y', strtotime($plan['end_date'])) ?></div>
                                        <?php endif; ?>
                                        <?php if ($plan['total_planned']): ?>
                                            <div class="plan-detail">Total Planned: ₹<?= number_format((float) $plan['total_planned'], 2) ?></div>
                                        <?php endif; ?>
                                        <?php
                                        $nextDate = next_payment_date($plan);
                                        if ($nextDate && $plan['status'] === 'active'):
                                        ?>
                                            <div class="plan-detail" style="color:var(--accent); font-weight:600;">Next: <?= $nextDate ?></div>
                                        <?php endif; ?>
                                        <?php if ($plan['notes']): ?>
                                            <div class="plan-detail"><?= htmlspecialchars($plan['notes']) ?></div>
                                        <?php endif; ?>

                                        <?php if ($canFinance && $plan['status'] === 'active'): ?>
                                            <div style="margin-top:8px; display:flex; gap:6px;">
                                                <form method="POST" action="" style="display:inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_plan_status">
                                                    <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                                                    <input type="hidden" name="plan_status" value="paused">
                                                    <button type="submit" class="button secondary sm"><?= icon('pause', 12) ?> Pause</button>
                                                </form>
                                                <form method="POST" action="" style="display:inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_plan_status">
                                                    <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                                                    <input type="hidden" name="plan_status" value="completed">
                                                    <button type="submit" class="button secondary sm"><?= icon('check', 12) ?> Complete</button>
                                                </form>
                                            </div>
                                        <?php elseif ($canFinance && $plan['status'] === 'paused'): ?>
                                            <div style="margin-top:8px;">
                                                <form method="POST" action="" style="display:inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_plan_status">
                                                    <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                                                    <input type="hidden" name="plan_status" value="active">
                                                    <button type="submit" class="button secondary sm"><?= icon('trending-up', 12) ?> Resume</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ─── Unpaid Purchases ─── -->
                    <?php if (!empty($unpaidPurchases)): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('truck', 15) ?></span>
                                    Unpaid Purchases
                                </div>
                            </div>
                            <div class="card-body" style="padding:0;">
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <tr><th>Purchase</th><th>Total</th><th>Due</th></tr>
                                        <?php foreach ($unpaidPurchases as $up): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($up['purchase_no']) ?></strong></td>
                                                <td class="num">₹<?= number_format((float) $up['total'], 2) ?></td>
                                                <td class="num" style="color:var(--danger); font-weight:600;">₹<?= number_format((float) $up['balance_due'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

        </section>

    </main>

</div>

<!-- ─── Modals ─── -->

<?php if ($canFinance): ?>

    <!-- Record Payment Modal -->
    <div class="modal-backdrop" id="payment-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('wallet', 16) ?></span>
                    Record Payment
                </div>
                <button type="button" class="modal-close" data-close-modal="payment-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="record_payment">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Amount</label>
                            <input type="number" name="amount" min="0.01" step="0.01" required>
                        </div>
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
                            <label>Reference no. (optional)</label>
                            <input type="text" name="reference_no">
                        </div>
                        <div class="form-field">
                            <label>Payment date</label>
                            <input type="date" name="payment_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-field">
                            <label>Allocate to purchase (optional)</label>
                            <select name="purchase_id">
                                <option value="">General balance payment</option>
                                <?php foreach ($unpaidPurchases as $up): ?>
                                    <option value="<?= (int) $up['id'] ?>"><?= htmlspecialchars($up['purchase_no']) ?> — Due ₹<?= number_format((float) $up['balance_due'], 2) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Credit Adjustment Modal -->
    <div class="modal-backdrop" id="credit-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('trending-up', 16) ?></span>
                    Credit Adjustment
                </div>
                <button type="button" class="modal-close" data-close-modal="credit-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <p class="result-meta" style="margin-bottom:14px;">Reduces the amount you owe this supplier (e.g. discount, return, compensation).</p>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="credit_adjustment">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Amount</label>
                            <input type="number" name="amount" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-field">
                            <label>Reason</label>
                            <input type="text" name="reason" required placeholder="e.g. Volume discount, returned item">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save Credit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Debit Adjustment Modal -->
    <div class="modal-backdrop" id="debit-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('trending-up', 16) ?></span>
                    Debit Adjustment
                </div>
                <button type="button" class="modal-close" data-close-modal="debit-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <p class="result-meta" style="margin-bottom:14px;">Increases the amount you owe this supplier (e.g. freight charge, pricing correction).</p>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="debit_adjustment">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Amount</label>
                            <input type="number" name="amount" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-field">
                            <label>Reason</label>
                            <input type="text" name="reason" required placeholder="e.g. Additional freight, missing charge">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save Debit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Opening Balance Modal -->
    <div class="modal-backdrop" id="opening-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('file-text', 16) ?></span>
                    Opening Balance
                </div>
                <button type="button" class="modal-close" data-close-modal="opening-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <p class="result-meta" style="margin-bottom:14px;">Set the amount you already owed this supplier before GarageOS started tracking.</p>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="opening_balance">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Amount</label>
                            <input type="number" name="amount" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-field">
                            <label>As of date</label>
                            <input type="date" name="as_of_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-field">
                            <label>Description (optional)</label>
                            <input type="text" name="description" placeholder="Opening balance">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Payment Plan Modal -->
    <div class="modal-backdrop" id="plan-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('calendar', 16) ?></span>
                    New Payment Plan
                </div>
                <button type="button" class="modal-close" data-close-modal="plan-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <p class="result-meta" style="margin-bottom:14px;">Schedule a payment arrangement. This does <strong>not</strong> record actual payments — it's a plan for future reference.</p>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_plan">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Amount per installment</label>
                            <input type="number" name="plan_amount" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-field">
                            <label>Frequency</label>
                            <select name="frequency" required>
                                <option value="monthly">Monthly</option>
                                <option value="weekly">Weekly</option>
                                <option value="quarterly">Quarterly</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Payment method (optional)</label>
                            <select name="plan_method">
                                <option value="">Not specified</option>
                                <option value="cash">Cash</option>
                                <option value="upi">UPI</option>
                                <option value="card">Card</option>
                                <option value="bank_transfer">Bank transfer</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Start date</label>
                            <input type="date" name="start_date" required>
                        </div>
                        <div class="form-field">
                            <label>End date (optional)</label>
                            <input type="date" name="end_date">
                        </div>
                        <div class="form-field">
                            <label>Total planned amount (optional)</label>
                            <input type="number" name="total_planned" min="0" step="0.01">
                        </div>
                        <div class="form-field">
                            <label>Notes (optional)</label>
                            <textarea name="plan_notes"></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Create Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

</body>
</html>
