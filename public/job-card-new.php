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

function next_job_no(PDO $pdo, int $branchId): string
{
    $statement = $pdo->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING(job_no FROM 4) AS INT)), 0) + 1
        FROM job_cards
        WHERE branch_id = :branch_id
    ");
    $statement->execute(['branch_id' => $branchId]);
    $nextNumber = (int) $statement->fetchColumn();

    return 'JC-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
}

function create_job_card(PDO $pdo, array $fields): int
{
    $statement = $pdo->prepare("
        INSERT INTO job_cards (
            organization_id, branch_id, job_no, customer_id, vehicle_id,
            advisor_id, status, customer_complaint, odometer_in
        )
        VALUES (
            :organization_id, :branch_id, :job_no, :customer_id, :vehicle_id,
            :advisor_id, 'received', :customer_complaint, :odometer_in
        )
        RETURNING id
    ");
    $statement->execute($fields);

    return (int) $statement->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $mode = $_POST['mode'] ?? 'existing';
    $complaint = trim($_POST['customer_complaint'] ?? '');
    $odometerIn = trim($_POST['odometer_in'] ?? '');

    if ($mode === 'new') {

        // Walk-in with a vehicle that isn't in the system yet — create
        // the customer and vehicle right here instead of sending the
        // advisor away to a separate screen and back.
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

                $jobCardId = create_job_card($pdo, [
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'job_no' => next_job_no($pdo, $branchId),
                    'customer_id' => $customerId,
                    'vehicle_id' => $vehicleId,
                    'advisor_id' => $user['id'],
                    'customer_complaint' => $complaint ?: null,
                    'odometer_in' => $odometerIn ?: null
                ]);

                $pdo->commit();

                header('Location: /job-card.php?id=' . $jobCardId);
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
            $error = 'Search for a vehicle and select it before creating the job card.';
        } else {

            $jobCardId = create_job_card($pdo, [
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'job_no' => next_job_no($pdo, $branchId),
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'advisor_id' => $user['id'],
                'customer_complaint' => $complaint ?: null,
                'odometer_in' => $odometerIn ?: null
            ]);

            header('Location: /job-card.php?id=' . $jobCardId);
            exit;
        }
    }
}

$activeNav = 'job_cards';
$topbarTitle = 'New Job Card';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — New Job Card</title>

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
                    <h1 class="page-title">New Job Card</h1>
                    <p class="page-description">Look up the vehicle, or add a new customer and vehicle right here.</p>
                </div>
            </div>

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

                    <div class="search-row">
                        <input
                            type="search"
                            id="vehicle-search"
                            placeholder="Registration no. or customer phone..."
                            autocomplete="off"
                        >
                        <button type="button" class="button" id="vehicle-search-button"><?= icon('search', 16) ?> Search</button>
                    </div>

                    <div id="vehicle-results"></div>

                    <div id="vehicle-not-found" style="display:none; margin-bottom:16px;">
                        <p class="page-description">No match found.</p>
                        <button type="button" class="button secondary" id="add-new-button"><?= icon('plus', 16) ?> Add new customer &amp; vehicle</button>
                    </div>

                    <form method="POST" action="" id="job-card-form" style="display:none;">

                        <?= csrf_field() ?>

                        <input type="hidden" name="mode" id="form_mode" value="existing">
                        <input type="hidden" name="vehicle_id" id="selected_vehicle_id">
                        <input type="hidden" name="customer_id" id="selected_customer_id">

                        <div class="selected-summary" id="selected-summary">
                            <span class="icon-badge"><?= icon('car', 16) ?></span>
                            <div>
                                <strong id="selected_vehicle_label"></strong>
                                <div class="result-meta" id="selected_customer_label"></div>
                            </div>
                        </div>

                        <div id="new-customer-vehicle" style="display:none; margin-bottom:16px;">
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="new_customer_name">Customer name</label>
                                    <input type="text" id="new_customer_name" name="new_customer_name">
                                </div>
                                <div class="form-field">
                                    <label for="new_customer_phone">Customer phone</label>
                                    <input type="tel" id="new_customer_phone" name="new_customer_phone">
                                </div>
                                <div class="form-field">
                                    <label for="new_registration_no">Registration number</label>
                                    <input type="text" id="new_registration_no" name="new_registration_no" placeholder="KL-14-AB-1234">
                                </div>
                                <div class="form-field">
                                    <label for="new_fuel_type">Fuel type</label>
                                    <select id="new_fuel_type" name="new_fuel_type">
                                        <option value="petrol">Petrol</option>
                                        <option value="diesel">Diesel</option>
                                        <option value="ev">EV</option>
                                        <option value="hybrid">Hybrid</option>
                                        <option value="cng">CNG</option>
                                    </select>
                                </div>
                                <div class="form-field">
                                    <label for="new_make">Make</label>
                                    <input type="text" id="new_make" name="new_make" placeholder="Maruti Suzuki">
                                </div>
                                <div class="form-field">
                                    <label for="new_model">Model</label>
                                    <input type="text" id="new_model" name="new_model" placeholder="Swift">
                                </div>
                                <div class="form-field">
                                    <label for="new_year">Year (optional)</label>
                                    <input type="number" id="new_year" name="new_year" min="1980" max="2100">
                                </div>
                            </div>
                        </div>

                        <div class="form-grid single">

                            <div class="form-field">
                                <label for="odometer_in">Odometer reading (km)</label>
                                <input type="number" id="odometer_in" name="odometer_in" min="0">
                            </div>

                            <div class="form-field">
                                <label for="customer_complaint">Customer complaint / request</label>
                                <textarea id="customer_complaint" name="customer_complaint" placeholder="e.g. Engine noise, brakes feel soft..."></textarea>
                            </div>

                        </div>

                        <div class="form-actions">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Create job card</button>
                        </div>

                    </form>

                </div>
            </div>

        </section>

    </main>

</div>

<script src="/js/job-card-new.js"></script>
</body>
</html>
