<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Audit.php';
require_once __DIR__ . '/../app/Domain/SupplierLedger.php';
require_once __DIR__ . '/../app/View/Pagination.php';
require_once __DIR__ . '/../app/Support/Flash.php';

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

$error     = null;
$activeTab = $_GET['tab'] ?? 'ledger';
if (!in_array($activeTab, ['ledger', 'bills', 'plans', 'profile'], true)) {
    $activeTab = 'ledger';
}

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
                if ($purchaseId > 0) {
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
                flash_set('Payment of ₹' . number_format($amount, 2) . ' recorded successfully.');
                break;

            case 'adjustment':
                $adjType = $_POST['adjustment_type'] ?? 'credit';
                $amount  = (float) ($_POST['amount'] ?? 0);
                $reason  = trim($_POST['reason'] ?? '');

                if ($amount <= 0) {
                    throw new RuntimeException('Enter a valid amount.');
                }
                if ($reason === '') {
                    throw new RuntimeException('A reason is required for adjustments.');
                }

                if ($adjType === 'credit') {
                    $txnId = $ledger->recordCreditAdjustment($organizationId, $supplierId, $amount, $reason, $user);
                    log_audit_event($pdo, $user, 'create', 'supplier_transaction', $txnId,
                        'Credit adjustment of ₹' . number_format($amount, 2) . ' for ' . $supplier['name'] . ': ' . $reason
                    );
                    flash_set('Credit adjustment of ₹' . number_format($amount, 2) . ' recorded.');
                } else {
                    $txnId = $ledger->recordDebitAdjustment($organizationId, $supplierId, $amount, $reason, $user);
                    log_audit_event($pdo, $user, 'create', 'supplier_transaction', $txnId,
                        'Debit adjustment of ₹' . number_format($amount, 2) . ' for ' . $supplier['name'] . ': ' . $reason
                    );
                    flash_set('Debit adjustment of ₹' . number_format($amount, 2) . ' recorded.');
                }
                break;

            case 'opening_balance':
                $amount   = (float) ($_POST['amount'] ?? 0);
                $desc     = trim($_POST['description'] ?? '');
                $asOfDate = $_POST['as_of_date'] ?? date('Y-m-d');

                if ($amount <= 0) {
                    throw new RuntimeException('Enter a valid amount.');
                }

                $txnId = $ledger->recordOpeningBalance($organizationId, $supplierId, $amount, $desc, $asOfDate, $user);

                log_audit_event($pdo, $user, 'create', 'supplier_transaction', $txnId,
                    'Opening balance of ₹' . number_format($amount, 2) . ' for ' . $supplier['name']
                );
                flash_set('Opening balance of ₹' . number_format($amount, 2) . ' saved.');
                break;

            case 'reverse_transaction':
                $txnId  = (int) ($_POST['transaction_id'] ?? 0);
                $reason = trim($_POST['reversal_reason'] ?? '');

                if (!$txnId) {
                    throw new RuntimeException('Invalid transaction.');
                }
                if ($reason === '') {
                    throw new RuntimeException('Reason for reversal is required.');
                }

                $newTxnId = $ledger->reverseTransaction($organizationId, $txnId, $reason, $user);

                log_audit_event($pdo, $user, 'create', 'supplier_transaction', $newTxnId,
                    'Reversed supplier transaction #' . $txnId . ': ' . $reason
                );
                flash_set('Transaction reversed successfully.');
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
                flash_set('Payment plan created.');
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
                flash_set('Plan status updated to ' . $newStatus . '.');
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }

        $pdo->commit();
        header('Location: /supplier.php?id=' . $supplierId . '&tab=' . urlencode($activeTab));
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

// Quick preset date handling
$periodPreset = $_GET['preset'] ?? '';
$dateFrom     = $_GET['from'] ?? null;
$dateTo       = $_GET['to'] ?? null;
$typeFilter   = $_GET['type'] ?? null;

if ($periodPreset === 'this_month') {
    $dateFrom = date('Y-m-01');
    $dateTo   = date('Y-m-t');
} elseif ($periodPreset === 'last_30') {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
    $dateTo   = date('Y-m-d');
} elseif ($periodPreset === 'this_fy') {
    $curMonth = (int) date('n');
    $curYear  = (int) date('Y');
    $fyStartYear = $curMonth >= 4 ? $curYear : $curYear - 1;
    $dateFrom = $fyStartYear . '-04-01';
    $dateTo   = ($fyStartYear + 1) . '-03-31';
}

$ledgerPage = max(1, (int) ($_GET['lp'] ?? 1));
$perPage    = 25;
$ledgerOffset = ($ledgerPage - 1) * $perPage;

