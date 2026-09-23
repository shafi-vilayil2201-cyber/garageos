<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/VehicleIntake.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'dashboard.view');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];

$canManageJobCards = user_can($user, 'job_cards.manage');

if ($canManageJobCards) {

    $jobCardExtraFields = '
        <div class="form-grid single">
            <div class="form-field">
                <label for="jobcard-odometer_in">Odometer reading (km)</label>
                <input type="number" id="jobcard-odometer_in" name="odometer_in" min="0">
            </div>
            <div class="form-field">
                <label for="jobcard-customer_complaint">Customer complaint / request</label>
                <textarea id="jobcard-customer_complaint" name="customer_complaint" placeholder="e.g. Engine noise, brakes feel soft..."></textarea>
            </div>
        </div>
    ';

    $statement = $pdo->prepare("SELECT id, name FROM services WHERE organization_id = :organization_id AND status = 'active' ORDER BY name");
    $statement->execute(['organization_id' => $organizationId]);
    $dashboardServices = $statement->fetchAll(PDO::FETCH_ASSOC);

    $dashboardServiceOptions = '<option value="">Not decided yet</option>';
    foreach ($dashboardServices as $service) {
        $dashboardServiceOptions .= '<option value="' . (int) $service['id'] . '">' . htmlspecialchars($service['name']) . '</option>';
    }

    $appointmentExtraFields = '
        <div class="form-grid">
            <div class="form-field">
                <label for="appt-scheduled_date">Date</label>
                <input type="date" id="appt-scheduled_date" name="scheduled_date" required>
            </div>
            <div class="form-field">
                <label for="appt-scheduled_time">Time</label>
                <input type="time" id="appt-scheduled_time" name="scheduled_time" required>
            </div>
            <div class="form-field">
                <label for="appt-service_id">Service (optional)</label>
                <select id="appt-service_id" name="service_id">' . $dashboardServiceOptions . '</select>
            </div>
            <div class="form-field">
                <label for="appt-source">Booked via</label>
                <select id="appt-source" name="source">
                    <option value="phone">Phone</option>
                    <option value="walk_in">Walk-in</option>
                    <option value="landing_page">Landing page</option>
                </select>
            </div>
        </div>
        <div class="form-field" style="margin-top:16px;">
            <label for="appt-notes">Notes (optional)</label>
            <textarea id="appt-notes" name="notes"></textarea>
        </div>
    ';
}

