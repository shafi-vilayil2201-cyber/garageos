<?php

require_once __DIR__ . '/../app/Auth/Auth.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];

// Revenue collected per day, last 7 days
$statement = $pdo->prepare("
    SELECT p.created_at::date AS day, SUM(p.amount) AS total
    FROM payments p
    WHERE p.organization_id = :organization_id
      AND p.created_at >= CURRENT_DATE - INTERVAL '6 days'
    GROUP BY day
    ORDER BY day
");
$statement->execute(['organization_id' => $organizationId]);
$revenueByDay = $statement->fetchAll(PDO::FETCH_ASSOC);

$revenueMap = [];
foreach ($revenueByDay as $row) {
    $revenueMap[$row['day']] = (float) $row['total'];
}

$last7Days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $last7Days[$date] = $revenueMap[$date] ?? 0.0;
}
$maxRevenue = max([1, ...array_values($last7Days)]);

// Technician productivity — jobs completed and revenue generated
$statement = $pdo->prepare("
    SELECT
        u.name,
        COUNT(*) AS jobs_count,
        SUM(jci.price - jci.discount) AS revenue
    FROM job_card_items jci
    INNER JOIN job_cards jc ON jc.id = jci.job_card_id
    INNER JOIN users u ON u.id = jci.technician_id
    WHERE jc.organization_id = :organization_id
    GROUP BY u.name
    ORDER BY revenue DESC
");
$statement->execute(['organization_id' => $organizationId]);
$technicianStats = $statement->fetchAll(PDO::FETCH_ASSOC);

// Top parts used, all time
$statement = $pdo->prepare("
    SELECT p.name, SUM(jcp.quantity) AS quantity_used, SUM(jcp.quantity * jcp.unit_price) AS revenue
    FROM job_card_parts jcp
    INNER JOIN job_cards jc ON jc.id = jcp.job_card_id
    INNER JOIN parts p ON p.id = jcp.part_id
    WHERE jc.organization_id = :organization_id
    GROUP BY p.name
    ORDER BY quantity_used DESC
    LIMIT 10
");
$statement->execute(['organization_id' => $organizationId]);
$topParts = $statement->fetchAll(PDO::FETCH_ASSOC);

// Low stock parts
$statement = $pdo->prepare("
    SELECT p.name, p.sku, i.quantity, p.reorder_level
    FROM inventory i
    INNER JOIN parts p ON p.id = i.part_id
    WHERE p.organization_id = :organization_id
      AND i.branch_id = :branch_id
      AND i.quantity <= p.reorder_level
    ORDER BY i.quantity ASC
");
$statement->execute(['organization_id' => $organizationId, 'branch_id' => $branchId]);
$lowStock = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'reports';
$topbarTitle = 'Reports';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Reports</title>

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
                    <h1 class="page-title">Reports</h1>
                    <p class="page-description">Revenue, technician productivity, and parts usage.</p>
                </div>
            </div>

            <div class="content-grid" style="grid-template-columns: 1.4fr 1fr;">

                <div class="card">
                    <div class="card-header">Revenue — last 7 days</div>
                    <div class="card-body">
                        <?php if (array_sum($last7Days) == 0): ?>
                            <div class="empty-state">No payments recorded in the last 7 days.</div>
                        <?php else: ?>
                            <div style="display:flex; flex-direction:column; gap:10px;">
                                <?php foreach ($last7Days as $date => $amount): ?>
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <div style="width:64px; font-size:12.5px; color:var(--muted);">
                                            <?= htmlspecialchars(date('D, d M', strtotime($date))) ?>
                                        </div>
                                        <div style="flex:1; background:#ece9e2; border-radius:6px; overflow:hidden; height:22px;">
                                            <div style="width:<?= max(2, round($amount / $maxRevenue * 100)) ?>%; background:var(--primary); height:100%;"></div>
                                        </div>
                                        <div style="width:90px; text-align:right; font-weight:600; font-size:13.5px;">
                                            ₹<?= number_format($amount, 0) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Low stock</div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($lowStock)): ?>
                            <div class="empty-state">All parts well stocked.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr><th>Part</th><th>Stock</th></tr>
                                    <?php foreach ($lowStock as $part): ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($part['name']) ?>
                                                <div class="result-meta"><?= htmlspecialchars($part['sku']) ?></div>
                                            </td>
                                            <td class="stat-meta warning" style="font-weight:600;">
                                                <?= rtrim(rtrim(number_format($part['quantity'], 2), '0'), '.') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Technician productivity</div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($technicianStats)): ?>
                            <div class="empty-state">No services assigned to a technician yet.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr><th>Technician</th><th>Jobs</th><th>Revenue generated</th></tr>
                                    <?php foreach ($technicianStats as $stat): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($stat['name']) ?></td>
                                            <td><?= (int) $stat['jobs_count'] ?></td>
                                            <td>₹<?= number_format($stat['revenue'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Top parts used</div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($topParts)): ?>
                            <div class="empty-state">No parts consumed yet.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr><th>Part</th><th>Qty used</th><th>Revenue</th></tr>
                                    <?php foreach ($topParts as $part): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($part['name']) ?></td>
                                            <td><?= rtrim(rtrim(number_format($part['quantity_used'], 2), '0'), '.') ?></td>
                                            <td>₹<?= number_format($part['revenue'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
