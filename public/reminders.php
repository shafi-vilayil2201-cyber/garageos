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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $reminderId = (int) ($_POST['reminder_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_sent') {

        $channel = $_POST['channel'] ?? 'call';

        $statement = $pdo->prepare("
            UPDATE reminders
            SET status = 'sent', channel = :channel, sent_at = CURRENT_TIMESTAMP
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute(['channel' => $channel, 'id' => $reminderId, 'organization_id' => $organizationId]);

    } elseif ($action === 'dismiss') {

        $statement = $pdo->prepare("
            UPDATE reminders SET status = 'dismissed'
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute(['id' => $reminderId, 'organization_id' => $organizationId]);
    }

    header('Location: /reminders.php');
    exit;
}

// Generate upcoming insurance/PUC expiry reminders from the vehicle
// records themselves. Idempotent — the unique constraint on
// (vehicle_id, due_type, due_date) means running this twice is harmless.
// Until cloud sync introduces a background worker, this runs inline
// whenever the page loads.
$expiryTypes = [
    'insurance_expiry' => 'insurance_expiry',
    'puc_expiry' => 'puc_expiry'
];

foreach ($expiryTypes as $column => $dueType) {
    $statement = $pdo->prepare("
        INSERT INTO reminders (organization_id, customer_id, vehicle_id, due_type, due_date)
        SELECT v.organization_id, v.customer_id, v.id, :due_type, v.{$column}
        FROM vehicles v
        WHERE v.organization_id = :organization_id
          AND v.{$column} IS NOT NULL
          AND v.{$column} <= CURRENT_DATE + INTERVAL '30 days'
        ON CONFLICT (vehicle_id, due_type, due_date) DO NOTHING
    ");
    $statement->execute(['due_type' => $dueType, 'organization_id' => $organizationId]);
}

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
    ORDER BY r.due_date
");
$statement->execute(['organization_id' => $organizationId]);
$reminders = $statement->fetchAll(PDO::FETCH_ASSOC);

$dueTypeLabels = [
    'service_due' => 'Service due',
    'insurance_expiry' => 'Insurance expiring',
    'puc_expiry' => 'PUC expiring',
    'follow_up' => 'Follow-up'
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
                    <p class="page-description">Vehicles due for service, or with documents expiring soon.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Due in the next 30 days</div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($reminders)): ?>
                        <div class="empty-state">Nothing due. New reminders appear automatically as vehicles approach their next service or document expiry.</div>
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
                                            <strong class="<?= $overdue ? 'stat-meta warning' : '' ?>">
                                                <?= htmlspecialchars(date('d M Y', strtotime($reminder['due_date']))) ?>
                                            </strong>
                                            <div class="result-meta">
                                                <?= $overdue ? abs($reminder['days_until']) . ' days overdue' : $reminder['days_until'] . ' days away' ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($dueTypeLabels[$reminder['due_type']] ?? $reminder['due_type']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($reminder['registration_no']) ?>
                                            <div class="result-meta"><?= htmlspecialchars($reminder['make'] . ' ' . $reminder['model']) ?></div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($reminder['customer_name']) ?>
                                            <div class="result-meta"><?= htmlspecialchars($reminder['customer_phone']) ?></div>
                                        </td>
                                        <td style="white-space:nowrap;">
                                            <form method="POST" action="" style="display:inline-flex; gap:6px; align-items:center;">
                                                <input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>">
                                                <input type="hidden" name="action" value="mark_sent">
                                                <select name="channel" style="height:32px; font-size:12.5px; border:1px solid var(--border); border-radius:6px;">
                                                    <option value="call">Call</option>
                                                    <option value="sms">SMS</option>
                                                    <option value="whatsapp">WhatsApp</option>
                                                    <option value="email">Email</option>
                                                </select>
                                                <button type="submit" class="button secondary" style="height:32px; padding:0 12px; font-size:12.5px;">Mark contacted</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline;">
                                                <input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>">
                                                <input type="hidden" name="action" value="dismiss">
                                                <button type="submit" class="button secondary" style="height:32px; padding:0 12px; font-size:12.5px;">Dismiss</button>
                                            </form>
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