// Job cards currently open (not delivered/cancelled)
$statement = $pdo->prepare("
    SELECT COUNT(*) FROM job_cards
    WHERE organization_id = :organization_id
      AND deleted_at IS NULL
      AND status NOT IN ('delivered', 'cancelled')
");
$statement->execute(['organization_id' => $organizationId]);
$openJobCards = (int) $statement->fetchColumn();

// Job cards delivered today
$statement = $pdo->prepare("
    SELECT COUNT(*) FROM job_cards
    WHERE organization_id = :organization_id
      AND deleted_at IS NULL
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
      AND jc.deleted_at IS NULL
    ORDER BY jc.created_at DESC
    LIMIT 6
");
$statement->execute(['organization_id' => $organizationId]);
$recentJobCards = $statement->fetchAll(PDO::FETCH_ASSOC);

// Someone who marks other people's attendance (Owner/Manager) wants a
// glance at today's team attendance, not a card about their own — they're
// the one doing the marking, not the one being tracked. Everyone else
// (Technician, Advisor, etc.) sees their own attendance/salary instead,
// since attendance.view_own is granted broadly to every role.
$myAttendance = null;
$myPayroll = null;
$teamAttendanceToday = null;

if (user_can($user, 'attendance.manage')) {

    $statement = $pdo->prepare("
        SELECT u.name, a.status
        FROM users u
        LEFT JOIN attendance a ON a.user_id = u.id AND a.work_date = CURRENT_DATE
        WHERE u.organization_id = :organization_id AND u.status = 'active' AND u.salary_type IS NOT NULL
        ORDER BY u.name
    ");
    $statement->execute(['organization_id' => $organizationId]);
    $teamAttendanceToday = $statement->fetchAll(PDO::FETCH_ASSOC);

} elseif (user_can($user, 'attendance.view_own')) {

    $statement = $pdo->prepare("
        SELECT status, COUNT(*) AS total
        FROM attendance
        WHERE user_id = :user_id
          AND work_date >= DATE_TRUNC('month', CURRENT_DATE)
          AND work_date < DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month'
        GROUP BY status
    ");
    $statement->execute(['user_id' => $user['id']]);
    $myAttendance = ['present' => 0, 'absent' => 0, 'half_day' => 0];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $myAttendance[$row['status']] = (int) $row['total'];
    }

    $statement = $pdo->prepare("
        SELECT days_present, days_absent, days_half_day, gross_salary, deduction_amount, net_salary
        FROM payroll_runs
        WHERE user_id = :user_id AND period_month = DATE_TRUNC('month', CURRENT_DATE)
    ");
    $statement->execute(['user_id' => $user['id']]);
    $myPayroll = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

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
                    <h1 class="page-title">
                        Good day, <?= htmlspecialchars($user['name']) ?>
                    </h1>
                    <p class="page-description">
                        Here's what's happening at your workshop today.
                    </p>
                </div>

                <?php if ($canManageJobCards): ?>
                    <button type="button" class="button" onclick="openModal('jobcard-modal')"><?= icon('plus', 16) ?> New Job Card</button>
                <?php endif; ?>
            </div>

            <div class="stats">

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Open job cards</div>
                            <div class="stat-value"><?= $openJobCards ?></div>
                        </div>
                        <div class="stat-icon"><?= icon('job-card', 17) ?></div>
                    </div>
                    <div class="stat-meta">Vehicles currently in the workshop</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Delivered today</div>
                            <div class="stat-value"><?= $deliveredToday ?></div>
                        </div>
                        <div class="stat-icon success"><?= icon('check-circle', 17) ?></div>
                    </div>
                    <div class="stat-meta">Job cards closed today</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Revenue today</div>
                            <div class="stat-value">₹<?= number_format($revenueToday, 2) ?></div>
                        </div>
                        <div class="stat-icon"><?= icon('wallet', 17) ?></div>
                    </div>
                    <div class="stat-meta">Payments collected today</div>
                </div>

                <div class="card stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-label">Low stock parts</div>
                            <div class="stat-value"><?= $lowStockParts ?></div>
                        </div>
                        <div class="stat-icon <?= $lowStockParts > 0 ? 'warning' : '' ?>"><?= icon('box', 17) ?></div>
                    </div>
                    <div class="stat-meta <?= $lowStockParts > 0 ? 'warning' : '' ?>">
                        <?= $lowStockParts > 0 ? 'Need reordering' : 'All parts well stocked' ?>
                    </div>
                </div>

            </div>

            <div class="content-grid">

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('job-card', 15) ?></span>
                            Recent job cards
                        </div>
                        <a href="/job-cards.php" class="card-header-link">View all</a>
                    </div>

                    <div class="card-body" style="padding:0;">
                        <?php if (empty($recentJobCards)): ?>
                            <div class="empty-state">
                                <?= icon('job-card', 28) ?>
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
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('sparkle', 15) ?></span>
                            Quick actions
                        </div>
                    </div>

                    <div class="card-body stack-sm">
                        <?php if ($canManageJobCards): ?>
                            <button type="button" class="button" onclick="openModal('jobcard-modal')"><?= icon('plus', 16) ?> New job card</button>
                            <button type="button" class="button secondary" onclick="openModal('appt-modal')"><?= icon('calendar', 16) ?> Book an appointment</button>
                        <?php endif; ?>
                        <?php if (user_can($user, 'customers.manage')): ?>
                            <a href="/customers.php" class="button secondary"><?= icon('person', 16) ?> Add a customer</a>
                        <?php endif; ?>
                        <?php if (user_can($user, 'parts.view')): ?>
                            <a href="/parts.php" class="button secondary"><?= icon('box', 16) ?> Check parts stock</a>
                        <?php endif; ?>
                        <?php if (!user_can($user, 'job_cards.manage') && !user_can($user, 'customers.manage') && !user_can($user, 'parts.view')): ?>
                            <p class="page-description">Nothing to act on from here — check Job Cards for what's assigned to you.</p>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <div class="content-grid">

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('calendar', 15) ?></span>
                            Today's appointments
                        </div>
                        <a href="/appointments.php" class="card-header-link">View all</a>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($todaysAppointments)): ?>
                            <div class="empty-state">
                                <?= icon('calendar', 28) ?>
                                Nothing booked for today.
                            </div>
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
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('bell', 15) ?></span>
                            Reminders
                        </div>
                        <a href="/reminders.php" class="card-header-link">View all</a>
                    </div>
                    <div class="card-body">
                        <div class="stat-top">
                            <div class="stat-value"><?= $remindersDue ?></div>
                            <div class="stat-icon <?= $remindersDue > 0 ? 'warning' : '' ?>"><?= icon($remindersDue > 0 ? 'alert-triangle' : 'check-circle', 17) ?></div>
                        </div>
                        <p class="stat-meta <?= $remindersDue > 0 ? 'warning' : '' ?>">
                            <?= $remindersDue > 0 ? 'Due for service or documents in the next 7 days' : 'Nothing due this week' ?>
                        </p>
                    </div>
                </div>

            </div>

            <?php if ($teamAttendanceToday !== null): ?>
                <div class="card" style="margin-top:20px;">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('calendar', 15) ?></span>
                            Today's staff attendance
                        </div>
                        <a href="/attendance.php" class="card-header-link">Mark attendance</a>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($teamAttendanceToday)): ?>
                            <div class="empty-state">
                                <?= icon('team', 28) ?>
                                No salaried staff yet — set a salary type for a user to start tracking attendance.
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr><th>Name</th><th>Today's status</th></tr>
                                    <?php foreach ($teamAttendanceToday as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['name']) ?></td>
                                            <td>
                                                <span class="badge badge-<?= $row['status'] ?? 'not_marked' ?>">
                                                    <?= htmlspecialchars($row['status'] ? str_replace('_', ' ', $row['status']) : 'Not marked') ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($myAttendance !== null): ?>
                <div class="card" style="margin-top:20px;">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('calendar', 15) ?></span>
                            My attendance — <?= htmlspecialchars(date('F Y')) ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="stats" style="margin:0;">
                            <div class="card stat-card">
                                <div class="stat-label">Present</div>
                                <div class="stat-value"><?= $myAttendance['present'] ?></div>
                            </div>
                            <div class="card stat-card">
                                <div class="stat-label">Absent</div>
                                <div class="stat-value"><?= $myAttendance['absent'] ?></div>
                            </div>
                            <div class="card stat-card">
                                <div class="stat-label">Half-day</div>
                                <div class="stat-value"><?= $myAttendance['half_day'] ?></div>
                            </div>
                            <?php if ($myPayroll): ?>
                                <div class="card stat-card">
                                    <div class="stat-label">Net salary so far</div>
                                    <div class="stat-value">&#8377;<?= number_format((float) $myPayroll['net_salary'], 2) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($myPayroll): ?>
                            <p class="stat-meta" style="margin-top:12px;">
                                &#8377;<?= number_format((float) $myPayroll['deduction_amount'], 2) ?> deducted for <?= rtrim(rtrim(number_format((float) $myPayroll['days_absent'], 1), '0'), '.') ?> absent day(s) and <?= rtrim(rtrim(number_format((float) $myPayroll['days_half_day'], 1), '0'), '.') ?> half-day(s) this month.
                            </p>
                        <?php else: ?>
                            <p class="stat-meta" style="margin-top:12px;">Payroll for this month hasn't been generated yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

<?php if ($canManageJobCards): ?>

    <div class="modal-backdrop" id="jobcard-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('job-card', 16) ?></span>
                    New Job Card
                </div>
                <button type="button" class="modal-close" data-close-modal="jobcard-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <?= vehicle_intake_form('jobcard', '/job-card-new.php', $jobCardExtraFields, 'check', 'Create job card') ?>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="appt-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('calendar', 16) ?></span>
                    New Appointment
                </div>
                <button type="button" class="modal-close" data-close-modal="appt-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <?= vehicle_intake_form('appt', '/appointment-new.php', $appointmentExtraFields, 'calendar', 'Book appointment') ?>
            </div>
        </div>
    </div>

    <script src="/js/modal.js"></script>
    <script src="/js/vehicle-intake.js"></script>
    <script>
        initVehicleIntake('jobcard');
        initVehicleIntake('appt');
    </script>

<?php endif; ?>

</body>
</html>
