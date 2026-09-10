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

// Appointments booked for today
$statement = $pdo->prepare("
    SELECT
        a.scheduled_at,
        v.registration_no, v.make, v.model,
        c.name AS customer_name
    FROM appointments a
    INNER JOIN vehicles v ON v.id = a.vehicle_id
    INNER JOIN customers c ON c.id = a.customer_id
    WHERE a.organization_id = :organization_id
      AND a.status IN ('scheduled', 'confirmed')
      AND a.scheduled_at::date = CURRENT_DATE
    ORDER BY a.scheduled_at
");
$statement->execute(['organization_id' => $organizationId]);
$todaysAppointments = $statement->fetchAll(PDO::FETCH_ASSOC);

// Reminders due within the next 7 days
$statement = $pdo->prepare("
    SELECT COUNT(*) FROM reminders
    WHERE organization_id = :organization_id
      AND status = 'pending'
      AND due_date <= CURRENT_DATE + INTERVAL '7 days'
");
$statement->execute(['organization_id' => $organizationId]);
$remindersDue = (int) $statement->fetchColumn();

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
                        <a href="/appointment-new.php" class="button secondary">Book an appointment</a>
                        <a href="/customers.php" class="button secondary">Add a customer</a>
                        <a href="/parts.php" class="button secondary">Check parts stock</a>
                    </div>
                </div>

            </div>

            <div class="content-grid">

                <div class="card">
                    <div class="card-header">
                        Today's appointments
                        <a href="/appointments.php" style="font-size:13px; font-weight:500; color: var(--muted);">View all</a>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($todaysAppointments)): ?>
                            <div class="empty-state">Nothing booked for today.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr><th>Time</th><th>Vehicle</th><th>Customer</th></tr>
                                    <?php foreach ($todaysAppointments as $appointment): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date('h:i A', strtotime($appointment['scheduled_at']))) ?></td>
                                            <td>
                                                <?= htmlspecialchars($appointment['registration_no']) ?>
                                                <div class="result-meta"><?= htmlspecialchars($appointment['make'] . ' ' . $appointment['model']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($appointment['customer_name']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        Reminders
                        <a href="/reminders.php" style="font-size:13px; font-weight:500; color: var(--muted);">View all</a>
                    </div>
                    <div class="card-body">
                        <div class="stat-value"><?= $remindersDue ?></div>
                        <p class="stat-meta <?= $remindersDue > 0 ? 'warning' : '' ?>">
                            <?= $remindersDue > 0 ? 'Due for service or documents in the next 7 days' : 'Nothing due this week' ?>
                        </p>
                    </div>
                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
