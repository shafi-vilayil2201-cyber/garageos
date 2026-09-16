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

require_permission($user, 'job_cards.manage');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $mode = $_POST['mode'] ?? 'existing';
    $scheduledDate = trim($_POST['scheduled_date'] ?? '');
    $scheduledTime = trim($_POST['scheduled_time'] ?? '');
    $serviceId = (int) ($_POST['service_id'] ?? 0) ?: null;
    $source = $_POST['source'] ?? 'phone';
    $notes = trim($_POST['notes'] ?? '');

    if ($scheduledDate === '' || $scheduledTime === '') {
        $error = 'Pick a date and time before booking.';
    } elseif ($mode === 'new') {

        // Booking for a customer/vehicle that isn't in the system yet —
        // create both right here rather than sending the advisor away to
        // a separate screen and back.
        $customerName = trim($_POST['new_customer_name'] ?? '');
        $customerPhone = trim($_POST['new_customer_phone'] ?? '');
        $registrationNo = strtoupper(trim($_POST['new_registration_no'] ?? ''));
        $make = trim($_POST['new_make'] ?? '');
        $model = trim($_POST['new_model'] ?? '');
        $year = trim($_POST['new_year'] ?? '');
        $fuelType = $_POST['new_fuel_type'] ?? 'petrol';

        if ($customerName === '' || $customerPhone === '' || $registrationNo === '' || $make === '' || $model === '') {
            $error = 'Customer name, phone, registration number, make and model are all required.';
        } else {

            $pdo->beginTransaction();

            try {
                // Same phone calling again? Attach the new vehicle to
                // their existing record instead of creating a duplicate.
                $statement = $pdo->prepare("
                    SELECT id FROM customers
                    WHERE organization_id = :organization_id AND phone = :phone
                    LIMIT 1
                ");
                $statement->execute(['organization_id' => $organizationId, 'phone' => $customerPhone]);
                $customerId = $statement->fetchColumn();

                if (!$customerId) {
                    $statement = $pdo->prepare("
                        SELECT COALESCE(MAX(CAST(SUBSTRING(code FROM 6) AS INT)), 0) + 1
                        FROM customers WHERE organization_id = :organization_id
                    ");
                    $statement->execute(['organization_id' => $organizationId]);
                    $nextNumber = (int) $statement->fetchColumn();
                    $customerCode = 'CUST-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

                    $statement = $pdo->prepare("
                        INSERT INTO customers (organization_id, name, code, phone)
                        VALUES (:organization_id, :name, :code, :phone)
                        RETURNING id
                    ");
                    $statement->execute([
                        'organization_id' => $organizationId,
                        'name' => $customerName,
                        'code' => $customerCode,
                        'phone' => $customerPhone
                    ]);
                    $customerId = $statement->fetchColumn();
                }

                $statement = $pdo->prepare("
                    INSERT INTO vehicles (organization_id, customer_id, registration_no, make, model, year, fuel_type)
                    VALUES (:organization_id, :customer_id, :registration_no, :make, :model, :year, :fuel_type)
                    RETURNING id
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'customer_id' => $customerId,
                    'registration_no' => $registrationNo,
                    'make' => $make,
                    'model' => $model,
                    'year' => $year ?: null,
                    'fuel_type' => $fuelType
                ]);
                $vehicleId = $statement->fetchColumn();

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

                $pdo->commit();

                header('Location: /appointments.php');
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = str_contains($e->getMessage(), 'uq_vehicles_organization_registration')
                    ? 'A vehicle with that registration number already exists — search for it above instead.'
                    : 'Could not save that customer and vehicle. Double-check the details and try again.';
            }
        }

    } else {

        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $customerId = (int) ($_POST['customer_id'] ?? 0);

        if (!$vehicleId || !$customerId) {
            $error = 'Search for a vehicle and select it before booking.';
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
}

$statement = $pdo->prepare("SELECT id, name FROM services WHERE organization_id = :organization_id AND status = 'active' ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$services = $statement->fetchAll(PDO::FETCH_ASSOC);

$serviceOptions = '<option value="">Not decided yet</option>';
foreach ($services as $service) {
    $serviceOptions .= '<option value="' . (int) $service['id'] . '">' . htmlspecialchars($service['name']) . '</option>';
}

$extraFields = '
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
            <select id="appt-service_id" name="service_id">' . $serviceOptions . '</select>
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

            <div class="card" style="max-width: 640px;">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('search', 15) ?></span>
                        Find the vehicle
                    </div>
                </div>
                <div class="card-body">

                    <?php if ($error): ?>
                        <div class="form-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?= vehicle_intake_form('appt', '/appointment-new.php', $extraFields, 'calendar', 'Book appointment') ?>

                </div>
            </div>

        </section>

    </main>

</div>

<script src="/js/vehicle-intake.js"></script>
<script>initVehicleIntake('appt');</script>
</body>
</html>
