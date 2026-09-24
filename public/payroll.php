<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Payroll.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'payroll.manage');

$organizationId = $user['organization_id'];

$periodMonth = $_GET['month'] ?? date('Y-m');

if (!DateTime::createFromFormat('Y-m', $periodMonth)) {
    $periodMonth = date('Y-m');
}

$periodStart = $periodMonth . '-01';
$daysInMonth = (int) (new DateTime($periodStart))->format('t');

$generated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'payroll.manage');

    $postedMonth = $_POST['period_month'] ?? '';

    if (DateTime::createFromFormat('Y-m', $postedMonth)) {

        $periodMonth = $postedMonth;
        $periodStart = $periodMonth . '-01';
        $daysInMonth = (int) (new DateTime($periodStart))->format('t');

        $usersStatement = $pdo->prepare("
            SELECT id, salary_type, salary_amount
            FROM users
            WHERE organization_id = :organization_id AND status = 'active' AND salary_type IS NOT NULL
        ");
        $usersStatement->execute(['organization_id' => $organizationId]);
        $salariedUsers = $usersStatement->fetchAll(PDO::FETCH_ASSOC);

        $attendanceStatement = $pdo->prepare("
            SELECT status FROM attendance
            WHERE user_id = :user_id
              AND work_date >= :period_start
              AND work_date < (:period_start_end::date + INTERVAL '1 month')
        ");

        $upsert = $pdo->prepare("
            INSERT INTO payroll_runs (
                organization_id, user_id, period_month, days_in_period,
                days_present, days_absent, days_half_day,
                gross_salary, deduction_amount, net_salary, generated_by
            ) VALUES (
                :organization_id, :user_id, :period_month, :days_in_period,
                :days_present, :days_absent, :days_half_day,
                :gross_salary, :deduction_amount, :net_salary, :generated_by
            )
            ON CONFLICT (user_id, period_month) DO UPDATE SET
                days_in_period = EXCLUDED.days_in_period,
                days_present = EXCLUDED.days_present,
                days_absent = EXCLUDED.days_absent,
                days_half_day = EXCLUDED.days_half_day,
                gross_salary = EXCLUDED.gross_salary,
                deduction_amount = EXCLUDED.deduction_amount,
                net_salary = EXCLUDED.net_salary,
                generated_by = EXCLUDED.generated_by,
                generated_at = CURRENT_TIMESTAMP
        ");

        $pdo->beginTransaction();

        try {
            foreach ($salariedUsers as $salariedUser) {

                $attendanceStatement->execute([
                    'user_id' => $salariedUser['id'],
                    'period_start' => $periodStart,
                    'period_start_end' => $periodStart
                ]);
                $attendanceRows = $attendanceStatement->fetchAll(PDO::FETCH_ASSOC);

                $result = calculate_payroll($salariedUser, $attendanceRows, $daysInMonth);

                $upsert->execute([
                    'organization_id' => $organizationId,
                    'user_id' => $salariedUser['id'],
                    'period_month' => $periodStart,
                    'days_in_period' => $daysInMonth,
                    'days_present' => $result['days_present'],
                    'days_absent' => $result['days_absent'],
                    'days_half_day' => $result['days_half_day'],
                    'gross_salary' => $result['gross_salary'],
                    'deduction_amount' => $result['deduction_amount'],
                    'net_salary' => $result['net_salary'],
                    'generated_by' => $user['id']
                ]);
            }

            log_audit_event(
                $pdo, $user, 'create', 'payroll_run', null,
                'Generated payroll for ' . (new DateTime($periodStart))->format('F Y') . ' (' . count($salariedUsers) . ' staff)'
            );

            $pdo->commit();
            $generated = true;

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Payroll generation failed: ' . $e->getMessage());
        }
    }
}

$statement = $pdo->prepare("
    SELECT pr.user_id, u.name, u.designation, u.salary_type, pr.days_present, pr.days_absent, pr.days_half_day,
        pr.gross_salary, pr.deduction_amount, pr.net_salary, pr.generated_at
    FROM payroll_runs pr
    JOIN users u ON u.id = pr.user_id
    WHERE pr.organization_id = :organization_id AND pr.period_month = :period_start
    ORDER BY u.name
");
$statement->execute(['organization_id' => $organizationId, 'period_start' => $periodStart]);
$payrollRows = $statement->fetchAll(PDO::FETCH_ASSOC);

// Each card's modal shows this employee's history alongside the
// selected month — cheap to pull for every employee at once here
// (a small workshop's staff count times a handful of periods each)
// rather than a query-per-card-per-click.
$payrollHistoryByUser = [];

if (!empty($payrollRows)) {
    $userIds = array_unique(array_column($payrollRows, 'user_id'));
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    $statement = $pdo->prepare("
        SELECT user_id, period_month, gross_salary, deduction_amount, net_salary, generated_at
        FROM payroll_runs
        WHERE organization_id = ? AND user_id IN ($placeholders)
        ORDER BY period_month DESC
    ");
    $statement->execute([$organizationId, ...$userIds]);

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payrollHistoryByUser[(int) $row['user_id']][] = $row;
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
                    <p class="page-description">Generate monthly salary from attendance — safe to re-run after fixing an attendance mistake.</p>
                </div>
            </div>

            <?php if ($generated): ?>
                <div class="form-success" style="margin-bottom:16px;"><?= icon('check-circle', 16) ?> Payroll generated for <?= htmlspecialchars($periodMonth) ?>.</div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('wallet', 15) ?></span>
                        <?= htmlspecialchars((new DateTime($periodStart))->format('F Y')) ?>
                    </div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <form method="GET" action="">
                            <div class="header-date-wrap">
                                <button type="button" class="button secondary header-date-trigger" aria-label="Choose month" onclick="openDatePicker(this)"><?= icon('calendar', 16) ?> <?= htmlspecialchars((new DateTime($periodStart))->format('F Y')) ?></button>
                                <input type="month" name="month" value="<?= htmlspecialchars($periodMonth) ?>" onchange="this.form.submit()">
                            </div>
                        </form>
                        <form method="POST" action="">
                            <?= csrf_field() ?>
                            <input type="hidden" name="period_month" value="<?= htmlspecialchars($periodMonth) ?>">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Generate</button>
                        </form>
                    </div>
                </div>

                <?php if (empty($payrollRows)): ?>
                    <div class="card-body">
                        <div class="empty-state">
                            <?= icon('wallet', 28) ?>
                            No payroll generated yet for this month.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($payrollRows)): ?>
                <div class="settings-grid" style="margin-top:20px;">
                    <?php foreach ($payrollRows as $row): ?>
                        <?php $modalId = 'payroll-modal-' . (int) $row['user_id']; ?>
                        <button type="button" class="card" onclick="openModal('<?= $modalId ?>')">
                            <div class="card-body">
                                <div class="employee-card-top">
                                    <div class="employee-card-name"><?= htmlspecialchars($row['name']) ?></div>
                                    <span class="icon-badge"><?= icon('wallet', 15) ?></span>
                                </div>
                                <div class="result-meta"><?= $row['salary_type'] === 'monthly' ? 'Monthly' : 'Daily wage' ?></div>

                                <div style="margin-top:12px;">
                                    <div class="info-row"><span class="label">Present</span><span class="value"><?= rtrim(rtrim(number_format((float) $row['days_present'], 1), '0'), '.') ?></span></div>
                                    <div class="info-row"><span class="label">Absent</span><span class="value"><?= rtrim(rtrim(number_format((float) $row['days_absent'], 1), '0'), '.') ?></span></div>
                                    <div class="info-row"><span class="label">Half-day</span><span class="value"><?= rtrim(rtrim(number_format((float) $row['days_half_day'], 1), '0'), '.') ?></span></div>
                                    <div class="info-row"><span class="label">Gross</span><span class="value">&#8377;<?= number_format((float) $row['gross_salary'], 2) ?></span></div>
                                    <div class="info-row"><span class="label">Deduction</span><span class="value">&#8377;<?= number_format((float) $row['deduction_amount'], 2) ?></span></div>
                                    <div class="info-row"><span class="label">Net</span><span class="value" style="color:var(--primary-dark); font-size:15px;">&#8377;<?= number_format((float) $row['net_salary'], 2) ?></span></div>
                                </div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

<?php foreach ($payrollRows as $row): ?>
    <?php
        $modalId = 'payroll-modal-' . (int) $row['user_id'];
        $history = $payrollHistoryByUser[(int) $row['user_id']] ?? [];
    ?>
    <div class="modal-backdrop" id="<?= $modalId ?>">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('wallet', 16) ?></span>
                    <?= htmlspecialchars($row['name']) ?>
                </div>
                <button type="button" class="modal-close" data-close-modal="<?= $modalId ?>" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <div class="card" style="box-shadow:none;">
                    <div class="card-header">
                        <div class="card-header-title"><?= htmlspecialchars((new DateTime($periodStart))->format('F Y')) ?></div>
                    </div>
                    <div class="card-body">
                        <div class="info-row"><span class="label">Type</span><span class="value"><?= $row['salary_type'] === 'monthly' ? 'Monthly' : 'Daily wage' ?></span></div>
                        <?php if ($row['designation']): ?>
                            <div class="info-row"><span class="label">Designation</span><span class="value"><?= htmlspecialchars($row['designation']) ?></span></div>
                        <?php endif; ?>
                        <div class="info-row"><span class="label">Present</span><span class="value"><?= rtrim(rtrim(number_format((float) $row['days_present'], 1), '0'), '.') ?></span></div>
                        <div class="info-row"><span class="label">Absent</span><span class="value"><?= rtrim(rtrim(number_format((float) $row['days_absent'], 1), '0'), '.') ?></span></div>
                        <div class="info-row"><span class="label">Half-day</span><span class="value"><?= rtrim(rtrim(number_format((float) $row['days_half_day'], 1), '0'), '.') ?></span></div>
                        <div class="info-row"><span class="label">Gross</span><span class="value">&#8377;<?= number_format((float) $row['gross_salary'], 2) ?></span></div>
                        <div class="info-row"><span class="label">Deduction</span><span class="value">&#8377;<?= number_format((float) $row['deduction_amount'], 2) ?></span></div>
                        <div class="info-row"><span class="label">Net</span><span class="value" style="color:var(--primary-dark); font-size:16px;">&#8377;<?= number_format((float) $row['net_salary'], 2) ?></span></div>
                        <p class="result-meta" style="margin-top:8px;">Generated <?= htmlspecialchars(date('d M Y, h:i A', strtotime($row['generated_at']))) ?></p>
                    </div>
                </div>

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
                                    <?php foreach ($history as $pastRun): ?>
                                        <?php if ($pastRun['period_month'] === $periodStart) continue; ?>
                                        <tr>
                                            <td><?= htmlspecialchars((new DateTime($pastRun['period_month']))->format('M Y')) ?></td>
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
                    <a href="/employee.php?id=<?= (int) $row['user_id'] ?>" class="button secondary"><?= icon('person', 16) ?> Full employee profile</a>
                </div>

            </div>
        </div>
    </div>
<?php endforeach; ?>

</body>
</html>
