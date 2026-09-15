<?php

require_once __DIR__ . '/../app/Auth/Auth.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'finance.view');

$organizationId = $user['organization_id'];
$periodStart = date('Y-m') . '-01';

// One prepare()/execute() per stat, following dashboard.php's existing
// idiom rather than one combined query — keeps each figure
// independently correct and easy to check.

$statement = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM payments
    WHERE organization_id = :organization_id
      AND created_at >= :period_start
      AND created_at < (:period_start_end::date + INTERVAL '1 month')
");
$statement->execute(['organization_id' => $organizationId, 'period_start' => $periodStart, 'period_start_end' => $periodStart]);
$monthRevenue = (float) $statement->fetchColumn();

$statement = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM expenses
    WHERE organization_id = :organization_id
      AND expense_date >= :period_start
      AND expense_date < (:period_start_end::date + INTERVAL '1 month')
");
$statement->execute(['organization_id' => $organizationId, 'period_start' => $periodStart, 'period_start_end' => $periodStart]);
$monthManualExpenses = (float) $statement->fetchColumn();

$statement = $pdo->prepare("
    SELECT COALESCE(SUM(total), 0) FROM purchases
    WHERE organization_id = :organization_id
      AND created_at >= :period_start
      AND created_at < (:period_start_end::date + INTERVAL '1 month')
");
$statement->execute(['organization_id' => $organizationId, 'period_start' => $periodStart, 'period_start_end' => $periodStart]);
$monthPurchases = (float) $statement->fetchColumn();

$statement = $pdo->prepare("
    SELECT COALESCE(SUM(net_salary), 0) FROM payroll_runs
    WHERE organization_id = :organization_id AND period_month = :period_start
");
$statement->execute(['organization_id' => $organizationId, 'period_start' => $periodStart]);
$monthPayroll = (float) $statement->fetchColumn();

$monthExpenses = $monthManualExpenses + $monthPurchases + $monthPayroll;
$monthNet = $monthRevenue - $monthExpenses;

$statement = $pdo->prepare("
    SELECT COALESCE(SUM(total - amount_paid), 0) FROM invoices
    WHERE organization_id = :organization_id AND status IN ('unpaid', 'partial')
");
$statement->execute(['organization_id' => $organizationId]);
$totalReceivable = (float) $statement->fetchColumn();

$statement = $pdo->prepare("
    SELECT COALESCE(SUM(total - amount_paid), 0) FROM purchases
    WHERE organization_id = :organization_id AND payment_status != 'paid'
");
$statement->execute(['organization_id' => $organizationId]);
$totalPayable = (float) $statement->fetchColumn();

$activeNav = 'finance_overview';
$topbarTitle = 'Finance';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Finance</title>

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
                    <h1 class="page-title">Finance</h1>
                    <p class="page-description"><?= htmlspecialchars((new DateTime($periodStart))->format('F Y')) ?> at a glance.</p>
                </div>
            </div>

            <div class="stats">

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Revenue</div>
                            <div class="stat-value">₹<?= number_format($monthRevenue, 2) ?></div>
                        </div>
                        <div class="stat-icon success"><?= icon('trending-up', 17) ?></div>
                    </div>
                    <div class="stat-meta">Received this month</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Expenses</div>
                            <div class="stat-value">₹<?= number_format($monthExpenses, 2) ?></div>
                        </div>
                        <div class="stat-icon"><?= icon('receipt', 17) ?></div>
                    </div>
                    <div class="stat-meta">Overheads + purchases + payroll</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Net</div>
                            <div class="stat-value">₹<?= number_format($monthNet, 2) ?></div>
                        </div>
                        <div class="stat-icon <?= $monthNet >= 0 ? 'success' : 'warning' ?>"><?= icon($monthNet >= 0 ? 'check-circle' : 'alert-triangle', 17) ?></div>
                    </div>
                    <div class="stat-meta">Revenue minus expenses</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Owed to you</div>
                            <div class="stat-value">₹<?= number_format($totalReceivable, 2) ?></div>
                        </div>
                        <div class="stat-icon <?= $totalReceivable > 0.009 ? 'warning' : '' ?>"><?= icon('alert-triangle', 17) ?></div>
                    </div>
                    <div class="stat-meta">Unpaid customer invoices</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">You owe</div>
                            <div class="stat-value">₹<?= number_format($totalPayable, 2) ?></div>
                        </div>
                        <div class="stat-icon <?= $totalPayable > 0.009 ? 'warning' : '' ?>"><?= icon('truck', 17) ?></div>
                    </div>
                    <div class="stat-meta">Unpaid supplier purchases</div>
                </div>

            </div>

            <div class="content-grid" style="grid-template-columns: repeat(4, 1fr);">

                <a href="/finance-revenue.php" class="card" style="padding:20px; display:block;">
                    <div class="card-header-title" style="margin-bottom:6px;">
                        <span class="icon-badge"><?= icon('trending-up', 15) ?></span>
                        Revenue
                    </div>
                    <p class="result-meta">Payments received from customers, and what's still outstanding.</p>
                </a>

                <a href="/finance-expenses.php" class="card" style="padding:20px; display:block;">
                    <div class="card-header-title" style="margin-bottom:6px;">
                        <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                        Expenses
                    </div>
                    <p class="result-meta">Rent, electricity, purchases, and payroll — everything going out.</p>
                </a>

                <a href="/finance-dues.php" class="card" style="padding:20px; display:block;">
                    <div class="card-header-title" style="margin-bottom:6px;">
                        <span class="icon-badge"><?= icon('alert-triangle', 15) ?></span>
                        Debt
                    </div>
                    <p class="result-meta">What you owe suppliers, and what customers owe you.</p>
                </a>

                <a href="/finance-pnl.php" class="card" style="padding:20px; display:block;">
                    <div class="card-header-title" style="margin-bottom:6px;">
                        <span class="icon-badge"><?= icon('chart', 15) ?></span>
                        P&amp;L
                    </div>
                    <p class="result-meta">Revenue, cost of goods, and operating expenses for the period.</p>
                </a>

            </div>

        </section>

    </main>

</div>

</body>
</html>