[$ledgerEntries, $ledgerTotal] = $ledger->getLedgerEntries(
    $organizationId, $supplierId,
    $dateFrom, $dateTo, $typeFilter,
    $perPage, $ledgerOffset
);

$ledgerTotalPages = max(1, (int) ceil($ledgerTotal / $perPage));

$allPurchases    = $ledger->getAllPurchases($organizationId, $supplierId);
$unpaidPurchases = $ledger->getUnpaidPurchases($organizationId, $supplierId);
$paymentPlans    = $ledger->getPaymentPlans($organizationId, $supplierId);

$txnTypeLabels = [
    'OPENING_BALANCE'    => 'Opening Balance',
    'PURCHASE'           => 'Purchase',
    'PAYMENT'            => 'Payment',
    'PURCHASE_RETURN'    => 'Return',
    'CREDIT_ADJUSTMENT'  => 'Credit (Disc/Return)',
    'DEBIT_ADJUSTMENT'   => 'Debit (Charge)',
    'REFUND'             => 'Refund',
    'PAYMENT_REVERSAL'   => 'Pay. Reversal',
    'PURCHASE_REVERSAL'  => 'Pur. Reversal'
];

$txnTypeBadge = [
    'OPENING_BALANCE'    => 'badge-neutral',
    'PURCHASE'           => 'badge-danger',
    'PAYMENT'            => 'badge-success',
    'PURCHASE_RETURN'    => 'badge-success',
    'CREDIT_ADJUSTMENT'  => 'badge-success',
    'DEBIT_ADJUSTMENT'   => 'badge-warning',
    'REFUND'             => 'badge-success',
    'PAYMENT_REVERSAL'   => 'badge-danger',
    'PURCHASE_REVERSAL'  => 'badge-danger'
];

function frequency_label(string $freq): string {
    return match ($freq) {
        'weekly'    => 'Weekly',
        'monthly'   => 'Monthly',
        'quarterly' => 'Quarterly',
        default     => ucfirst($freq)
    };
}

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

$outstandingBal = (float) $summary['outstanding_balance'];
$isPayable = $outstandingBal > 0.009;

// WhatsApp text generator
$waPhone = preg_replace('/[^0-9]/', '', $supplier['phone'] ?? '');
if ($waPhone && strlen($waPhone) === 10) {
    $waPhone = '91' . $waPhone;
}
$orgName = $user['organization_name'] ?? 'GarageOS';
$waText = "Hello {$supplier['name']},\n\nAccount Summary from {$orgName}:\n"
    . "• Total Purchases: ₹" . number_format((float) $summary['total_purchases'], 2) . "\n"
    . "• Total Paid: ₹" . number_format((float) $summary['total_payments'], 2) . "\n"
    . "• Outstanding Balance: ₹" . number_format($outstandingBal, 2) . "\n\n"
    . "As of: " . date('d M Y') . "\nThank you!";
