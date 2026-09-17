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

$periodStart = $periodMonth . '-01';

// payments.created_at is the real source of truth for "when money came
// in" — unlike invoices.amount_paid, which is just a running total with
// no history of individual payment dates.
$statement = $pdo->prepare("
    SELECT p.created_at, p.method, p.amount, p.reference_no,
           i.invoice_no, c.name AS customer_name
    FROM payments p
    INNER JOIN invoices i ON i.id = p.invoice_id
    INNER JOIN customers c ON c.id = i.customer_id
    WHERE p.organization_id = :organization_id
      AND p.created_at >= :period_start
      AND p.created_at < (:period_start_end::date + INTERVAL '1 month')
    ORDER BY p.created_at DESC
");
$statement->execute([
    'organization_id' => $organizationId,
    'period_start' => $periodStart,
    'period_start_end' => $periodStart
]);
$paymentRows = $statement->fetchAll(PDO::FETCH_ASSOC);

$monthRevenue = array_sum(array_column($paymentRows, 'amount'));

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

$activeNav = 'finance_revenue';
$topbarTitle = 'Revenue';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Revenue</title>

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
                    <h1 class="page-title">Revenue</h1>
                    <p class="page-description">Money received from customers.</p>
                </div>
            </div>

            <div class="stats">
                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Revenue this month</div>
                            <div class="stat-value">₹<?= number_format($monthRevenue, 2) ?></div>
                        </div>
                        <div class="stat-icon success"><?= icon('trending-up', 17) ?></div>
                    </div>
                    <div class="stat-meta">Received in <?= htmlspecialchars((new DateTime($periodStart))->format('F Y')) ?></div>
                </div>
                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Outstanding receivables</div>
                            <div class="stat-value">₹<?= number_format($totalReceivable, 2) ?></div>
                        </div>
                        <div class="stat-icon <?= $totalReceivable > 0.009 ? 'warning' : '' ?>"><?= icon('alert-triangle', 17) ?></div>
                    </div>
                    <div class="stat-meta">Across <?= count($receivableRows) ?> unpaid invoice<?= count($receivableRows) === 1 ? '' : 's' ?></div>
                </div>
            </div>

            <div class="card" style="margin-top:16px;">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('trending-up', 15) ?></span>
                        Payments received
                    </div>
                    <form method="GET" action="">
                        <div class="header-date-wrap">
                            <button type="button" class="button secondary header-date-trigger" aria-label="Choose month" onclick="openDatePicker(this)"><?= icon('calendar', 16) ?> <?= htmlspecialchars((new DateTime($periodStart))->format('F Y')) ?></button>
                            <input type="month" name="month" value="<?= htmlspecialchars($periodMonth) ?>" onchange="this.form.submit()">
                        </div>
                    </form>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($paymentRows)): ?>
                        <div class="empty-state">
                            <?= icon('trending-up', 28) ?>
                            No payments received this month.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Method</th><th>Amount</th></tr>
                                <?php foreach ($paymentRows as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('d M Y', strtotime($row['created_at']))) ?></td>
                                        <td><?= htmlspecialchars($row['invoice_no']) ?></td>
                                        <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                        <td style="text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $row['method'])) ?></td>
                                        <td class="num">₹<?= number_format($row['amount'], 2) ?></td>
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
                        <span class="icon-badge"><?= icon('alert-triangle', 15) ?></span>
                        Unpaid &amp; partially paid invoices
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
                                    <tr>
                                        <td><?= htmlspecialchars($row['invoice_no']) ?></td>
                                        <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                        <td style="text-transform:capitalize;"><?= htmlspecialchars($row['status']) ?></td>
                                        <td class="num">₹<?= number_format($row['total'] - $row['amount_paid'], 2) ?></td>
                                        <td><a href="/invoice.php?id=<?= (int) $row['id'] ?>" class="button secondary">View</a></td>
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

</body>
</html>
