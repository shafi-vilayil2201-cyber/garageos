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

require_permission($user, 'job_cards.view');

$organizationId = $user['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'job_cards.manage');

    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {

        $statement = $pdo->prepare("
            UPDATE appointments SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute(['id' => $appointmentId, 'organization_id' => $organizationId]);

    } elseif ($action === 'convert_to_job_card') {

        $statement = $pdo->prepare("
            SELECT * FROM appointments WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute(['id' => $appointmentId, 'organization_id' => $organizationId]);
        $appointment = $statement->fetch(PDO::FETCH_ASSOC);

        if ($appointment && !$appointment['job_card_id']) {

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare("
                    SELECT COALESCE(MAX(CAST(SUBSTRING(job_no FROM 4) AS INT)), 0) + 1
                    FROM job_cards WHERE branch_id = :branch_id
                ");
                $statement->execute(['branch_id' => $appointment['branch_id']]);
                $nextNumber = (int) $statement->fetchColumn();
                $jobNo = 'JC-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

                $statement = $pdo->prepare("
                    INSERT INTO job_cards (organization_id, branch_id, job_no, customer_id, vehicle_id, advisor_id, status)
                    VALUES (:organization_id, :branch_id, :job_no, :customer_id, :vehicle_id, :advisor_id, 'received')
                    RETURNING id
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'branch_id' => $appointment['branch_id'],
                    'job_no' => $jobNo,
                    'customer_id' => $appointment['customer_id'],
                    'vehicle_id' => $appointment['vehicle_id'],
                    'advisor_id' => $user['id']
                ]);
                $jobCardId = $statement->fetchColumn();

                if ($appointment['service_id']) {
                    $statement = $pdo->prepare("SELECT standard_price FROM services WHERE id = :id");
                    $statement->execute(['id' => $appointment['service_id']]);
                    $price = $statement->fetchColumn();

                    if ($price !== false) {
                        $statement = $pdo->prepare("
                            INSERT INTO job_card_items (job_card_id, service_id, price)
                            VALUES (:job_card_id, :service_id, :price)
                        ");
                        $statement->execute([
                            'job_card_id' => $jobCardId,
                            'service_id' => $appointment['service_id'],
                            'price' => $price
                        ]);
                    }
                }

                $statement = $pdo->prepare("
                    UPDATE appointments
                    SET status = 'completed', job_card_id = :job_card_id, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $statement->execute(['job_card_id' => $jobCardId, 'id' => $appointmentId]);

                $pdo->commit();

                header('Location: /job-card.php?id=' . $jobCardId);
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
            }
        }
    }

    header('Location: /appointments.php');
    exit;
}

$statement = $pdo->prepare("
    SELECT
        a.id, a.scheduled_at, a.status, a.source, a.job_card_id,
        v.registration_no, v.make, v.model,
        c.name AS customer_name, c.phone AS customer_phone,
        s.name AS service_name
    FROM appointments a
    INNER JOIN vehicles v ON v.id = a.vehicle_id
    INNER JOIN customers c ON c.id = a.customer_id
    LEFT JOIN services s ON s.id = a.service_id
    WHERE a.organization_id = :organization_id
      AND a.status IN ('scheduled', 'confirmed')
    ORDER BY a.scheduled_at
");
$statement->execute(['organization_id' => $organizationId]);
$appointments = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'appointments';
$topbarTitle = 'Appointments';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Appointments</title>

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
                    <h1 class="page-title">Appointments</h1>
                    <p class="page-description">Vehicles booked for a future visit.</p>
                </div>

                <a href="/appointment-new.php" class="button">+ New Appointment</a>
            </div>

            <div class="card">
                <div class="card-header">Upcoming</div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($appointments)): ?>
                        <div class="empty-state">No upcoming appointments. Book the first one.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr>
                                    <th>When</th>
                                    <th>Vehicle</th>
                                    <th>Customer</th>
                                    <th>Service</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                                <?php foreach ($appointments as $appointment): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars(date('d M, h:i A', strtotime($appointment['scheduled_at']))) ?></strong>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($appointment['registration_no']) ?>
                                            <div class="result-meta"><?= htmlspecialchars($appointment['make'] . ' ' . $appointment['model']) ?></div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($appointment['customer_name']) ?>
                                            <div class="result-meta"><?= htmlspecialchars($appointment['customer_phone']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($appointment['service_name'] ?? '—') ?></td>
                                        <td><span class="badge badge-received"><?= htmlspecialchars($appointment['status']) ?></span></td>
                                        <td style="white-space:nowrap;">
                                            <form method="POST" action="" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                                                <input type="hidden" name="action" value="convert_to_job_card">
                                                <button type="submit" class="button secondary" style="height:32px; padding:0 12px; font-size:12.5px;">Create job card</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <button type="submit" class="button secondary" style="height:32px; padding:0 12px; font-size:12.5px;">Cancel</button>
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
