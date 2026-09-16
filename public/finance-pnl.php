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

$periodMonth = $_GET['month'] ?? date('Y-m');

if (!DateTime::createFromFormat('Y-m', $periodMonth)) {
    $periodMonth = date('Y-m');
}

$monthStart = $periodMonth . '-01';
$yearStart = substr($periodMonth, 0, 4) . '-01-01';

$expenseCategories = [
    'rent' => 'Rent',
    'electricity' => 'Electricity',
    'maintenance' => 'Maintenance',
    'misc' => 'Miscellaneous',
    'other' => 'Other'
];


// Pulls the same figures already proven correct on finance.php/
// finance-revenue.php/finance-expenses.php, but for two windows side
// by side (the selected month, and year-to-date through the end of
// that month) — a standard P&L presentation, not a new data model.
function calculate_pnl(PDO $pdo, int $organizationId, string $windowStart, string $windowEnd, array $expenseCategories): array
{
    $statement = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM payments
        WHERE organization_id = :organization_id AND created_at >= :window_start AND created_at < :window_end
    ");
    $statement->execute(['organization_id' => $organizationId, 'window_start' => $windowStart, 'window_end' => $windowEnd]);
    $revenue = (float) $statement->fetchColumn();

    $statement = $pdo->prepare("
        SELECT COALESCE(SUM(total), 0) FROM purchases
        WHERE organization_id = :organization_id AND created_at >= :window_start AND created_at < :window_end
    ");
    $statement->execute(['organization_id' => $organizationId, 'window_start' => $windowStart, 'window_end' => $windowEnd]);
    $costOfGoods = (float) $statement->fetchColumn();

    $statement = $pdo->prepare("
        SELECT COALESCE(SUM(net_salary), 0) FROM payroll_runs
        WHERE organization_id = :organization_id AND period_month >= :window_start AND period_month < :window_end
    ");
    $statement->execute(['organization_id' => $organizationId, 'window_start' => $windowStart, 'window_end' => $windowEnd]);
    $payroll = (float) $statement->fetchColumn();

    $statement = $pdo->prepare("
        SELECT category, COALESCE(SUM(amount), 0) AS total FROM expenses
        WHERE organization_id = :organization_id AND expense_date >= :window_start AND expense_date < :window_end
        GROUP BY category
    ");
    $statement->execute(['organization_id' => $organizationId, 'window_start' => $windowStart, 'window_end' => $windowEnd]);
    $byCategory = array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'total', 'category');

    $overheadLines = [];
    $overheadTotal = 0.0;

    foreach ($expenseCategories as $key => $label) {
        $amount = (float) ($byCategory[$key] ?? 0);
        $overheadLines[$label] = $amount;
        $overheadTotal += $amount;
    }

    $grossProfit = $revenue - $costOfGoods;
    $operatingExpenses = $payroll + $overheadTotal;
    $netProfit = $grossProfit - $operatingExpenses;

    return [
        'revenue' => $revenue,
        'cost_of_goods' => $costOfGoods,
        'gross_profit' => $grossProfit,
        'payroll' => $payroll,
        'overhead_lines' => $overheadLines,
        'operating_expenses' => $operatingExpenses,
        'net_profit' => $netProfit
    ];
}


$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

$month = calculate_pnl($pdo, $organizationId, $monthStart, $monthEnd, $expenseCategories);
$ytd = calculate_pnl($pdo, $organizationId, $yearStart, $monthEnd, $expenseCategories);

$activeNav = 'finance_pnl';
$topbarTitle = 'Profit & Loss';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Profit &amp; Loss</title>

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
                    <h1 class="page-title">Profit &amp; Loss</h1>
                    <p class="page-description">Revenue, cost of goods, and operating expenses for the period.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('chart', 15) ?></span>
                        <?= htmlspecialchars((new DateTime($monthStart))->format('F Y')) ?>
                    </div>
                    <form method="GET" action="">
                        <div class="header-date-wrap">
                            <button type="button" class="button secondary header-date-trigger" aria-label="Choose month" onclick="openDatePicker(this)"><?= icon('calendar', 16) ?> <?= htmlspecialchars((new DateTime($monthStart))->format('F Y')) ?></button>
                            <input type="month" name="month" value="<?= htmlspecialchars($periodMonth) ?>" onchange="this.form.submit()">
                        </div>
                    </form>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-wrap">
                        <table class="data-table">
                            <tr><th></th><th class="num">This month</th><th class="num">Year to date</th></tr>

                            <tr><td><strong>Revenue</strong></td><td class="num">₹<?= number_format($month['revenue'], 2) ?></td><td class="num">₹<?= number_format($ytd['revenue'], 2) ?></td></tr>

                            <tr><td style="padding-left:24px; color:var(--muted);">Cost of goods (parts purchased)</td><td class="num">₹<?= number_format($month['cost_of_goods'], 2) ?></td><td class="num">₹<?= number_format($ytd['cost_of_goods'], 2) ?></td></tr>

                            <tr style="border-top:1px solid var(--border);"><td><strong>Gross profit</strong></td><td class="num"><strong>₹<?= number_format($month['gross_profit'], 2) ?></strong></td><td class="num"><strong>₹<?= number_format($ytd['gross_profit'], 2) ?></strong></td></tr>

                            <tr><td style="padding-top:14px;"><strong>Operating expenses</strong></td><td class="num" style="padding-top:14px;"></td><td class="num" style="padding-top:14px;"></td></tr>

                            <tr><td style="padding-left:24px; color:var(--muted);">Payroll</td><td class="num">₹<?= number_format($month['payroll'], 2) ?></td><td class="num">₹<?= number_format($ytd['payroll'], 2) ?></td></tr>

                            <?php foreach ($expenseCategories as $label): ?>
                                <tr><td style="padding-left:24px; color:var(--muted);"><?= htmlspecialchars($label) ?></td><td class="num">₹<?= number_format($month['overhead_lines'][$label], 2) ?></td><td class="num">₹<?= number_format($ytd['overhead_lines'][$label], 2) ?></td></tr>
                            <?php endforeach; ?>

                            <tr style="border-top:1px solid var(--border);"><td><strong>Total operating expenses</strong></td><td class="num"><strong>₹<?= number_format($month['operating_expenses'], 2) ?></strong></td><td class="num"><strong>₹<?= number_format($ytd['operating_expenses'], 2) ?></strong></td></tr>

                            <tr style="border-top:2px solid var(--text); font-size:15px;"><td><strong>Net profit</strong></td><td class="num"><strong style="color:<?= $month['net_profit'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">₹<?= number_format($month['net_profit'], 2) ?></strong></td><td class="num"><strong style="color:<?= $ytd['net_profit'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">₹<?= number_format($ytd['net_profit'], 2) ?></strong></td></tr>

                        </table>
                    </div>
                </div>
            </div>

            <p class="result-meta" style="margin-top:12px;">
                Cost of goods is approximated as total parts purchased in the period, not parts actually consumed on job cards — a simplification common for small shops that don't track work-in-progress inventory separately.
            </p>

        </section>

    </main>

</div>

</body>
</html>
