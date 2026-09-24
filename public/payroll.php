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
// advance against the new run, audit-log it. Shared by both the
// single-row "Generate" button and "Generate all due".
function generate_payroll_run(PDO $pdo, array $user, int $organizationId, array $salariedUser, string $periodStart, string $periodEnd): void
{
    $daysInPeriod = (new DateTime($periodStart))->diff(new DateTime($periodEnd))->days;

    $statement = $pdo->prepare("
        SELECT status FROM attendance
        WHERE user_id = :user_id AND work_date >= :period_start AND work_date < :period_end
    ");
    $statement->execute([
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
        $pdo, $user, 'create', 'payroll_run', $payrollRunId,
        "Generated payroll for {$salariedUser['name']}, " . (new DateTime($periodStart))->format('d M') . '–' . (new DateTime($periodEnd))->modify('-1 day')->format('d M Y')
    );
}

$generatedCount = 0;

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
    $salariedUsers = $usersStatement->fetchAll(PDO::FETCH_ASSOC);
    $salariedUsersById = array_column($salariedUsers, null, 'id');

    $periodsStatement = $pdo->prepare("SELECT period_month FROM payroll_runs WHERE organization_id = :organization_id AND user_id = :user_id");

    $pdo->beginTransaction();

    try {
        if ($action === 'generate_one') {

            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            $periodStart = trim($_POST['period_start'] ?? '');

            if (isset($salariedUsersById[$targetUserId]) && DateTime::createFromFormat('Y-m-d', $periodStart)) {
                generate_payroll_run($pdo, $user, $organizationId, $salariedUsersById[$targetUserId], $periodStart, payroll_period_end($periodStart));
                $generatedCount = 1;
            }

        } elseif ($action === 'generate_all_due') {

            foreach ($salariedUsers as $salariedUser) {

                $payDay = (int) ($salariedUser['salary_pay_day'] ?? 1);
                $joinedAt = $salariedUser['joined_at'] ?? $salariedUser['created_at'];

                $periodsStatement->execute(['organization_id' => $organizationId, 'user_id' => $salariedUser['id']]);
                $generatedPeriods = $periodsStatement->fetchAll(PDO::FETCH_COLUMN);

                foreach (pending_payroll_periods($payDay, $joinedAt, $generatedPeriods) as $period) {
                    generate_payroll_run($pdo, $user, $organizationId, $salariedUser, $period['start'], $period['end']);
                    $generatedCount++;
                }
            }
        }

        $pdo->commit();

        if ($generatedCount > 0) {
            flash_set($generatedCount === 1 ? 'Payroll generated.' : "Payroll generated for {$generatedCount} periods.");
        }

        header('Location: /payroll.php');
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Payroll generation failed: ' . $e->getMessage());
    }
}

// Every active salaried employee, their pending (due) periods, and
// their most recently generated payroll run — everything the page
// needs in three queries instead of one per employee.
$statement = $pdo->prepare("
    SELECT id, name, designation, salary_type, salary_amount, salary_pay_day, joined_at, created_at
    FROM users
    WHERE organization_id = :organization_id AND status = 'active' AND salary_type IS NOT NULL
    ORDER BY name
");
$statement->execute(['organization_id' => $organizationId]);
$salariedUsers = $statement->fetchAll(PDO::FETCH_ASSOC);

$dueList = [];
$latestRunByUser = [];
$historyByUser = [];

if (!empty($salariedUsers)) {

    $userIds = array_column($salariedUsers, 'id');
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    $statement = $pdo->prepare("
        SELECT user_id, period_month, gross_salary, deduction_amount, advance_deducted, net_salary, generated_at,
            days_present, days_absent, days_half_day
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

        foreach (pending_payroll_periods($payDay, $joinedAt, $generatedPeriodsByUser[$uid] ?? []) as $period) {
            $dueList[] = ['user' => $salariedUser, 'period' => $period];
        }
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
                    <p class="page-description">Each employee is paid on their own cycle (Settings on their profile) — generate whatever's due below, any time, not just once a month.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('alert-triangle', 15) ?></span>
                        Payroll due
                    </div>
                    <?php if (count($dueList) > 1): ?>
                        <form method="POST" action="">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="generate_all_due">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Generate all due (<?= count($dueList) ?>)</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (empty($dueList)): ?>
                    <div class="card-body">
                        <p class="result-meta">Nothing due right now — every closed pay cycle has been generated.</p>
                    </div>
                <?php else: ?>
                    <div class="card-body" style="padding:0;">
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Employee</th><th>Period</th><th></th></tr>
                                <?php foreach ($dueList as $due): ?>
                                    <?php
                                        $periodLabel = (new DateTime($due['period']['start']))->format('d M Y')
                                            . ' – ' . (new DateTime($due['period']['end']))->modify('-1 day')->format('d M Y');
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($due['user']['name']) ?></td>
                                        <td><?= htmlspecialchars($periodLabel) ?></td>
                                        <td>
                                            <form method="POST" action="">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="generate_one">
                                                <input type="hidden" name="user_id" value="<?= (int) $due['user']['id'] ?>">
                                                <input type="hidden" name="period_start" value="<?= htmlspecialchars($due['period']['start']) ?>">
                                                <button type="submit" class="button secondary sm"><?= icon('check', 13) ?> Generate</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($salariedUsers)): ?>
                <div class="settings-grid" style="margin-top:20px;">
                    <?php foreach ($salariedUsers as $salariedUser): ?>
                        <?php
                            $uid = (int) $salariedUser['id'];
                            $latest = $latestRunByUser[$uid] ?? null;
                            $modalId = 'payroll-modal-' . $uid;
                        ?>
                        <button type="button" class="card" onclick="openModal('<?= $modalId ?>')">
                            <div class="card-body">
                                <div class="employee-card-top">
                                    <div class="employee-card-name"><?= htmlspecialchars($salariedUser['name']) ?></div>
                                    <span class="icon-badge"><?= icon('wallet', 15) ?></span>
                                </div>
                                <div class="result-meta"><?= $salariedUser['salary_type'] === 'monthly' ? 'Monthly' : 'Daily wage' ?></div>

                                <?php if ($latest): ?>
                                    <div style="margin-top:12px;">
                                        <div class="info-row"><span class="label">Present</span><span class="value"><?= rtrim(rtrim(number_format((float) $latest['days_present'], 1), '0'), '.') ?></span></div>
                                        <div class="info-row"><span class="label">Absent</span><span class="value"><?= rtrim(rtrim(number_format((float) $latest['days_absent'], 1), '0'), '.') ?></span></div>
                                        <div class="info-row"><span class="label">Half-day</span><span class="value"><?= rtrim(rtrim(number_format((float) $latest['days_half_day'], 1), '0'), '.') ?></span></div>
                                        <div class="info-row"><span class="label">Gross</span><span class="value">&#8377;<?= number_format((float) $latest['gross_salary'], 2) ?></span></div>
                                        <div class="info-row"><span class="label">Deduction</span><span class="value">&#8377;<?= number_format((float) $latest['deduction_amount'], 2) ?></span></div>
                                        <div class="info-row"><span class="label">Net</span><span class="value" style="color:var(--primary-dark); font-size:15px;">&#8377;<?= number_format((float) $latest['net_salary'], 2) ?></span></div>
                                    </div>
                                <?php else: ?>
                                    <p class="result-meta" style="margin-top:12px;">No payroll generated yet.</p>
                                <?php endif; ?>
                            </div>
                        </button>
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
                                    <tr><th>Period</th><th>Gross</th><th>Deduction</th><th>Net</th></tr>
                                    <?php foreach (array_slice($history, 1) as $pastRun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(payroll_period_label($pastRun['period_month'])) ?></td>
                                            <td>&#8377;<?= number_format((float) $pastRun['gross_salary'], 2) ?></td>
                                            <td>&#8377;<?= number_format((float) $pastRun['deduction_amount'], 2) ?></td>
                                            <td>&#8377;<?= number_format((float) $pastRun['net_salary'], 2) ?></td>
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
