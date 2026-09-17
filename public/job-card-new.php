<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/VehicleIntake.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

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
            advisor_id, status, customer_complaint, odometer_in, promised_at
        )
        VALUES (
            :organization_id, :branch_id, :job_no, :customer_id, :vehicle_id,
            :advisor_id, 'received', :customer_complaint, :odometer_in, :promised_at
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
    $promisedAt = trim($_POST['promised_at'] ?? '');

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

                $jobNo = next_job_no($pdo, $branchId);

                $jobCardId = create_job_card($pdo, [
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'job_no' => $jobNo,
                    'customer_id' => $customerId,
                    'vehicle_id' => $vehicleId,
                    'advisor_id' => $user['id'],
                    'customer_complaint' => $complaint ?: null,
                    'odometer_in' => $odometerIn ?: null,
                    'promised_at' => $promisedAt ?: null
                ]);

                log_audit_event(
                    $pdo, $user, 'create', 'job_card', $jobCardId,
                    "Opened job card $jobNo for $customerName — $registrationNo"
                );

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

            $jobNo = next_job_no($pdo, $branchId);

            $jobCardId = create_job_card($pdo, [
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'job_no' => $jobNo,
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'advisor_id' => $user['id'],
                'customer_complaint' => $complaint ?: null,
                'odometer_in' => $odometerIn ?: null,
                'promised_at' => $promisedAt ?: null
            ]);

            $statement = $pdo->prepare("
                SELECT c.name AS customer_name, v.registration_no
                FROM customers c, vehicles v
                WHERE c.id = :customer_id AND v.id = :vehicle_id
            ");
            $statement->execute(['customer_id' => $customerId, 'vehicle_id' => $vehicleId]);
            $jobCardFor = $statement->fetch(PDO::FETCH_ASSOC);

            if ($jobCardFor) {
                log_audit_event(
                    $pdo, $user, 'create', 'job_card', $jobCardId,
                    "Opened job card $jobNo for {$jobCardFor['customer_name']} — {$jobCardFor['registration_no']}"
                );
            }

            header('Location: /job-card.php?id=' . $jobCardId);
            exit;
        }
    }
}

$activeNav = 'job_cards';
$topbarTitle = 'New Job Card';

$extraFields = '
    <div class="form-grid single">
        <div class="form-field">
            <label for="jobcard-odometer_in">Odometer reading (km)</label>
            <input type="number" id="jobcard-odometer_in" name="odometer_in" min="0">
        </div>
        <div class="form-field">
            <label for="jobcard-promised_at">Promised delivery (optional)</label>
            <input type="datetime-local" id="jobcard-promised_at" name="promised_at">
        </div>
        <div class="form-field">
            <label for="jobcard-customer_complaint">Customer complaint / request</label>
            <textarea id="jobcard-customer_complaint" name="customer_complaint" placeholder="e.g. Engine noise, brakes feel soft..."></textarea>
        </div>
    </div>
';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — New Job Card</title>

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

                    <?= vehicle_intake_form('jobcard', '/job-card-new.php', $extraFields, 'check', 'Create job card') ?>

                </div>
            </div>

        </section>

    </main>

</div>

<script src="/js/vehicle-intake.js"></script>
<script>initVehicleIntake('jobcard');</script>
</body>
</html>