$waUrl = "https://wa.me/{$waPhone}?text=" . urlencode($waText);

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
        /* Modern, app-consistent tab bar */
        .tab-bar-nav {
            display: flex;
            gap: 6px;
            margin-top: 20px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0;
            overflow-x: auto;
        }
        .tab-nav-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 500;
            color: var(--muted);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .tab-nav-item:hover {
            color: var(--text);
        }
        .tab-nav-item.active {
            color: var(--text);
            font-weight: 600;
            border-bottom-color: var(--text);
        }
        .tab-nav-count {
            display: inline-block;
            padding: 1px 7px;
            font-size: 11px;
            border-radius: 10px;
            background: var(--surface-sunken);
            color: var(--muted);
            font-weight: 600;
        }
        .tab-nav-item.active .tab-nav-count {
            background: var(--text);
            color: var(--surface);
        }

        /* Chips for filtering */
        .chip-group {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-chip {
            padding: 4px 10px;
            border-radius: 20px;
            border: 1px solid var(--border);
            font-size: 12px;
            text-decoration: none;
            color: var(--text);
            background: var(--surface);
            transition: all 0.15s ease;
        }
        .filter-chip:hover {
            background: var(--surface-sunken);
        }
        .filter-chip.active {
            background: var(--text);
            color: var(--surface);
            border-color: var(--text);
            font-weight: 600;
        }

        /* Modal Segmented Tabs */
        .modal-tabs {
            display: flex;
            background: var(--surface-sunken);
            padding: 4px;
            border-radius: 8px;
            margin-bottom: 18px;
            gap: 4px;
        }
        .modal-tab-item {
            flex: 1;
            text-align: center;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 500;
            color: var(--muted);
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.15s ease;
            user-select: none;
        }
        .modal-tab-item.active {
            background: var(--surface);
            color: var(--text);
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .ledger-table td.debit  { color: var(--danger); font-weight: 600; }
        .ledger-table td.credit { color: var(--success); font-weight: 600; }

        .plan-item-card {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            background: var(--surface);
        }
        .plan-item-card .plan-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <a href="/suppliers.php" class="link-action" style="margin-bottom:14px; display:inline-flex; align-items:center; gap:6px;">
                <?= icon('arrow-left', 14) ?> Back to Suppliers
            </a>

            <!-- Page Title & Header Actions -->
            <div class="page-header" style="margin-bottom:18px;">
                <div>
                    <h1 class="page-title" style="display:flex; align-items:center; gap:10px;">
                        <?= htmlspecialchars($supplier['name']) ?>
                        <span class="badge badge-subtle" style="font-size:12px;"><?= htmlspecialchars($supplier['code']) ?></span>
                    </h1>
                    <p class="page-description">
                        <?= $supplier['phone'] ? '📞 ' . htmlspecialchars($supplier['phone']) : '' ?>
                        <?= $supplier['email'] ? ' · ✉️ ' . htmlspecialchars($supplier['email']) : '' ?>
                        <?= $supplier['gstin'] ? ' · GSTIN: ' . htmlspecialchars($supplier['gstin']) : '' ?>
                    </p>
                </div>

                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <?php if ($canFinance): ?>
                        <button type="button" class="button" onclick="openUnifiedModal('pay')">
                            <?= icon('plus', 16) ?> Record Transaction
                        </button>
                        <button type="button" class="button secondary" onclick="openModal('plan-modal')">
                            <?= icon('calendar', 16) ?> Payment Plan
                        </button>
                    <?php endif; ?>

                    <?php if ($supplier['phone']): ?>
                        <a href="<?= $waUrl ?>" target="_blank" rel="noopener" class="button secondary" title="Share balance statement via WhatsApp">
                            <?= brand_icon('whatsapp', 15) ?> Share Statement
                        </a>
                    <?php endif; ?>

                    <?php if ($canManage): ?>
                        <a href="/suppliers.php?edit=<?= (int) $supplier['id'] ?>" class="button secondary">
                            <?= icon('settings', 16) ?> Edit Profile
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="form-error" style="margin-bottom:16px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- ─── Consistent GarageOS Stats Row ─── -->
            <div class="stats">
                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Total Purchases</div>
                            <div class="stat-value">₹<?= number_format((float) $summary['total_purchases'], 2) ?></div>
                        </div>
                        <div class="stat-icon"><?= icon('truck', 17) ?></div>
                    </div>
                    <div class="stat-meta"><?= count($allPurchases) ?> total purchase bill<?= count($allPurchases) === 1 ? '' : 's' ?></div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Total Paid</div>
                            <div class="stat-value" style="color:var(--success);">₹<?= number_format((float) $summary['total_payments'], 2) ?></div>
                        </div>
                        <div class="stat-icon success"><?= icon('wallet', 17) ?></div>
                    </div>
                    <div class="stat-meta">Total amount settled</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Credits / Returns</div>
                            <div class="stat-value">₹<?= number_format((float) $summary['total_credits'], 2) ?></div>
                        </div>
                        <div class="stat-icon"><?= icon('receipt', 17) ?></div>
                    </div>
                    <div class="stat-meta">Discounts & adjustments</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Outstanding Due</div>
                            <div class="stat-value" style="color:<?= $isPayable ? 'var(--danger)' : 'var(--success)' ?>;">
                                ₹<?= number_format($outstandingBal, 2) ?>
                            </div>
                        </div>
                        <div class="stat-icon <?= $isPayable ? 'warning' : 'success' ?>"><?= icon($isPayable ? 'alert-triangle' : 'check-circle', 17) ?></div>
                    </div>
                    <div class="stat-meta <?= $isPayable ? 'warning' : '' ?>">
                        <?= $isPayable ? 'You owe this supplier' : 'All clear / Settled' ?>
                    </div>
                </div>
            </div>

            <!-- ─── Tab Navigation ─── -->
            <div class="tab-bar-nav">
                <a href="?id=<?= $supplierId ?>&tab=ledger" class="tab-nav-item <?= $activeTab === 'ledger' ? 'active' : '' ?>">
                    <?= icon('receipt', 15) ?>
                    Account Ledger
                    <span class="tab-nav-count"><?= $ledgerTotal ?></span>
                </a>
                <a href="?id=<?= $supplierId ?>&tab=bills" class="tab-nav-item <?= $activeTab === 'bills' ? 'active' : '' ?>">
                    <?= icon('truck', 15) ?>
                    Purchase Bills
                    <span class="tab-nav-count"><?= count($allPurchases) ?></span>
                </a>
                <a href="?id=<?= $supplierId ?>&tab=plans" class="tab-nav-item <?= $activeTab === 'plans' ? 'active' : '' ?>">
                    <?= icon('calendar', 15) ?>
                    Payment Plans
                    <span class="tab-nav-count"><?= count($paymentPlans) ?></span>
                </a>
                <a href="?id=<?= $supplierId ?>&tab=profile" class="tab-nav-item <?= $activeTab === 'profile' ? 'active' : '' ?>">
                    <?= icon('warehouse', 15) ?>
                    Supplier Profile
                </a>
            </div>

            <!-- ─── TAB 1: Statement & Ledger ─── -->
            <?php if ($activeTab === 'ledger'): ?>
                <div class="card">
                    <div class="card-header" style="flex-wrap:wrap; gap:12px; align-items:center;">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                            Transaction Statement & Ledger
                        </div>

                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <!-- Date Range Preset Chips -->
                            <div class="chip-group">
                                <a href="?id=<?= $supplierId ?>&tab=ledger" class="filter-chip <?= (!$periodPreset && !$dateFrom && !$dateTo) ? 'active' : '' ?>">All Time</a>
                                <a href="?id=<?= $supplierId ?>&tab=ledger&preset=this_month" class="filter-chip <?= $periodPreset === 'this_month' ? 'active' : '' ?>">This Month</a>
                                <a href="?id=<?= $supplierId ?>&tab=ledger&preset=last_30" class="filter-chip <?= $periodPreset === 'last_30' ? 'active' : '' ?>">Last 30 Days</a>
                                <a href="?id=<?= $supplierId ?>&tab=ledger&preset=this_fy" class="filter-chip <?= $periodPreset === 'this_fy' ? 'active' : '' ?>">This FY</a>
                            </div>

                            <a href="/api/supplier-statement-export.php?id=<?= $supplierId ?><?= $dateFrom ? '&from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&to=' . urlencode($dateTo) : '' ?>" class="button secondary sm">
                                <?= icon('box', 14) ?> Export CSV
                            </a>
                        </div>
                    </div>

                    <!-- Custom Filter Row -->
                    <div style="padding:12px 20px; background:var(--surface-sunken); border-bottom:1px solid var(--border);">
                        <form method="GET" action="" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                            <input type="hidden" name="id" value="<?= $supplierId ?>">
                            <input type="hidden" name="tab" value="ledger">
                            <label style="font-size:12px; color:var(--muted);">From:</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($dateFrom ?? '') ?>" style="padding:5px 8px; font-size:13px;">
                            <label style="font-size:12px; color:var(--muted);">To:</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($dateTo ?? '') ?>" style="padding:5px 8px; font-size:13px;">
                            <select name="type" style="padding:5px 8px; font-size:13px;">
                                <option value="">All transaction types</option>
                                <?php foreach ($txnTypeLabels as $code => $label): ?>
                                    <option value="<?= $code ?>" <?= $typeFilter === $code ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button secondary sm"><?= icon('search', 13) ?> Filter</button>
                            <?php if ($dateFrom || $dateTo || $typeFilter || $periodPreset): ?>
                                <a href="?id=<?= $supplierId ?>&tab=ledger" class="link-action" style="font-size:13px;">Reset</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="card-body" style="padding:0;">
                        <?php if (empty($ledgerEntries)): ?>
                            <div class="empty-state">
                                <?= icon('receipt', 28) ?>
                                No transactions recorded for this period.
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table ledger-table">
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Reference</th>
                                        <th>Description</th>
                                        <th style="text-align:right;">Debit (Payable +)</th>
                                        <th style="text-align:right;">Credit (Paid -)</th>
                                        <th style="text-align:right;">Balance</th>
                                        <?php if ($canFinance): ?>
                                            <th style="text-align:center; width:50px;"></th>
                                        <?php endif; ?>
                                    </tr>
                                    <?php foreach ($ledgerEntries as $entry): ?>
                                        <tr>
                                            <td style="white-space:nowrap; font-size:13px;"><?= htmlspecialchars(date('d M Y', strtotime($entry['transaction_date']))) ?></td>
                                            <td>
                                                <span class="badge <?= $txnTypeBadge[$entry['transaction_type']] ?? 'badge-neutral' ?>" style="font-size:11px;">
                                                    <?= htmlspecialchars($txnTypeLabels[$entry['transaction_type']] ?? $entry['transaction_type']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($entry['reference_type'] === 'purchase' && $entry['reference_id']): ?>
                                                    <a href="/purchases.php" class="link-action"><strong><?= htmlspecialchars($entry['reference_no'] ?? '—') ?></strong></a>
                                                <?php else: ?>
                                                    <strong><?= htmlspecialchars($entry['reference_no'] ?? '—') ?></strong>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:13px; color:var(--text);"><?= htmlspecialchars($entry['description']) ?></td>
                                            <td class="num <?= (float) $entry['debit'] > 0 ? 'debit' : '' ?>">
                                                <?= (float) $entry['debit'] > 0 ? '₹' . number_format((float) $entry['debit'], 2) : '—' ?>
                                            </td>
                                            <td class="num <?= (float) $entry['credit'] > 0 ? 'credit' : '' ?>">
                                                <?= (float) $entry['credit'] > 0 ? '₹' . number_format((float) $entry['credit'], 2) : '—' ?>
                                            </td>
                                            <td class="num" style="font-weight:700;">
                                                ₹<?= number_format((float) $entry['running_balance'], 2) ?>
                                            </td>
                                            <?php if ($canFinance): ?>
                                                <td style="text-align:center;">
                                                    <?php if (!str_ends_with($entry['transaction_type'], '_REVERSAL')): ?>
                                                        <button type="button" class="link-action" style="color:var(--muted); font-size:12px;" onclick="openReversalModal(<?= (int) $entry['id'] ?>, '<?= htmlspecialchars(addslashes($entry['reference_no'] ?? 'Txn #' . $entry['id'])) ?>')" title="Reverse transaction">
                                                            <?= icon('trash', 13) ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>

                            <?php if ($ledgerTotalPages > 1): ?>
                                <div style="display:flex; justify-content:center; align-items:center; gap:8px; padding:16px;">
                                    <?php
                                    $pageUrl = '?id=' . $supplierId . '&tab=ledger';
                                    if ($dateFrom) $pageUrl .= '&from=' . urlencode($dateFrom);
                                    if ($dateTo)   $pageUrl .= '&to=' . urlencode($dateTo);
                                    if ($typeFilter) $pageUrl .= '&type=' . urlencode($typeFilter);
                                    if ($periodPreset) $pageUrl .= '&preset=' . urlencode($periodPreset);
                                    ?>
                                    <?php if ($ledgerPage > 1): ?>
                                        <a href="<?= $pageUrl ?>&lp=<?= $ledgerPage - 1 ?>" class="button secondary sm">← Previous</a>
                                    <?php endif; ?>
                                    <span style="font-size:13px; color:var(--muted);">Page <?= $ledgerPage ?> of <?= $ledgerTotalPages ?></span>
                                    <?php if ($ledgerPage < $ledgerTotalPages): ?>
                                        <a href="<?= $pageUrl ?>&lp=<?= $ledgerPage + 1 ?>" class="button secondary sm">Next →</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ─── TAB 2: Purchase Bills ─── -->
            <?php if ($activeTab === 'bills'): ?>
                <div class="card">
                    <div class="card-header" style="justify-content:space-between;">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('truck', 15) ?></span>
                            Purchase Invoices & Orders
                        </div>
                        <a href="/purchase-new.php" class="button secondary sm"><?= icon('plus', 14) ?> New Purchase</a>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($allPurchases)): ?>
                            <div class="empty-state">
                                <?= icon('truck', 28) ?>
                                No purchase bills found for this supplier.
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr>
                                        <th>Purchase No</th>
                                        <th>Date</th>
                                        <th style="text-align:right;">Bill Total</th>
                                        <th style="text-align:right;">Paid</th>
                                        <th style="text-align:right;">Balance Due</th>
                                        <th>Status</th>
                                        <th style="text-align:right;"></th>
                                    </tr>
                                    <?php foreach ($allPurchases as $bill): ?>
                                        <?php
                                        $due = (float) $bill['balance_due'];
                                        $statusClass = match ($bill['payment_status']) {
                                            'paid'    => 'badge-success',
                                            'partial' => 'badge-warning',
                                            default   => 'badge-danger'
                                        };
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($bill['purchase_no']) ?></strong></td>
                                            <td style="font-size:13px;"><?= htmlspecialchars(date('d M Y', strtotime($bill['created_at']))) ?></td>
                                            <td class="num">₹<?= number_format((float) $bill['total'], 2) ?></td>
                                            <td class="num" style="color:var(--success);">₹<?= number_format((float) $bill['amount_paid'], 2) ?></td>
                                            <td class="num" style="font-weight:700; color: <?= $due > 0.009 ? 'var(--danger)' : 'var(--success)' ?>;">
                                                ₹<?= number_format($due, 2) ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= $statusClass ?>" style="font-size:11px;">
                                                    <?= ucfirst($bill['payment_status']) ?>
                                                </span>
                                            </td>
                                            <td style="text-align:right;">
                                                <?php if ($canFinance && $due > 0.009): ?>
                                                    <button type="button" class="button secondary sm" onclick="paySpecificBill(<?= (int) $bill['id'] ?>, <?= $due ?>, '<?= htmlspecialchars(addslashes($bill['purchase_no'])) ?>')">
                                                        <?= icon('wallet', 13) ?> Pay Bill
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
            <?php endif; ?>

            <!-- ─── TAB 3: Payment Plans ─── -->
            <?php if ($activeTab === 'plans'): ?>
                <div class="card">
                    <div class="card-header" style="justify-content:space-between;">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('calendar', 15) ?></span>
                            Scheduled Payment Plans & Installments
                        </div>
                        <?php if ($canFinance): ?>
                            <button type="button" class="button secondary sm" onclick="openModal('plan-modal')">
                                <?= icon('plus', 14) ?> Create Plan
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($paymentPlans)): ?>
                            <div class="empty-state">
                                <?= icon('calendar', 28) ?>
                                No active or past payment plans configured.
                            </div>
                        <?php else: ?>
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:14px;">
                                <?php foreach ($paymentPlans as $plan): ?>
                                    <?php
                                    $nextDate = next_payment_date($plan);
                                    $planStatusBadge = match ($plan['status']) {
                                        'active'    => 'badge-success',
                                        'paused'    => 'badge-warning',
                                        'completed' => 'badge-subtle',
                                        default     => 'badge-danger'
                                    };
                                    ?>
                                    <div class="plan-item-card">
                                        <div class="plan-top">
                                            <div>
                                                <strong style="font-size:16px;">₹<?= number_format((float) $plan['amount_per_installment'], 2) ?></strong>
                                                <span style="font-size:13px; color:var(--muted);">/ <?= frequency_label($plan['frequency']) ?></span>
                                            </div>
                                            <span class="badge <?= $planStatusBadge ?>" style="font-size:11px;">
                                                <?= ucfirst($plan['status']) ?>
                                            </span>
                                        </div>

                                        <div style="font-size:13px; color:var(--muted); margin-bottom:6px;">
                                            Start: <?= date('d M Y', strtotime($plan['start_date'])) ?>
                                            <?= $plan['end_date'] ? ' · End: ' . date('d M Y', strtotime($plan['end_date'])) : '' ?>
                                        </div>

                                        <?php if ($plan['payment_method']): ?>
                                            <div style="font-size:13px; color:var(--muted); margin-bottom:6px;">
                                                Preferred Method: <strong><?= strtoupper(str_replace('_', ' ', $plan['payment_method'])) ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($nextDate && $plan['status'] === 'active'): ?>
                                            <div style="margin-top:8px; padding:6px 10px; background:var(--surface-sunken); border-radius:6px; font-size:13px; font-weight:600;">
                                                🗓️ Next Due: <?= $nextDate ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($plan['notes']): ?>
                                            <p style="font-size:12px; color:var(--muted); margin-top:8px;"><?= htmlspecialchars($plan['notes']) ?></p>
                                        <?php endif; ?>

                                        <?php if ($canFinance): ?>
                                            <div style="margin-top:12px; display:flex; gap:8px; border-top:1px solid var(--border); padding-top:10px;">
                                                <?php if ($plan['status'] === 'active'): ?>
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
                                                <?php elseif ($plan['status'] === 'paused'): ?>
                                                    <form method="POST" action="" style="display:inline;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="update_plan_status">
                                                        <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                                                        <input type="hidden" name="plan_status" value="active">
                                                        <button type="submit" class="button secondary sm"><?= icon('trending-up', 12) ?> Resume</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ─── TAB 4: Supplier Profile ─── -->
            <?php if ($activeTab === 'profile'): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('warehouse', 15) ?></span>
                            Supplier Contact & Tax Information
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:24px;">
                            <div>
                                <p class="stat-label" style="margin-bottom:4px;">Full Name</p>
                                <p style="font-size:15px; font-weight:600; margin:0;"><?= htmlspecialchars($supplier['name']) ?></p>
                            </div>

                            <div>
                                <p class="stat-label" style="margin-bottom:4px;">Supplier Code</p>
                                <p style="font-size:15px; font-weight:600; margin:0;"><?= htmlspecialchars($supplier['code']) ?></p>
                            </div>

                            <div>
                                <p class="stat-label" style="margin-bottom:4px;">Phone Number</p>
                                <p style="font-size:15px; margin:0;">
                                    <?php if ($supplier['phone']): ?>
                                        <a href="tel:<?= htmlspecialchars($supplier['phone']) ?>" class="link-action" style="font-weight:600;"><?= htmlspecialchars($supplier['phone']) ?></a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div>
                                <p class="stat-label" style="margin-bottom:4px;">Email Address</p>
                                <p style="font-size:15px; margin:0;">
                                    <?php if ($supplier['email']): ?>
                                        <a href="mailto:<?= htmlspecialchars($supplier['email']) ?>" class="link-action"><?= htmlspecialchars($supplier['email']) ?></a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div>
                                <p class="stat-label" style="margin-bottom:4px;">GSTIN</p>
                                <p style="font-size:15px; font-weight:600; margin:0;"><?= htmlspecialchars($supplier['gstin'] ?: 'Unregistered') ?></p>
                            </div>

                            <div>
                                <p class="stat-label" style="margin-bottom:4px;">Supplier Since</p>
                                <p style="font-size:15px; margin:0;"><?= htmlspecialchars(date('d M Y', strtotime($supplier['created_at']))) ?></p>
                            </div>

                            <div style="grid-column: 1 / -1;">
                                <p class="stat-label" style="margin-bottom:4px;">Office / Warehouse Address</p>
                                <p style="font-size:14px; margin:0; line-height:1.5;"><?= $supplier['address'] ? nl2br(htmlspecialchars($supplier['address'])) : '<span class="muted">—</span>' ?></p>
                            </div>
                        </div>

                        <?php if ($canManage): ?>
                            <div style="margin-top:24px; border-top:1px solid var(--border); padding-top:16px;">
                                <a href="/suppliers.php?edit=<?= (int) $supplier['id'] ?>" class="button secondary">
                                    <?= icon('settings', 15) ?> Edit Profile Details
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  MODALS                                                         -->
<!-- ═══════════════════════════════════════════════════════════════ -->

<?php if ($canFinance): ?>

    <!-- 1. UNIFIED RECORD TRANSACTION MODAL -->
    <div class="modal-backdrop" id="unified-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('wallet', 16) ?></span>
                    Record Transaction
                </div>
                <button type="button" class="modal-close" data-close-modal="unified-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <!-- Segmented Tab Header -->
                <div class="modal-tabs">
                    <div class="modal-tab-item active" id="mtab-pay" onclick="switchModalTab('pay')">💸 Pay Supplier</div>
                    <div class="modal-tab-item" id="mtab-adj" onclick="switchModalTab('adj')">⚖️ Adjustment</div>
                    <div class="modal-tab-item" id="mtab-open" onclick="switchModalTab('open')">🏁 Opening Balance</div>
                </div>

                <!-- SUB-FORM 1: PAY SUPPLIER -->
                <form method="POST" action="" id="form-pay">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="record_payment">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Amount to Pay (₹)</label>
                            <input type="number" name="amount" id="pay-amount" min="0.01" step="0.01" required value="<?= $outstandingBal > 0 ? htmlspecialchars((string) $outstandingBal) : '' ?>">
                        </div>
                        <div class="form-field">
                            <label>Payment Method</label>
                            <select name="method" required>
                                <option value="cash">Cash</option>
                                <option value="upi" selected>UPI</option>
                                <option value="bank_transfer">Bank Transfer / NEFT</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Payment Date</label>
                            <input type="date" name="payment_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-field">
                            <label>Bill Allocation</label>
                            <select name="purchase_id" id="pay-purchase-id">
                                <option value="">Auto-Settle Oldest Bills First (FIFO)</option>
                                <?php foreach ($unpaidPurchases as $up): ?>
                                    <option value="<?= (int) $up['id'] ?>">
                                        <?= htmlspecialchars($up['purchase_no']) ?> (Due: ₹<?= number_format((float) $up['balance_due'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Reference / UTR / Txn No. (Optional)</label>
                            <input type="text" name="reference_no" placeholder="e.g. UTR12345678">
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top:16px;">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Record Payment</button>
                    </div>
                </form>

                <!-- SUB-FORM 2: ADJUSTMENT (CREDIT/DEBIT) -->
                <form method="POST" action="" id="form-adj" style="display:none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="adjustment">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Adjustment Type</label>
                            <select name="adjustment_type" required>
                                <option value="credit">Credit (Reduces what you owe — discount / return)</option>
                                <option value="debit">Debit (Increases what you owe — extra freight / charges)</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Amount (₹)</label>
                            <input type="number" name="amount" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-field">
                            <label>Reason / Note</label>
                            <input type="text" name="reason" required placeholder="e.g. Volume discount on brake pads, returned defective piece">
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top:16px;">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save Adjustment</button>
                    </div>
                </form>

                <!-- SUB-FORM 3: OPENING BALANCE -->
                <form method="POST" action="" id="form-open" style="display:none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="opening_balance">
                    <p class="muted" style="font-size:13px; margin-bottom:12px;">Set the amount owed prior to tracking in GarageOS.</p>
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Opening Amount Owed (₹)</label>
                            <input type="number" name="amount" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-field">
                            <label>As of Date</label>
                            <input type="date" name="as_of_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-field">
                            <label>Note (Optional)</label>
                            <input type="text" name="description" placeholder="Opening balance as per ledger">
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top:16px;">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Set Opening Balance</button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- 2. PAYMENT PLAN MODAL -->
    <div class="modal-backdrop" id="plan-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('calendar', 16) ?></span>
                    Schedule Payment Plan
                </div>
                <button type="button" class="modal-close" data-close-modal="plan-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_plan">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Installment Amount (₹)</label>
                            <input type="number" name="plan_amount" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-field">
                            <label>Frequency</label>
                            <select name="frequency" required>
                                <option value="monthly" selected>Monthly</option>
                                <option value="weekly">Weekly</option>
                                <option value="quarterly">Quarterly</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Preferred Payment Mode</label>
                            <select name="plan_method">
                                <option value="">Not specified</option>
                                <option value="upi">UPI</option>
                                <option value="bank_transfer">Bank Transfer / NEFT</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-field">
                            <label>End Date (Optional)</label>
                            <input type="date" name="end_date">
                        </div>
                        <div class="form-field">
                            <label>Total Planned Amount (Optional)</label>
                            <input type="number" name="total_planned" min="0" step="0.01">
                        </div>
                        <div class="form-field">
                            <label>Notes (Optional)</label>
                            <textarea name="plan_notes" placeholder="e.g. Settle ₹50,000 outstanding across 5 monthly installments"></textarea>
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top:16px;">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save Payment Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 3. REVERSAL CONFIRMATION MODAL -->
    <div class="modal-backdrop" id="reversal-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge" style="background:var(--warning-soft); color:var(--danger);"><?= icon('trash', 16) ?></span>
                    Reverse Transaction
                </div>
                <button type="button" class="modal-close" data-close-modal="reversal-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <p style="font-size:14px; margin-bottom:14px;">
                    Are you sure you want to reverse <strong id="reversal-ref-text">this entry</strong>?
                    This will post an opposing transaction to preserve audit integrity and reopen linked bills.
                </p>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reverse_transaction">
                    <input type="hidden" name="transaction_id" id="reversal-txn-id" value="">
                    <div class="form-field">
                        <label>Reason for Reversal</label>
                        <input type="text" name="reversal_reason" required placeholder="e.g. Wrong amount entered, duplicate entry">
                    </div>
                    <div class="form-actions" style="margin-top:16px;">
                        <button type="submit" class="button" style="background:var(--danger); border-color:var(--danger); color:#fff;">Confirm Reversal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
function switchModalTab(tabKey) {
    document.querySelectorAll('.modal-tab-item').forEach(el => el.classList.remove('active'));
    document.getElementById('mtab-' + tabKey).classList.add('active');

    document.getElementById('form-pay').style.display = tabKey === 'pay' ? 'block' : 'none';
    document.getElementById('form-adj').style.display = tabKey === 'adj' ? 'block' : 'none';
    document.getElementById('form-open').style.display = tabKey === 'open' ? 'block' : 'none';
}

function openUnifiedModal(tabKey) {
    switchModalTab(tabKey || 'pay');
    openModal('unified-modal');
}

function paySpecificBill(purchaseId, dueAmount, purchaseNo) {
    document.getElementById('pay-purchase-id').value = purchaseId;
    document.getElementById('pay-amount').value = dueAmount.toFixed(2);
    openUnifiedModal('pay');
}

function openReversalModal(txnId, refText) {
    document.getElementById('reversal-txn-id').value = txnId;
    document.getElementById('reversal-ref-text').innerText = refText;
    openModal('reversal-modal');
}
</script>

</body>
</html>
