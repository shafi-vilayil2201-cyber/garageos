<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'users.manage');

$organizationId = $user['organization_id'];
$employeeId = (int) ($_GET['id'] ?? 0);

$statement = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.phone, u.status, u.designation, u.joined_at,
        u.salary_type, u.salary_amount,
        STRING_AGG(r.name, ', ' ORDER BY r.name) AS role_names
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    WHERE u.id = :id AND u.organization_id = :organization_id
    GROUP BY u.id, u.name, u.email, u.phone, u.status, u.designation, u.joined_at, u.salary_type, u.salary_amount
");
$statement->execute(['id' => $employeeId, 'organization_id' => $organizationId]);
$employee = $statement->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: /employees.php');
    exit;
}

// This month's attendance — same query dashboard.php runs for the
// logged-in user's own "my attendance" cards, just parameterized to
// any employee instead of always $user['id'].
$statement = $pdo->prepare("
    SELECT status, COUNT(*) AS total
    FROM attendance
    WHERE user_id = :user_id
      AND work_date >= DATE_TRUNC('month', CURRENT_DATE)
      AND work_date < DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month'
    GROUP BY status
");
$statement->execute(['user_id' => $employeeId]);
$monthAttendance = ['present' => 0, 'absent' => 0, 'half_day' => 0];
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $monthAttendance[$row['status']] = (int) $row['total'];
}

