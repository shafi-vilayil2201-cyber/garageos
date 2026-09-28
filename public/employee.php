<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Payroll.php';
require_once __DIR__ . '/../app/Domain/SalaryAdvance.php';
require_once __DIR__ . '/../app/Domain/Audit.php';
require_once __DIR__ . '/../app/Support/Flash.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

// Payroll managers need this page too (it's where advances are recorded),
// and the seeded Manager role has payroll.manage but not users.manage.
$canManageUsers = user_can($user, 'users.manage');
$canManagePayroll = user_can($user, 'payroll.manage');
if (!$canManageUsers && !$canManagePayroll) {
    require_permission($user, 'users.manage');
}

$organizationId = $user['organization_id'];
$employeeId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_advance') {

    csrf_verify();
    require_permission($user, 'payroll.manage');

    $method = $_POST['recovery_method'] ?? '';
    $note = trim($_POST['recovery_note'] ?? '') ?: null;

    $statement = $pdo->prepare("SELECT name FROM users WHERE id = :id AND organization_id = :organization_id");
    $statement->execute(['id' => $employeeId, 'organization_id' => $organizationId]);
    $targetName = $statement->fetchColumn();

    if (!$targetName || !in_array($method, ['repaid', 'written_off'], true)) {
        flash_set('Choose whether the advance was repaid or written off.', 'error');
    } else {
        try {
            $pdo->beginTransaction();
            $closedAmount = close_outstanding_advances($pdo, $organizationId, $employeeId, $method, $note, (int) $user['id']);
            if ($closedAmount > 0) {
                log_audit_event(
                    $pdo, $user, 'update', 'salary_advance', $employeeId,
                    ($method === 'repaid' ? 'Marked' : 'Wrote off') . ' ₹' . number_format($closedAmount, 2) . " outstanding advance for $targetName"
                    . ($method === 'repaid' ? ' as repaid outside payroll' : '') . ($note ? ": $note" : '')
                );
            }
            $pdo->commit();
            flash_set($closedAmount > 0
                ? '₹' . number_format($closedAmount, 2) . ' advance ' . ($method === 'repaid' ? 'marked as repaid.' : 'written off.')
                : 'There was no outstanding advance to close.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set(user_facing_error($e), 'error');
        }
    }

    header('Location: /employee.php?id=' . $employeeId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_advance') {

    csrf_verify();
    require_permission($user, 'payroll.manage');

    $advanceAmount = (float) ($_POST['amount'] ?? 0);
    $paidAtRaw = trim($_POST['paid_at'] ?? '');
    $paidAtParsed = $paidAtRaw !== '' ? DateTime::createFromFormat('!Y-m-d', $paidAtRaw) : false;
    $paidAt = ($paidAtParsed && $paidAtParsed->format('Y-m-d') === $paidAtRaw && $paidAtRaw <= date('Y-m-d')) ? $paidAtRaw : date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '') ?: null;

    // Only someone payroll can actually recover it from.
    $statement = $pdo->prepare("
        SELECT name FROM users
        WHERE id = :id AND organization_id = :organization_id AND status = 'active' AND salary_type IS NOT NULL
    ");
    $statement->execute(['id' => $employeeId, 'organization_id' => $organizationId]);
    $targetName = $statement->fetchColumn();

    if (!$targetName) {
        flash_set('Advances can only be recorded for active employees on salary.', 'error');
    } elseif ($advanceAmount > 0) {
        try {
            $pdo->beginTransaction();
            record_salary_advance($pdo, $organizationId, $employeeId, $advanceAmount, $paidAt, $notes, $user['id']);
            log_audit_event(
                $pdo, $user, 'create', 'salary_advance', $employeeId,
                "Recorded a ₹$advanceAmount advance for $targetName, recovered from their next payroll run"
            );
            $pdo->commit();
            flash_set("Advance recorded — it'll be deducted from {$targetName}'s next payroll run.");
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Salary advance failed: ' . $e->getMessage());
            flash_set('Failed to record advance — please check the amount and try again.', 'error');
        }
    }

    header('Location: /employee.php?id=' . $employeeId);
    exit;
}

$statement = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.phone, u.status, u.designation, u.joined_at, u.created_at,
        u.salary_type, u.salary_amount, u.salary_pay_day,
        STRING_AGG(r.name, ', ' ORDER BY r.name) AS role_names
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    WHERE u.id = :id AND u.organization_id = :organization_id
    GROUP BY u.id, u.name, u.email, u.phone, u.status, u.designation, u.joined_at, u.created_at, u.salary_type, u.salary_amount, u.salary_pay_day
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
    SELECT period_month, days_present, days_absent, days_half_day, gross_salary, deduction_amount, net_salary, generated_at, payment_status, paid_at
    FROM payroll_runs
    WHERE organization_id = :organization_id AND user_id = :user_id
    ORDER BY period_month DESC
    LIMIT 12
");
$statement->execute(['organization_id' => $organizationId, 'user_id' => $employeeId]);
$payrollHistory = $statement->fetchAll(PDO::FETCH_ASSOC);
$latestPayroll = $payrollHistory[0] ?? null;

// Always computed: an employee who has since gone inactive or come off
// salary can still owe an advance that no payroll run will recover.
$outstandingAdvance = outstanding_advance_for_user($pdo, $organizationId, $employeeId);
$isOnActivePayroll = $employee['salary_type'] && $employee['status'] === 'active';

$pendingPayrollPeriods = [];
if ($employee['salary_type'] && $employee['status'] === 'active') {
    $payDay = (int) ($employee['salary_pay_day'] ?? 1);
    $joinedAtOrCreated = $employee['joined_at'] ?? $employee['created_at'] ?? date('Y-m-d');
    $pendingPayrollPeriods = pending_payroll_periods($payDay, $joinedAtOrCreated, array_column($payrollHistory, 'period_month'));
}

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

            <?php if ($canManageUsers): ?>
                <a href="/employees.php" class="link-action" style="margin-bottom:16px;"><?= icon('arrow-left', 14) ?> Back to Employees</a>
            <?php else: ?>
                <a href="/payroll.php" class="link-action" style="margin-bottom:16px;"><?= icon('arrow-left', 14) ?> Back to Payroll</a>
            <?php endif; ?>

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
                <?php if ($canManageUsers): ?>
                    <a href="/users.php?edit=<?= (int) $employee['id'] ?>" class="button secondary"><?= icon('edit', 16) ?> Edit</a>
                <?php endif; ?>
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
                            <?php if ($isOnActivePayroll && $canManagePayroll): ?>
                                <button type="button" class="button secondary sm" onclick="openModal('record-advance-modal')"><?= icon('plus', 13) ?> Record advance</button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">

                            <?php if (!empty($pendingPayrollPeriods)): ?>
                                <div class="form-error" style="margin-bottom:12px;">
                                    <?= count($pendingPayrollPeriods) === 1 ? '1 pay cycle is' : count($pendingPayrollPeriods) . ' pay cycles are' ?> due —
                                    <a href="/payroll.php">generate it from Payroll</a>.
                                </div>
                            <?php endif; ?>

                            <?php if ($outstandingAdvance > 0): ?>
                                <div class="info-row">
                                    <span class="label">Outstanding advance</span>
                                    <span class="value">&#8377;<?= number_format($outstandingAdvance, 2) ?></span>
                                </div>
                                <?php if ($isOnActivePayroll): ?>
                                    <p class="result-meta">Recovered in full from the next payroll run.</p>
                                <?php else: ?>
                                    <div class="form-error" style="margin-top:6px;">
                                        No payroll run will recover this — <?= htmlspecialchars($employee['name']) ?> is
                                        <?= $employee['status'] !== 'active' ? 'inactive' : 'no longer on salary' ?>.
                                        If they paid it back or you're letting it go, close it below.
                                    </div>
                                <?php endif; ?>
                                <?php if ($canManagePayroll): ?>
                                    <button type="button" class="link-action" style="background:none; border:none; padding:0; margin-top:6px; cursor:pointer; font-size:12.5px;" onclick="openModal('close-advance-modal')">
                                        <?= icon('check-circle', 13) ?> Repaid outside payroll / write off
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($latestPayroll): ?>
                                <div class="info-row" style="margin-top:8px;">
                                    <span class="label"><?= htmlspecialchars(payroll_period_label($latestPayroll['period_month'])) ?></span>
                                    <span class="value"><strong>&#8377;<?= number_format((float) $latestPayroll['net_salary'], 2) ?></strong></span>
                                </div>
                                <p class="result-meta">
                                    <span class="badge badge-<?= $latestPayroll['payment_status'] === 'paid' ? 'ready' : 'in_progress' ?>"><?= $latestPayroll['payment_status'] === 'paid' ? 'Paid' : 'Unpaid' ?></span>
                                    <?= $latestPayroll['payment_status'] === 'paid' ? 'on ' . htmlspecialchars(date('d M Y', strtotime($latestPayroll['paid_at']))) : '— generated ' . htmlspecialchars(date('d M Y', strtotime($latestPayroll['generated_at']))) ?>
                                </p>

                                <?php if (count($payrollHistory) > 1): ?>
                                    <div class="table-wrap" style="margin-top:12px;">
                                        <table class="data-table">
                                            <tr><th>Period</th><th>Net</th><th>Status</th></tr>
                                            <?php foreach (array_slice($payrollHistory, 1) as $run): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars(payroll_period_label($run['period_month'])) ?></td>
                                                    <td>&#8377;<?= number_format((float) $run['net_salary'], 2) ?></td>
                                                    <td><span class="badge badge-<?= $run['payment_status'] === 'paid' ? 'ready' : 'in_progress' ?>"><?= $run['payment_status'] === 'paid' ? 'Paid' : 'Unpaid' ?></span></td>
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

<?php if ($outstandingAdvance > 0 && $canManagePayroll): ?>
    <div class="modal-backdrop" id="close-advance-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('wallet', 16) ?></span>
                    Close advance — <?= htmlspecialchars($employee['name']) ?>
                </div>
                <button type="button" class="modal-close" data-close-modal="close-advance-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close_advance">
                    <p class="result-meta">Outstanding: <strong>&#8377;<?= number_format($outstandingAdvance, 2) ?></strong>. Once closed, it won't be deducted from any payroll run.</p>
                    <div class="form-field">
                        <label>What happened?</label>
                        <label style="display:flex; gap:10px; align-items:center; justify-content:flex-start; font-weight:400; cursor:pointer;">
                            <input type="radio" name="recovery_method" value="repaid" required style="width:18px; height:18px; flex:none; margin:0; accent-color:var(--accent); cursor:pointer;">
                            <span>Repaid directly (cash / transfer)</span>
                        </label>
                        <label style="display:flex; gap:10px; align-items:center; justify-content:flex-start; font-weight:400; cursor:pointer;">
                            <input type="radio" name="recovery_method" value="written_off" style="width:18px; height:18px; flex:none; margin:0; accent-color:var(--accent); cursor:pointer;">
                            <span>Written off — won't be recovered</span>
                        </label>
                    </div>
                    <div class="form-field">
                        <label for="recovery-note">Note (optional)</label>
                        <input type="text" id="recovery-note" name="recovery_note" maxlength="300" placeholder="e.g. Paid back in cash on last day">
                    </div>
                    <div class="actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Close advance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($isOnActivePayroll && $canManagePayroll): ?>
    <div class="modal-backdrop" id="record-advance-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('wallet', 16) ?></span>
                    Record advance — <?= htmlspecialchars($employee['name']) ?>
                </div>
                <button type="button" class="modal-close" data-close-modal="record-advance-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="record_advance">
                    <div class="form-field">
                        <label for="advance-amount">Amount (&#8377;)</label>
                        <input type="number" id="advance-amount" name="amount" min="0.01" step="0.01" required>
                    </div>
                    <div class="form-field">
                        <label for="advance-paid-at">Paid on</label>
                        <input type="date" id="advance-paid-at" name="paid_at" value="<?= htmlspecialchars(date('Y-m-d')) ?>" max="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="advance-notes">Notes (optional)</label>
                        <input type="text" id="advance-notes" name="notes" placeholder="e.g. Medical expense">
                    </div>
                    <p class="result-meta">Recovered in full from <?= htmlspecialchars($employee['name']) ?>'s next payroll run — never split across multiple cycles.</p>
                    <div class="actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Record advance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($isOnActivePayroll && $canManagePayroll && isset($_GET['advance'])): ?>
    <script>openModal('record-advance-modal');</script>
<?php endif; ?>

</body>
</html>
