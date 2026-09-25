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

require_permission($user, 'payroll.manage');

$organizationId = $user['organization_id'];

// One employee's payroll run for one specific period — attendance,
// any outstanding advance, calculate_payroll(), upsert, settle the
// advance against the new run, audit-log it. Re-running this for a
// period that's already generated (but not yet paid) is how a mistake
// gets fixed — the upsert just recomputes it. Once paid, it's locked:
// this refuses outright rather than silently resurrecting an unpaid
// state on a run that's already been counted as a real expense.
//
// $payDay/$joinedAt are only used to verify $periodStart is actually
// legitimate for a *new* run — the "only available once it's due" rule
// used to live purely in what button the page rendered, which a direct
// POST past the UI could ignore entirely (confirmed: an arbitrary
// future period generated cleanly). A period that already has a run is
// always allowed through here regardless of due-ness — it was
// legitimate when first created, and recalculating it is a correction,
// not a new grant.
function generate_payroll_run(PDO $pdo, array $user, int $organizationId, array $salariedUser, string $periodStart, string $periodEnd, int $payDay, string $joinedAt): bool
{
    $statement = $pdo->prepare("
        SELECT id, payment_status FROM payroll_runs
        WHERE organization_id = :organization_id AND user_id = :user_id AND period_month = :period_month
    ");
    $statement->execute(['organization_id' => $organizationId, 'user_id' => $salariedUser['id'], 'period_month' => $periodStart]);
    $existing = $statement->fetch(PDO::FETCH_ASSOC);

    if ($existing && $existing['payment_status'] === 'paid') {
        return false;
    }

    if (!$existing) {
        $periodsStatement = $pdo->prepare("SELECT period_month FROM payroll_runs WHERE organization_id = :organization_id AND user_id = :user_id");
        $periodsStatement->execute(['organization_id' => $organizationId, 'user_id' => $salariedUser['id']]);
        $generatedPeriods = $periodsStatement->fetchAll(PDO::FETCH_COLUMN);

        $duePeriodStarts = array_column(pending_payroll_periods($payDay, $joinedAt, $generatedPeriods), 'start');

        if (!in_array($periodStart, $duePeriodStarts, true)) {
            return false;
        }
    } else {
        // Recalculating: whatever this run previously settled goes back
        // into the outstanding pool, so it's correctly included in the
        // fresh sum below instead of being replaced by it.
        unsettle_advances_for_run($pdo, (int) $existing['id']);
    }

    $daysInPeriod = (new DateTime($periodStart))->diff(new DateTime($periodEnd))->days;

    $statement = $pdo->prepare("
        SELECT status FROM attendance
        WHERE organization_id = :organization_id AND user_id = :user_id AND work_date >= :period_start AND work_date < :period_end
    ");
    $statement->execute([
        'organization_id' => $organizationId,
        'user_id' => $salariedUser['id'],
        'period_start' => $periodStart,
        'period_end' => $periodEnd
    ]);
    $attendanceRows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $advanceAmount = outstanding_advance_for_user($pdo, $organizationId, (int) $salariedUser['id']);
    $result = calculate_payroll($salariedUser, $attendanceRows, $daysInPeriod, $advanceAmount);

    $statement = $pdo->prepare("
        INSERT INTO payroll_runs (
            organization_id, user_id, period_month, days_in_period,
            days_present, days_absent, days_half_day,
            gross_salary, deduction_amount, advance_deducted, net_salary, generated_by
        ) VALUES (
            :organization_id, :user_id, :period_month, :days_in_period,
            :days_present, :days_absent, :days_half_day,
            :gross_salary, :deduction_amount, :advance_deducted, :net_salary, :generated_by
        )
        ON CONFLICT (user_id, period_month) DO UPDATE SET
            days_in_period = EXCLUDED.days_in_period,
            days_present = EXCLUDED.days_present,
            days_absent = EXCLUDED.days_absent,
            days_half_day = EXCLUDED.days_half_day,
            gross_salary = EXCLUDED.gross_salary,
            deduction_amount = EXCLUDED.deduction_amount,
            advance_deducted = EXCLUDED.advance_deducted,
            net_salary = EXCLUDED.net_salary,
            generated_by = EXCLUDED.generated_by,
            generated_at = CURRENT_TIMESTAMP
        RETURNING id
    ");
    $statement->execute([
        'organization_id' => $organizationId,
        'user_id' => $salariedUser['id'],
        'period_month' => $periodStart,
        'days_in_period' => $daysInPeriod,
        'days_present' => $result['days_present'],
        'days_absent' => $result['days_absent'],
        'days_half_day' => $result['days_half_day'],
        'gross_salary' => $result['gross_salary'],
        'deduction_amount' => $result['deduction_amount'],
        'advance_deducted' => $result['advance_deducted'],
        'net_salary' => $result['net_salary'],
        'generated_by' => $user['id']
    ]);
    $payrollRunId = (int) $statement->fetchColumn();

    if ($advanceAmount > 0) {
        settle_outstanding_advances($pdo, $organizationId, (int) $salariedUser['id'], $payrollRunId);
    }

    log_audit_event(
        $pdo, $user, $existing ? 'update' : 'create', 'payroll_run', $payrollRunId,
        ($existing ? 'Regenerated' : 'Generated') . " payroll for {$salariedUser['name']}, " . (new DateTime($periodStart))->format('d M') . '–' . (new DateTime($periodEnd))->modify('-1 day')->format('d M Y')
    );

    return true;
}

// Marks an unpaid, already-generated run as paid — the point at which
// finance.php / finance-pnl.php / finance-expenses.php start counting
// it (see migration 054). Refuses a run that's already paid, same
// belt-and-suspenders guard as generate_payroll_run() above.
function mark_payroll_paid(PDO $pdo, array $user, int $organizationId, int $payrollRunId): bool
{
    $statement = $pdo->prepare("
        UPDATE payroll_runs
        SET payment_status = 'paid', paid_at = CURRENT_TIMESTAMP, paid_by = :paid_by
        WHERE id = :id AND organization_id = :organization_id AND payment_status = 'unpaid'
        RETURNING user_id, period_month, net_salary
    ");
    $statement->execute(['paid_by' => $user['id'], 'id' => $payrollRunId, 'organization_id' => $organizationId]);
    $paidRun = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$paidRun) {
        return false;
    }

    $statement = $pdo->prepare("SELECT name FROM users WHERE id = :id");
    $statement->execute(['id' => $paidRun['user_id']]);
    $employeeName = $statement->fetchColumn();

    log_audit_event(
        $pdo, $user, 'update', 'payroll_run', $payrollRunId,
        "Marked {$employeeName}'s payroll for " . payroll_period_label($paidRun['period_month']) . ' as paid (₹' . number_format((float) $paidRun['net_salary'], 2) . ')'
    );

    return true;
}

$generatedCount = 0;
$paidCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'payroll.manage');

    $action = $_POST['action'] ?? '';

    $usersStatement = $pdo->prepare("
        SELECT id, name, salary_type, salary_amount, salary_pay_day, joined_at, created_at
        FROM users
        WHERE organization_id = :organization_id AND status = 'active' AND salary_type IS NOT NULL
    ");
    $usersStatement->execute(['organization_id' => $organizationId]);
    $salariedUsersById = array_column($usersStatement->fetchAll(PDO::FETCH_ASSOC), null, 'id');

    $pdo->beginTransaction();

    try {
        if ($action === 'generate_one') {

            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            $periodStart = trim($_POST['period_start'] ?? '');

            if (isset($salariedUsersById[$targetUserId]) && DateTime::createFromFormat('Y-m-d', $periodStart)) {
                $targetUser = $salariedUsersById[$targetUserId];
                $targetPayDay = (int) ($targetUser['salary_pay_day'] ?? 1);
                $targetJoinedAt = $targetUser['joined_at'] ?? $targetUser['created_at'];

                if (generate_payroll_run($pdo, $user, $organizationId, $targetUser, $periodStart, payroll_period_end($periodStart), $targetPayDay, $targetJoinedAt)) {
                    $generatedCount = 1;
                }
            }

        } elseif ($action === 'mark_paid') {

            $payrollRunId = (int) ($_POST['payroll_run_id'] ?? 0);

            if (mark_payroll_paid($pdo, $user, $organizationId, $payrollRunId)) {
                $paidCount = 1;
            }
        }

        $pdo->commit();

        if ($generatedCount > 0) {
            flash_set('Salary generated.');
        } elseif ($paidCount > 0) {
            flash_set('Marked as paid.');
        }

        header('Location: /payroll.php');
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Payroll action failed: ' . $e->getMessage());
    }
}

// Every active salaried employee, their own next-due period (if any),
// and their most recently generated payroll run — everything each
// card needs to decide which single action (Generate Salary / Mark as
// Paid / nothing yet) to show, in three queries instead of one per
// employee.
$statement = $pdo->prepare("
    SELECT id, name, designation, salary_type, salary_amount, salary_pay_day, joined_at, created_at
    FROM users
    WHERE organization_id = :organization_id AND status = 'active' AND salary_type IS NOT NULL
    ORDER BY name
");
$statement->execute(['organization_id' => $organizationId]);
$salariedUsers = $statement->fetchAll(PDO::FETCH_ASSOC);

$latestRunByUser = [];
$historyByUser = [];
$nextDuePeriodByUser = [];
$nextAvailableByUser = [];

if (!empty($salariedUsers)) {

    $userIds = array_column($salariedUsers, 'id');
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    $statement = $pdo->prepare("
        SELECT id, user_id, period_month, gross_salary, deduction_amount, advance_deducted, net_salary,
            payment_status, paid_at, generated_at, days_present, days_absent, days_half_day
        FROM payroll_runs
        WHERE organization_id = ? AND user_id IN ($placeholders)
        ORDER BY period_month DESC
    ");
    $statement->execute([$organizationId, ...$userIds]);

    $generatedPeriodsByUser = [];

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = (int) $row['user_id'];
        $historyByUser[$uid][] = $row;
        $generatedPeriodsByUser[$uid][] = $row['period_month'];

        if (!isset($latestRunByUser[$uid])) {
            $latestRunByUser[$uid] = $row;
        }
    }

    foreach ($salariedUsers as $salariedUser) {
        $uid = (int) $salariedUser['id'];
        $payDay = (int) ($salariedUser['salary_pay_day'] ?? 1);
        $joinedAt = $salariedUser['joined_at'] ?? $salariedUser['created_at'];
        $generatedPeriods = $generatedPeriodsByUser[$uid] ?? [];

        // Oldest due period only — one card, one action at a time. If
        // an employee is behind by more than one cycle, resolving this
        // one reveals the next on the following page load.
        $pending = pending_payroll_periods($payDay, $joinedAt, $generatedPeriods);
        $nextDuePeriodByUser[$uid] = $pending[0] ?? null;
        $nextAvailableByUser[$uid] = next_payroll_period_end($payDay, $joinedAt, $generatedPeriods);
    }
}

$activeNav = 'payroll';
$topbarTitle = 'Payroll';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Payroll</title>

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
                    <h1 class="page-title">Payroll</h1>
                    <p class="page-description">Each employee's own card — generate their salary once their pay date arrives, then mark it paid.</p>
                </div>
            </div>

            <?php if (empty($salariedUsers)): ?>
                <div class="card">
                    <div class="empty-state">
                        <?= icon('wallet', 28) ?>
                        No salaried staff yet — set a salary for someone from <a href="/users.php">Users</a>.
                    </div>
                </div>
            <?php else: ?>
                <div class="settings-grid">
                    <?php foreach ($salariedUsers as $salariedUser): ?>
                        <?php
                            $uid = (int) $salariedUser['id'];
                            $latest = $latestRunByUser[$uid] ?? null;
                            $duePeriod = $nextDuePeriodByUser[$uid];
                            $modalId = 'payroll-modal-' . $uid;
                        ?>
                        <div class="card">
                            <div class="card-body">
                                <div class="employee-card-top">
                                    <div class="employee-card-name"><?= htmlspecialchars($salariedUser['name']) ?></div>
                                    <button type="button" class="card-header-icon-button" onclick="openModal('<?= $modalId ?>')" title="View history"><?= icon('wallet', 15) ?></button>
                                </div>
                                <div class="result-meta"><?= $salariedUser['salary_type'] === 'monthly' ? 'Monthly' : 'Daily wage' ?></div>

                                <?php if ($latest): ?>
                                    <div style="margin-top:12px;">
                                        <div class="info-row">
                                            <span class="label"><?= htmlspecialchars(payroll_period_label($latest['period_month'])) ?></span>
                                            <span class="badge badge-<?= $latest['payment_status'] === 'paid' ? 'ready' : 'in_progress' ?>"><?= $latest['payment_status'] === 'paid' ? 'Paid' : 'Unpaid' ?></span>
                                        </div>
                                        <div class="info-row"><span class="label">Net</span><span class="value" style="color:var(--primary-dark); font-size:15px;">&#8377;<?= number_format((float) $latest['net_salary'], 2) ?></span></div>
                                        <?php if ($latest['payment_status'] === 'paid'): ?>
                                            <p class="result-meta">Paid <?= htmlspecialchars(date('d M Y', strtotime($latest['paid_at']))) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="result-meta" style="margin-top:12px;">No payroll generated yet.</p>
                                <?php endif; ?>

                                <div style="margin-top:14px;">
                                    <?php if ($duePeriod): ?>
                                        <div class="form-notice"><?= icon('alert-triangle', 14) ?> Payroll due — <?= htmlspecialchars(payroll_period_label($duePeriod['start'])) ?></div>
                                        <form method="POST" action="">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="generate_one">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="period_start" value="<?= htmlspecialchars($duePeriod['start']) ?>">
                                            <button type="submit" class="button" style="width:100%;"><?= icon('check', 16) ?> Generate Salary</button>
                                        </form>
                                    <?php elseif ($latest && $latest['payment_status'] === 'unpaid'): ?>
                                        <form method="POST" action=""
                                              data-confirm="Mark &#8377;<?= number_format((float) $latest['net_salary'], 2) ?> as paid to <?= htmlspecialchars($salariedUser['name'], ENT_QUOTES) ?> for <?= htmlspecialchars(payroll_period_label($latest['period_month']), ENT_QUOTES) ?>? This locks the record — it can no longer be recalculated."
                                              data-confirm-title="Mark as paid?"
                                              data-confirm-label="Mark as Paid"
                                              data-confirm-variant="neutral">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="mark_paid">
                                            <input type="hidden" name="payroll_run_id" value="<?= (int) $latest['id'] ?>">
                                            <button type="submit" class="button" style="width:100%;"><?= icon('check', 16) ?> Mark as Paid</button>
                                        </form>
                                        <form method="POST" action="" style="margin-top:6px;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="generate_one">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="period_start" value="<?= htmlspecialchars($latest['period_month']) ?>">
                                            <button type="submit" class="link-action" style="background:none; border:none; cursor:pointer; padding:0; font-size:12.5px;"><?= icon('edit', 13) ?> Recalculate before paying</button>
                                        </form>
                                    <?php else: ?>
                                        <p class="result-meta">Next payroll available from <?= htmlspecialchars((new DateTime($nextAvailableByUser[$uid]))->format('d M Y')) ?>.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

<?php foreach ($salariedUsers as $salariedUser): ?>
    <?php
        $uid = (int) $salariedUser['id'];
        $modalId = 'payroll-modal-' . $uid;
        $history = $historyByUser[$uid] ?? [];
        $latest = $history[0] ?? null;
    ?>
    <div class="modal-backdrop" id="<?= $modalId ?>">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('wallet', 16) ?></span>
                    <?= htmlspecialchars($salariedUser['name']) ?>
                </div>
                <button type="button" class="modal-close" data-close-modal="<?= $modalId ?>" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($latest): ?>
                    <div class="card" style="box-shadow:none;">
                        <div class="card-header">
                            <div class="card-header-title"><?= htmlspecialchars(payroll_period_label($latest['period_month'])) ?></div>
                            <span class="badge badge-<?= $latest['payment_status'] === 'paid' ? 'ready' : 'in_progress' ?>"><?= $latest['payment_status'] === 'paid' ? 'Paid' : 'Unpaid' ?></span>
                        </div>
                        <div class="card-body">
                            <div class="info-row"><span class="label">Type</span><span class="value"><?= $salariedUser['salary_type'] === 'monthly' ? 'Monthly' : 'Daily wage' ?></span></div>
                            <?php if ($salariedUser['designation']): ?>
                                <div class="info-row"><span class="label">Designation</span><span class="value"><?= htmlspecialchars($salariedUser['designation']) ?></span></div>
                            <?php endif; ?>
                            <div class="info-row"><span class="label">Present</span><span class="value"><?= rtrim(rtrim(number_format((float) $latest['days_present'], 1), '0'), '.') ?></span></div>
                            <div class="info-row"><span class="label">Absent</span><span class="value"><?= rtrim(rtrim(number_format((float) $latest['days_absent'], 1), '0'), '.') ?></span></div>
                            <div class="info-row"><span class="label">Half-day</span><span class="value"><?= rtrim(rtrim(number_format((float) $latest['days_half_day'], 1), '0'), '.') ?></span></div>
                            <div class="info-row"><span class="label">Gross</span><span class="value">&#8377;<?= number_format((float) $latest['gross_salary'], 2) ?></span></div>
                            <div class="info-row"><span class="label">Deduction</span><span class="value">&#8377;<?= number_format((float) $latest['deduction_amount'], 2) ?></span></div>
                            <?php if ((float) $latest['advance_deducted'] > 0): ?>
                                <div class="info-row"><span class="label">Advance recovered</span><span class="value">&#8377;<?= number_format((float) $latest['advance_deducted'], 2) ?></span></div>
                            <?php endif; ?>
                            <div class="info-row"><span class="label">Net</span><span class="value" style="color:var(--primary-dark); font-size:16px;">&#8377;<?= number_format((float) $latest['net_salary'], 2) ?></span></div>
                            <p class="result-meta" style="margin-top:8px;">Generated <?= htmlspecialchars(date('d M Y, h:i A', strtotime($latest['generated_at']))) ?></p>
                            <?php if ($latest['payment_status'] === 'paid'): ?>
                                <p class="result-meta">Paid <?= htmlspecialchars(date('d M Y, h:i A', strtotime($latest['paid_at']))) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <?= icon('wallet', 24) ?>
                        No payroll generated yet.
                    </div>
                <?php endif; ?>

                <div class="card" style="box-shadow:none; margin-top:16px;">
                    <div class="card-header">
                        <div class="card-header-title">Payroll history</div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (count($history) <= 1): ?>
                            <div class="empty-state">
                                <?= icon('wallet', 24) ?>
                                No earlier payroll on record.
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr><th>Period</th><th>Net</th><th>Status</th></tr>
                                    <?php foreach (array_slice($history, 1) as $pastRun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(payroll_period_label($pastRun['period_month'])) ?></td>
                                            <td>&#8377;<?= number_format((float) $pastRun['net_salary'], 2) ?></td>
                                            <td><span class="badge badge-<?= $pastRun['payment_status'] === 'paid' ? 'ready' : 'in_progress' ?>"><?= $pastRun['payment_status'] === 'paid' ? 'Paid' : 'Unpaid' ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="actions" style="justify-content:flex-end; margin-top:16px;">
                    <a href="/employee.php?id=<?= $uid ?>" class="button secondary"><?= icon('person', 16) ?> Full employee profile</a>
                </div>

            </div>
        </div>
    </div>
<?php endforeach; ?>

</body>
</html>
