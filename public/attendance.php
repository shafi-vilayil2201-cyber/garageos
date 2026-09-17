<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'attendance.manage');

$organizationId = $user['organization_id'];

$workDate = $_GET['date'] ?? date('Y-m-d');

if (!DateTime::createFromFormat('Y-m-d', $workDate)) {
    $workDate = date('Y-m-d');
}

$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'attendance.manage');

    $postedDate = $_POST['work_date'] ?? '';
    $statuses = $_POST['status'] ?? [];

    if (DateTime::createFromFormat('Y-m-d', $postedDate)) {

        $workDate = $postedDate;

        $statement = $pdo->prepare("SELECT user_id, status FROM attendance WHERE organization_id = :organization_id AND work_date = :work_date");
        $statement->execute(['organization_id' => $organizationId, 'work_date' => $workDate]);
        $existingStatuses = array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'status', 'user_id');

        $upsert = $pdo->prepare("
            INSERT INTO attendance (organization_id, user_id, work_date, status, marked_by)
            VALUES (:organization_id, :user_id, :work_date, :status, :marked_by)
            ON CONFLICT (user_id, work_date)
            DO UPDATE SET status = EXCLUDED.status, marked_by = EXCLUDED.marked_by, updated_at = CURRENT_TIMESTAMP
        ");

        $clear = $pdo->prepare("
            DELETE FROM attendance WHERE user_id = :user_id AND work_date = :work_date
        ");

        $checkUser = $pdo->prepare("SELECT name FROM users WHERE id = :id AND organization_id = :organization_id");

        $pdo->beginTransaction();

        try {
            foreach ($statuses as $userId => $status) {

                $userId = (int) $userId;
                $checkUser->execute(['id' => $userId, 'organization_id' => $organizationId]);
                $staffName = $checkUser->fetchColumn();

                if ($staffName === false) {
                    continue;
                }

                $previousStatus = $existingStatuses[$userId] ?? null;
                $isRealStatus = $status === 'present' || $status === 'absent' || $status === 'half_day';

                if ($isRealStatus && $status === $previousStatus) {
                    continue;
                }

                if (!$isRealStatus && $previousStatus === null) {
                    continue;
                }

                if ($isRealStatus) {
                    $upsert->execute([
                        'organization_id' => $organizationId,
                        'user_id' => $userId,
                        'work_date' => $workDate,
                        'status' => $status,
                        'marked_by' => $user['id']
                    ]);

                    log_audit_event(
                        $pdo, $user, $previousStatus === null ? 'create' : 'update', 'attendance', $userId,
                        "Marked $staffName as " . str_replace('_', ' ', $status) . " for $workDate"
                    );
                } else {
                    $clear->execute(['user_id' => $userId, 'work_date' => $workDate]);

                    log_audit_event(
                        $pdo, $user, 'delete', 'attendance', $userId,
                        "Cleared attendance mark for $staffName on $workDate"
                    );
                }
            }

            $pdo->commit();
            $saved = true;

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Attendance save failed: ' . $e->getMessage());
        }
    }
}

$statement = $pdo->prepare("
    SELECT u.id, u.name, u.designation, a.status
    FROM users u
    LEFT JOIN attendance a ON a.user_id = u.id AND a.work_date = :work_date
    WHERE u.organization_id = :organization_id AND u.status = 'active'
    ORDER BY u.name
");
$statement->execute(['organization_id' => $organizationId, 'work_date' => $workDate]);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'attendance';
$topbarTitle = 'Attendance';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Attendance</title>

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
                    <h1 class="page-title">Attendance</h1>
                    <p class="page-description">Mark who was present, absent, or half-day for a given date.</p>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="form-success" style="margin-bottom:16px;"><?= icon('check-circle', 16) ?> Attendance saved for <?= htmlspecialchars($workDate) ?>.</div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('calendar', 15) ?></span>
                        Mark attendance
                    </div>
                    <form method="GET" action="">
                        <div class="header-date-wrap">
                            <button type="button" class="button secondary header-date-trigger" aria-label="Choose date" onclick="openDatePicker(this)"><?= icon('calendar', 16) ?> <?= htmlspecialchars((new DateTime($workDate))->format('d M Y')) ?></button>
                            <input type="date" name="date" value="<?= htmlspecialchars($workDate) ?>" onchange="this.form.submit()">
                        </div>
                    </form>
                </div>
                <div class="card-body" style="padding:0;">

                    <?php if (empty($rows)): ?>
                        <div class="empty-state">
                            <?= icon('team', 28) ?>
                            No active staff to mark attendance for.
                        </div>
                    <?php else: ?>

                        <form method="POST" action="">
                            <?= csrf_field() ?>
                            <input type="hidden" name="work_date" value="<?= htmlspecialchars($workDate) ?>">

                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr><th>Name</th><th>Designation</th><th>Status</th></tr>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['name']) ?></td>
                                            <td><?= htmlspecialchars($row['designation'] ?? '—') ?></td>
                                            <td>
                                                <select name="status[<?= (int) $row['id'] ?>]">
                                                    <option value="" <?= $row['status'] === null ? 'selected' : '' ?>>Not marked</option>
                                                    <option value="present" <?= $row['status'] === 'present' ? 'selected' : '' ?>>Present</option>
                                                    <option value="half_day" <?= $row['status'] === 'half_day' ? 'selected' : '' ?>>Half day</option>
                                                    <option value="absent" <?= $row['status'] === 'absent' ? 'selected' : '' ?>>Absent</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>

                            <div class="form-actions" style="padding:16px 22px;">
                                <button type="submit" class="button"><?= icon('check', 16) ?> Save attendance</button>
                            </div>

                        </form>

                    <?php endif; ?>

                </div>
            </div>

        </section>

    </main>

</div>

</body>
</html>
