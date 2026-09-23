<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/ServiceDue.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'job_cards.view');

$organizationId = $user['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'job_cards.manage');

    $reminderId = (int) ($_POST['reminder_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $statement = $pdo->prepare("
        SELECT c.name AS customer_name, v.registration_no
        FROM reminders r
        INNER JOIN customers c ON c.id = r.customer_id
        INNER JOIN vehicles v ON v.id = r.vehicle_id
        WHERE r.id = :id AND r.organization_id = :organization_id
    ");
    $statement->execute(['id' => $reminderId, 'organization_id' => $organizationId]);
    $targetReminder = $statement->fetch(PDO::FETCH_ASSOC);

    if ($action === 'mark_sent') {

        $channel = $_POST['channel'] ?? 'call';

        $statement = $pdo->prepare("
            UPDATE reminders
            SET status = 'sent', channel = :channel, sent_at = CURRENT_TIMESTAMP
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute(['channel' => $channel, 'id' => $reminderId, 'organization_id' => $organizationId]);

        if ($targetReminder) {
            log_audit_event(
                $pdo, $user, 'update', 'reminder', $reminderId,
                "Marked reminder contacted via $channel for {$targetReminder['customer_name']} — {$targetReminder['registration_no']}"
            );
        }

    } elseif ($action === 'dismiss') {

        $statement = $pdo->prepare("
            UPDATE reminders SET status = 'dismissed'
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute(['id' => $reminderId, 'organization_id' => $organizationId]);

        if ($targetReminder) {
            log_audit_event(
                $pdo, $user, 'update', 'reminder', $reminderId,
                "Dismissed reminder for {$targetReminder['customer_name']} — {$targetReminder['registration_no']}"
            );
        }
    }

    header('Location: /reminders.php');
    exit;
}

// Generate upcoming "service due" reminders by projecting each vehicle's
// next service from its own odometer history — see
// app/Domain/ServiceDue.php for the "whichever comes first" (km or
// months) rule. Each installation is a single self-contained instance
// with no background worker, so this runs inline whenever the page loads.
$statement = $pdo->prepare("SELECT service_interval_km, service_interval_months FROM organizations WHERE id = :id");
$statement->execute(['id' => $organizationId]);
$serviceInterval = $statement->fetch(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("
    SELECT jc.vehicle_id, v.customer_id, jc.created_at::date AS date, jc.odometer_in AS odometer
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    WHERE jc.organization_id = :organization_id
      AND jc.deleted_at IS NULL
      AND jc.odometer_in IS NOT NULL
    ORDER BY jc.vehicle_id, jc.created_at
");
$statement->execute(['organization_id' => $organizationId]);

$historyByVehicle = [];

foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $historyByVehicle[$row['vehicle_id']]['customer_id'] = $row['customer_id'];
    $historyByVehicle[$row['vehicle_id']]['rows'][] = ['date' => $row['date'], 'odometer' => (int) $row['odometer']];
}

$deleteStale = $pdo->prepare("
    DELETE FROM reminders
    WHERE vehicle_id = :vehicle_id AND due_type = 'service_due' AND status = 'pending' AND due_date != :due_date
");
$insertFresh = $pdo->prepare("
    INSERT INTO reminders (organization_id, customer_id, vehicle_id, due_type, due_date)
    VALUES (:organization_id, :customer_id, :vehicle_id, 'service_due', :due_date)
    ON CONFLICT (vehicle_id, due_type, due_date) DO NOTHING
");

foreach ($historyByVehicle as $vehicleId => $vehicle) {
    $dueDate = calculate_next_service_due($vehicle['rows'], (int) $serviceInterval['service_interval_km'], (int) $serviceInterval['service_interval_months']);

    if ($dueDate === null || $dueDate > date('Y-m-d', strtotime('+30 days'))) {
        continue;
    }

    $deleteStale->execute(['vehicle_id' => $vehicleId, 'due_date' => $dueDate]);
    $insertFresh->execute([
        'organization_id' => $organizationId,
        'customer_id' => $vehicle['customer_id'],
        'vehicle_id' => $vehicleId,
        'due_date' => $dueDate
    ]);
}

// Job cards running past the delivery date staff promised the customer
// — an internal, operational nudge rather than a customer-facing
// reminder, so it's computed live from job_cards directly instead of
// being materialized into the reminders table: there's nothing to
// "dismiss" or "mark contacted" about it, it should just disappear the
// moment the job card is actually delivered (or the promise changes).
$statement = $pdo->prepare("
    SELECT jc.id, jc.job_no, jc.promised_at, jc.status,
           v.registration_no, v.make, v.model,
           c.name AS customer_name
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    INNER JOIN customers c ON c.id = jc.customer_id
    WHERE jc.organization_id = :organization_id
      AND jc.deleted_at IS NULL
      AND jc.promised_at IS NOT NULL
      AND jc.promised_at < CURRENT_TIMESTAMP
      AND jc.status NOT IN ('delivered', 'cancelled')
    ORDER BY jc.promised_at
");
$statement->execute(['organization_id' => $organizationId]);
$overdueJobCards = $statement->fetchAll(PDO::FETCH_ASSOC);

// A heads-up before a promise is actually missed — anything still
// promised for later today, so staff can catch it before it slides
// into "Running late" above.
$statement = $pdo->prepare("
    SELECT jc.id, jc.job_no, jc.promised_at, jc.status,
           v.registration_no, v.make, v.model,
           c.name AS customer_name
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    INNER JOIN customers c ON c.id = jc.customer_id
    WHERE jc.organization_id = :organization_id
      AND jc.deleted_at IS NULL
      AND jc.promised_at IS NOT NULL
      AND jc.promised_at >= CURRENT_TIMESTAMP
      AND jc.promised_at < (CURRENT_DATE + INTERVAL '1 day')
      AND jc.status NOT IN ('delivered', 'cancelled')
    ORDER BY jc.promised_at
");
$statement->execute(['organization_id' => $organizationId]);
$dueTodayJobCards = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("
    SELECT
        r.id, r.due_type, r.due_date, r.status,
        v.registration_no, v.make, v.model,
        c.name AS customer_name, c.phone AS customer_phone,
        (r.due_date - CURRENT_DATE) AS days_until
    FROM reminders r
    INNER JOIN vehicles v ON v.id = r.vehicle_id
    INNER JOIN customers c ON c.id = r.customer_id
    WHERE r.organization_id = :organization_id
      AND r.status = 'pending'
      AND r.due_date <= CURRENT_DATE + INTERVAL '30 days'
    ORDER BY r.due_date
");
$statement->execute(['organization_id' => $organizationId]);
$reminders = $statement->fetchAll(PDO::FETCH_ASSOC);

$dueTypeLabels = [
    'service_due' => 'Service due',
    'follow_up' => 'Follow-up'
];

$dueTypeIcons = [
    'service_due' => 'settings',
    'follow_up' => 'message'
];

$activeNav = 'reminders';
$topbarTitle = 'Reminders';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Reminders</title>

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
                    <h1 class="page-title">Reminders</h1>
                    <p class="page-description">Vehicles due for their next service, and job cards running past the promised delivery.</p>
                </div>
            </div>

            <?php if (!empty($overdueJobCards)): ?>
                <div class="card" style="margin-bottom:16px; border-color:var(--danger);">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('alert-triangle', 15) ?></span>
                            Running late — promised but not delivered
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Job card</th><th>Vehicle</th><th>Customer</th><th>Promised</th><th>Status</th><th></th></tr>
                                <?php foreach ($overdueJobCards as $jobCard): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($jobCard['job_no']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($jobCard['registration_no']) ?>
                                            <div class="result-meta"><?= htmlspecialchars($jobCard['make'] . ' ' . $jobCard['model']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($jobCard['customer_name']) ?></td>
                                        <td class="stat-meta warning"><?= htmlspecialchars(date('d M, h:i A', strtotime($jobCard['promised_at']))) ?></td>
                                        <td style="text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $jobCard['status'])) ?></td>
                                        <td><a href="/job-card.php?id=<?= (int) $jobCard['id'] ?>" class="button secondary">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($dueTodayJobCards)): ?>
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('bell', 15) ?></span>
                            Due today — not yet delivered
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Job card</th><th>Vehicle</th><th>Customer</th><th>Promised</th><th>Status</th><th></th></tr>
                                <?php foreach ($dueTodayJobCards as $jobCard): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($jobCard['job_no']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($jobCard['registration_no']) ?>
                                            <div class="result-meta"><?= htmlspecialchars($jobCard['make'] . ' ' . $jobCard['model']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($jobCard['customer_name']) ?></td>
                                        <td><?= htmlspecialchars(date('h:i A', strtotime($jobCard['promised_at']))) ?></td>
                                        <td style="text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $jobCard['status'])) ?></td>
                                        <td><a href="/job-card.php?id=<?= (int) $jobCard['id'] ?>" class="button secondary">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('bell', 15) ?></span>
                        Service due in the next 30 days
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($reminders)): ?>
                        <div class="empty-state">
                            <?= icon('check-circle', 28) ?>
                            Nothing due. Reminders appear automatically as a vehicle approaches its next service, projected from its own odometer history.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr>
                                    <th>Due</th>
                                    <th>Type</th>
                                    <th>Vehicle</th>
                                    <th>Customer</th>
                                    <th></th>
                                </tr>
                                <?php foreach ($reminders as $reminder): ?>
                                    <?php $overdue = $reminder['days_until'] < 0; ?>
                                    <tr>
                                        <td>
                                            <strong class="<?= $overdue ? 'stat-meta warning' : '' ?>" style="display:inline-flex; align-items:center; gap:6px;">
                                                <?php if ($overdue): ?><?= icon('alert-triangle', 14) ?><?php endif; ?>
                                                <?= htmlspecialchars(date('d M Y', strtotime($reminder['due_date']))) ?>
                                            </strong>
                                            <div class="result-meta">
                                                <?= $overdue ? abs($reminder['days_until']) . ' days overdue' : $reminder['days_until'] . ' days away' ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="kanban-column-label"><?= icon($dueTypeIcons[$reminder['due_type']] ?? 'bell', 14) ?><?= htmlspecialchars($dueTypeLabels[$reminder['due_type']] ?? $reminder['due_type']) ?></span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($reminder['registration_no']) ?>
                                            <div class="result-meta"><?= htmlspecialchars($reminder['make'] . ' ' . $reminder['model']) ?></div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($reminder['customer_name']) ?>
                                            <div class="result-meta"><?= htmlspecialchars($reminder['customer_phone']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (user_can($user, 'job_cards.manage')): ?>
                                                <div class="row-actions">
                                                    <form method="POST" action="" class="actions" style="gap:6px;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>">
                                                        <input type="hidden" name="action" value="mark_sent">
                                                        <select name="channel" style="height:32px; font-size:12.5px; border:1px solid var(--border); border-radius:6px;">
                                                            <option value="call">Call</option>
                                                            <option value="sms">SMS</option>
                                                            <option value="whatsapp">WhatsApp</option>
                                                            <option value="email">Email</option>
                                                        </select>
                                                        <button type="submit" class="button secondary sm"><?= icon('phone', 14) ?> Mark contacted</button>
                                                    </form>
                                                    <form method="POST" action="" class="inline-form">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>">
                                                        <input type="hidden" name="action" value="dismiss">
                                                        <button type="submit" class="button danger sm"><?= icon('x', 14) ?> Dismiss</button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

</body>
</html>