// Performance — same catalog-Service + ad-hoc-part-labour union reports.php
// uses for technician productivity, narrowed to this one employee: an
// all-time total plus the 5 most recent job cards they billed work on.
$statement = $pdo->prepare("
    SELECT COUNT(*) AS jobs_count, COALESCE(SUM(combined.revenue), 0) AS revenue
    FROM (
        SELECT jci.job_card_id, (jci.price - jci.discount) AS revenue
        FROM job_card_items jci
        INNER JOIN job_cards jc ON jc.id = jci.job_card_id
        WHERE jc.organization_id = :organization_id AND jc.deleted_at IS NULL AND jci.technician_id = :employee_id

        UNION ALL

        SELECT jcp.job_card_id, (jcp.labour_charge * jcp.labour_quantity) AS revenue
        FROM job_card_parts jcp
        INNER JOIN job_cards jc ON jc.id = jcp.job_card_id
        WHERE jc.organization_id = :organization_id2 AND jc.deleted_at IS NULL AND jcp.technician_id = :employee_id2 AND jcp.labour_charge > 0
    ) combined
");
$statement->execute([
    'organization_id' => $organizationId,
    'employee_id' => $employeeId,
    'organization_id2' => $organizationId,
    'employee_id2' => $employeeId
]);
$performance = $statement->fetch(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("
    SELECT DISTINCT jc.id, jc.job_no, jc.status, jc.created_at, v.registration_no, c.name AS customer_name
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    INNER JOIN customers c ON c.id = jc.customer_id
    WHERE jc.organization_id = :organization_id
      AND jc.deleted_at IS NULL
      AND (
          EXISTS (SELECT 1 FROM job_card_items jci WHERE jci.job_card_id = jc.id AND jci.technician_id = :employee_id)
          OR EXISTS (SELECT 1 FROM job_card_parts jcp WHERE jcp.job_card_id = jc.id AND jcp.technician_id = :employee_id2)
      )
    ORDER BY jc.created_at DESC
    LIMIT 5
");
$statement->execute(['organization_id' => $organizationId, 'employee_id' => $employeeId, 'employee_id2' => $employeeId]);
$recentJobCards = $statement->fetchAll(PDO::FETCH_ASSOC);

// Payroll — the same payroll_runs rows payroll.php generates, just this
// employee's whole history instead of one org-wide month at a time.
$statement = $pdo->prepare("
    SELECT period_month, days_present, days_absent, days_half_day, gross_salary, deduction_amount, net_salary, generated_at
    FROM payroll_runs
    WHERE organization_id = :organization_id AND user_id = :user_id
    ORDER BY period_month DESC
    LIMIT 12
");
$statement->execute(['organization_id' => $organizationId, 'user_id' => $employeeId]);
$payrollHistory = $statement->fetchAll(PDO::FETCH_ASSOC);
$latestPayroll = $payrollHistory[0] ?? null;

// Recent activity — audit_logs where this employee was the actor (what
// they did), not where they were the subject (e.g. someone editing
// their profile shows up under the editor's own activity, not here).
$statement = $pdo->prepare("
    SELECT action, entity_type, description, created_at
    FROM audit_logs
    WHERE organization_id = :organization_id AND user_id = :user_id
    ORDER BY created_at DESC
    LIMIT 10
");
$statement->execute(['organization_id' => $organizationId, 'user_id' => $employeeId]);
$recentActivity = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'employees';
$topbarTitle = $employee['name'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — <?= htmlspecialchars($employee['name']) ?></title>

    <link rel="stylesheet" href="/css/app.css">
    <?= favicon_tag($user['organization_logo_url'] ?? null) ?>
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <a href="/employees.php" class="link-action" style="margin-bottom:16px;"><?= icon('arrow-left', 14) ?> Back to Employees</a>

            <div class="page-header page-header-tight">
                <div>
                    <h1 class="page-title">
                        <?= htmlspecialchars($employee['name']) ?>
                        <span class="badge badge-<?= $employee['status'] === 'active' ? 'ready' : 'cancelled' ?>"><?= $employee['status'] === 'active' ? 'Active' : 'Inactive' ?></span>
                    </h1>
                    <p class="page-description">
                        <?= htmlspecialchars($employee['designation'] ?: ($employee['role_names'] ?: 'No role assigned')) ?>
                        <?php if ($employee['joined_at']): ?>
                            &middot; Joined <?= htmlspecialchars(date('d M Y', strtotime($employee['joined_at']))) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="/users.php?edit=<?= (int) $employee['id'] ?>" class="button secondary"><?= icon('edit', 16) ?> Edit</a>
            </div>

            <div class="content-grid">

                <div class="stack">

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('person', 15) ?></span>
                                Contact
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="info-row"><span class="label">Email</span><span class="value"><?= htmlspecialchars($employee['email']) ?></span></div>
                            <?php if ($employee['phone']): ?>
                                <div class="info-row"><span class="label">Phone</span><span class="value"><?= htmlspecialchars($employee['phone']) ?></span></div>
                            <?php endif; ?>
                            <div class="info-row"><span class="label">Role</span><span class="value"><?= htmlspecialchars($employee['role_names'] ?: '—') ?></span></div>
                            <?php if ($employee['salary_type']): ?>
                                <div class="info-row">
                                    <span class="label">Salary</span>
                                    <span class="value">&#8377;<?= number_format((float) $employee['salary_amount'], 2) ?> <?= $employee['salary_type'] === 'monthly' ? '/ month' : '/ day' ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('calendar', 15) ?></span>
                                Attendance this month
                            </div>
                            <a href="/attendance.php" class="card-header-link">Mark attendance</a>
                        </div>
                        <div class="card-body">
                            <div class="stats" style="margin:0; grid-template-columns:repeat(3, 1fr);">
                                <div class="stat-card">
                                    <div class="stat-label">Present</div>
                                    <div class="stat-value"><?= $monthAttendance['present'] ?></div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-label">Absent</div>
                                    <div class="stat-value"><?= $monthAttendance['absent'] ?></div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-label">Half-day</div>
                                    <div class="stat-value"><?= $monthAttendance['half_day'] ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('trending-up', 15) ?></span>
                                Performance
                            </div>
                            <a href="/reports.php" class="card-header-link">Full report</a>
                        </div>
                        <div class="card-body">
                            <div class="stats" style="margin:0; grid-template-columns:repeat(2, 1fr);">
                                <div class="stat-card">
                                    <div class="stat-label">Job cards worked on</div>
                                    <div class="stat-value"><?= (int) $performance['jobs_count'] ?></div>
                                    <div class="stat-meta">All time</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-label">Revenue generated</div>
                                    <div class="stat-value">&#8377;<?= number_format((float) $performance['revenue'], 0) ?></div>
                                    <div class="stat-meta">All time</div>
                                </div>
                            </div>

                            <?php if (!empty($recentJobCards)): ?>
                                <div class="table-wrap" style="margin-top:16px;">
                                    <table class="data-table">
                                        <tr><th>Job #</th><th>Vehicle</th><th>Customer</th><th>Status</th></tr>
                                        <?php foreach ($recentJobCards as $job): ?>
                                            <tr>
                                                <td><a href="/job-card.php?id=<?= (int) $job['id'] ?>"><?= htmlspecialchars($job['job_no']) ?></a></td>
                                                <td><?= htmlspecialchars($job['registration_no']) ?></td>
                                                <td><?= htmlspecialchars($job['customer_name']) ?></td>
                                                <td><span class="badge badge-<?= htmlspecialchars($job['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $job['status'])) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="result-meta" style="margin-top:12px;">No job card work recorded yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="stack">

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('wallet', 15) ?></span>
                                Payroll
                            </div>
                            <a href="/payroll.php" class="card-header-link">Open Payroll</a>
                        </div>
                        <div class="card-body">
                            <?php if ($latestPayroll): ?>
                                <div class="info-row">
                                    <span class="label"><?= htmlspecialchars((new DateTime($latestPayroll['period_month']))->format('F Y')) ?></span>
                                    <span class="value"><strong>&#8377;<?= number_format((float) $latestPayroll['net_salary'], 2) ?></strong></span>
                                </div>
                                <p class="result-meta">Last generated <?= htmlspecialchars(date('d M Y', strtotime($latestPayroll['generated_at']))) ?></p>

                                <?php if (count($payrollHistory) > 1): ?>
                                    <div class="table-wrap" style="margin-top:12px;">
                                        <table class="data-table">
                                            <tr><th>Period</th><th>Net</th></tr>
                                            <?php foreach (array_slice($payrollHistory, 1) as $run): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars((new DateTime($run['period_month']))->format('M Y')) ?></td>
                                                    <td>&#8377;<?= number_format((float) $run['net_salary'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <?= icon('wallet', 24) ?>
                                    No payroll generated yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('clipboard-list', 15) ?></span>
                                Recent activity
                            </div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <?php if (empty($recentActivity)): ?>
                                <div class="empty-state">
                                    <?= icon('clipboard-list', 24) ?>
                                    No recorded activity yet.
                                </div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <?php foreach ($recentActivity as $activity): ?>
                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars($activity['description']) ?>
                                                    <div class="result-meta"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($activity['created_at']))) ?></div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
