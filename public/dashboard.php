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

// Job cards currently open (not delivered/cancelled)
$statement = $pdo->prepare("
    SELECT COUNT(*) FROM job_cards
    WHERE organization_id = :organization_id
      AND status NOT IN ('delivered', 'cancelled')
");
$statement->execute(['organization_id' => $organizationId]);
$openJobCards = (int) $statement->fetchColumn();

// Job cards delivered today
$statement = $pdo->prepare("
    SELECT COUNT(*) FROM job_cards
    WHERE organization_id = :organization_id
      AND closed_at::date = CURRENT_DATE
");
$statement->execute(['organization_id' => $organizationId]);
$deliveredToday = (int) $statement->fetchColumn();

// Revenue today (paid + partial invoices raised today)
$statement = $pdo->prepare("
    SELECT COALESCE(SUM(amount_paid), 0) FROM invoices
    WHERE organization_id = :organization_id
      AND created_at::date = CURRENT_DATE
");
$statement->execute(['organization_id' => $organizationId]);
$revenueToday = (float) $statement->fetchColumn();

// Low stock parts
$statement = $pdo->prepare("
    SELECT COUNT(*) FROM inventory i
    INNER JOIN parts p ON p.id = i.part_id
    WHERE p.organization_id = :organization_id
      AND i.branch_id = :branch_id
      AND i.quantity <= p.reorder_level
");
$statement->execute([
    'organization_id' => $organizationId,
    'branch_id' => $branchId
]);
$lowStockParts = (int) $statement->fetchColumn();

// Recent job cards
$statement = $pdo->prepare("
    SELECT
        jc.job_no,
        jc.status,
        v.registration_no,
        v.make,
        v.model,
        c.name AS customer_name,
        jc.created_at
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    INNER JOIN customers c ON c.id = jc.customer_id
    WHERE jc.organization_id = :organization_id
    ORDER BY jc.created_at DESC
    LIMIT 6
");
$statement->execute(['organization_id' => $organizationId]);
$recentJobCards = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'dashboard';
$topbarTitle = 'Dashboard';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Dashboard</title>

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
                    <h1 class="page-title">
                        Good day, <?= htmlspecialchars($user['name']) ?>
                    </h1>
                    <p class="page-description">
                        Here's what's happening at your workshop today.
                    </p>
                </div>

                <a href="/job-card-new.php" class="button">+ New Job Card</a>
            </div>

            <div class="stats">

                <div class="card stat-card">
                    <div class="stat-label">Open job cards</div>
                    <div class="stat-value"><?= $openJobCards ?></div>
                    <div class="stat-meta">Vehicles currently in the workshop</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-label">Delivered today</div>
                    <div class="stat-value"><?= $deliveredToday ?></div>
                    <div class="stat-meta">Job cards closed today</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-label">Revenue today</div>
                    <div class="stat-value">₹<?= number_format($revenueToday, 2) ?></div>
                    <div class="stat-meta">Payments collected today</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-label">Low stock parts</div>
                    <div class="stat-value"><?= $lowStockParts ?></div>
                    <div class="stat-meta <?= $lowStockParts > 0 ? 'warning' : '' ?>">
                        <?= $lowStockParts > 0 ? 'Need reordering' : 'All parts well stocked' ?>
                    </div>
                </div>

            </div>

            <div class="content-grid">

                <div class="card">
                    <div class="card-header">
                        Recent job cards
                        <a href="/job-cards.php" style="font-size:13px; font-weight:500; color: var(--muted);">View all</a>
                    </div>

                    <div class="card-body" style="padding:0;">
                        <?php if (empty($recentJobCards)): ?>
                            <div class="empty-state">
                                No job cards yet. Create your first one to get started.
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr>
                                        <th>Job #</th>
                                        <th>Vehicle</th>
                                        <th>Customer</th>
                                        <th>Status</th>
                                    </tr>
                                    <?php foreach ($recentJobCards as $job): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($job['job_no']) ?></td>
                                            <td>
                                                <?= htmlspecialchars($job['make'] . ' ' . $job['model']) ?>
                                                <div class="result-meta"><?= htmlspecialchars($job['registration_no']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($job['customer_name']) ?></td>
                                            <td>
                                                <span class="badge badge-<?= htmlspecialchars($job['status']) ?>">
                                                    <?= htmlspecialchars(str_replace('_', ' ', $job['status'])) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        Quick actions
                    </div>

                    <div class="card-body" style="display:flex; flex-direction:column; gap:10px;">
                        <a href="/job-card-new.php" class="button">+ New job card</a>
                        <a href="/customers.php" class="button secondary">Add a customer</a>
                        <a href="/parts.php" class="button secondary">Check parts stock</a>
                    </div>
                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
