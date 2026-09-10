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

require_permission($user, 'job_cards.manage');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $scheduledDate = trim($_POST['scheduled_date'] ?? '');
    $scheduledTime = trim($_POST['scheduled_time'] ?? '');
    $serviceId = (int) ($_POST['service_id'] ?? 0) ?: null;
    $source = $_POST['source'] ?? 'phone';
    $notes = trim($_POST['notes'] ?? '');

    if (!$vehicleId || !$customerId || $scheduledDate === '' || $scheduledTime === '') {
        $error = 'Search for a vehicle and pick a date and time before booking.';
    } else {

        $statement = $pdo->prepare("
            INSERT INTO appointments (organization_id, branch_id, customer_id, vehicle_id, service_id, scheduled_at, source, notes)
            VALUES (:organization_id, :branch_id, :customer_id, :vehicle_id, :service_id, :scheduled_at, :source, :notes)
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'service_id' => $serviceId,
            'scheduled_at' => $scheduledDate . ' ' . $scheduledTime,
            'source' => $source,
            'notes' => $notes ?: null
        ]);

        header('Location: /appointments.php');
        exit;
    }
}

$statement = $pdo->prepare("SELECT id, name FROM services WHERE organization_id = :organization_id AND status = 'active' ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$services = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'appointments';
$topbarTitle = 'New Appointment';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — New Appointment</title>

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
                    <h1 class="page-title">New Appointment</h1>
                    <p class="page-description">Look up the vehicle, then pick a date and time.</p>
                </div>
            </div>

            <div class="card" style="max-width: 640px;">
                <div class="card-body">

                    <?php if ($error): ?>
                        <div class="form-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <div class="search-row">
                        <input
                            type="search"
                            id="vehicle-search"
                            placeholder="Registration no. or customer phone..."
                            autocomplete="off"
                        >
                        <button type="button" class="button" id="vehicle-search-button">Search</button>
                    </div>

                    <div id="vehicle-results"></div>

                    <div id="vehicle-not-found" style="display:none; margin-bottom:16px;">
                        <p class="page-description">No match found.</p>
                        <a href="/vehicles.php" class="button secondary">Register a new vehicle</a>
                    </div>

                    <form method="POST" action="" id="appointment-form" style="display:none;">

                        <?= csrf_field() ?>

                        <input type="hidden" name="vehicle_id" id="selected_vehicle_id">
                        <input type="hidden" name="customer_id" id="selected_customer_id">

                        <div class="card" style="background:#faf9f6; padding:14px 16px; margin-bottom:16px;">
                            <strong id="selected_vehicle_label"></strong>
                            <div class="result-meta" id="selected_customer_label"></div>
                        </div>

                        <div class="form-grid">

                            <div class="form-field">
                                <label for="scheduled_date">Date</label>
                                <input type="date" id="scheduled_date" name="scheduled_date" required>
                            </div>

                            <div class="form-field">
                                <label for="scheduled_time">Time</label>
                                <input type="time" id="scheduled_time" name="scheduled_time" required>
                            </div>

                            <div class="form-field">
                                <label for="service_id">Service (optional)</label>
                                <select id="service_id" name="service_id">
                                    <option value="">Not decided yet</option>
                                    <?php foreach ($services as $service): ?>
                                        <option value="<?= (int) $service['id'] ?>"><?= htmlspecialchars($service['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-field">
                                <label for="source">Booked via</label>
                                <select id="source" name="source">
                                    <option value="phone">Phone</option>
                                    <option value="walk_in">Walk-in</option>
                                    <option value="landing_page">Landing page</option>
                                </select>
                            </div>

                        </div>

                        <div class="form-field" style="margin-top:16px;">
                            <label for="notes">Notes (optional)</label>
                            <textarea id="notes" name="notes"></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="button">Book appointment</button>
                        </div>

                    </form>

                </div>
            </div>

        </section>

    </main>

</div>

<script src="/js/appointment-new.js"></script>
</body>
</html>
