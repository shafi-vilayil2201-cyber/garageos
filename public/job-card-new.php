<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/VehicleIntake.php';
require_once __DIR__ . '/../app/View/JobCardIntakeExtras.php';
require_once __DIR__ . '/../app/Domain/Audit.php';
require_once __DIR__ . '/../app/Domain/Accessory.php';
require_once __DIR__ . '/../app/Domain/DamageImage.php';

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

// Jumping in from a customer's history page (public/customer.php) skips the
// search-a-vehicle step entirely, since the customer is already known —
// see the plan at /Users/shafivilayil/.claude/plans/partitioned-whistling-nova.md.
// This is deliberately kept separate from vehicle_intake_form()/VehicleIntake.php
// (shared with appointment-new.php) rather than adding a third mode there.
$shortcutCustomerId = (int) ($_GET['customer_id'] ?? 0);
$shortcutVehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$shortcutCustomer = null;
$shortcutVehicles = [];
$shortcutSelectedVehicle = null;

if ($shortcutCustomerId) {

    $statement = $pdo->prepare("SELECT id, name, phone FROM customers WHERE id = :id AND organization_id = :organization_id");
    $statement->execute(['id' => $shortcutCustomerId, 'organization_id' => $organizationId]);
    $shortcutCustomer = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$shortcutCustomer) {
        header('Location: /customers.php');
        exit;
    }

    $statement = $pdo->prepare("
        SELECT id, registration_no, make, model, year
        FROM vehicles
        WHERE customer_id = :customer_id AND organization_id = :organization_id
        ORDER BY created_at DESC
    ");
    $statement->execute(['customer_id' => $shortcutCustomerId, 'organization_id' => $organizationId]);
    $shortcutVehicles = $statement->fetchAll(PDO::FETCH_ASSOC);

    if ($shortcutVehicleId) {
        foreach ($shortcutVehicles as $vehicle) {
            if ((int) $vehicle['id'] === $shortcutVehicleId) {
                $shortcutSelectedVehicle = $vehicle;
                break;
            }
        }
    }

    if (!$shortcutSelectedVehicle && count($shortcutVehicles) === 1) {
        $shortcutSelectedVehicle = $shortcutVehicles[0];
    }
}

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
            advisor_id, status, customer_complaint, odometer_in, promised_at, advance_amount
        )
        VALUES (
            :organization_id, :branch_id, :job_no, :customer_id, :vehicle_id,
            :advisor_id, 'received', :customer_complaint, :odometer_in, :promised_at, :advance_amount
        )
        RETURNING id
    ");
    $statement->execute($fields);

    return (int) $statement->fetchColumn();
}

// Turns the repeatable "Customer Voice" rows into the single numbered
// string job_cards.customer_complaint already stores — every existing
// reader of that column (print page, quick-edit modal, audit log) keeps
// working unchanged; only how staff type it in has improved.
function join_customer_voice(array $entries): ?string
{
    $lines = [];
    $seq = 1;

    foreach ($entries as $entry) {
        $entry = trim($entry);

        if ($entry !== '') {
            $lines[] = "{$seq}. {$entry}";
            $seq++;
        }
    }

    return $lines ? implode("\n", $lines) : null;
}

function insert_accessories(PDO $pdo, int $jobCardId, array $keys): void
{
    $validKeys = array_values(array_unique(array_filter($keys, 'accessory_is_valid')));

    if (!$validKeys) {
        return;
    }

    $statement = $pdo->prepare("
        INSERT INTO job_card_accessories (job_card_id, accessory_key)
        VALUES (:job_card_id, :accessory_key)
    ");

    foreach ($validKeys as $key) {
        $statement->execute(['job_card_id' => $jobCardId, 'accessory_key' => $key]);
    }
}

function attach_damage_image(PDO $pdo, int $jobCardId, ?string $dataUrl): void
{
    $damageImageUrl = save_damage_image($jobCardId, $dataUrl);

    if (!$damageImageUrl) {
        return;
    }

    $statement = $pdo->prepare("
        UPDATE job_cards
        SET damage_image_url = :damage_image_url, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $statement->execute(['damage_image_url' => $damageImageUrl, 'id' => $jobCardId]);
}

function parse_damage_marks(?string $json): array
{
    $decoded = json_decode($json ?: '', true);

    if (!is_array($decoded)) {
        return [];
    }

    $marks = [];
    $seen = [];

    foreach ($decoded as $mark) {
        if (!is_array($mark)) {
            continue;
        }

        $partKey = (string) ($mark['part_key'] ?? '');
        $damageType = (string) ($mark['damage_type'] ?? '');
        $x = (float) ($mark['x'] ?? -1);
        $y = (float) ($mark['y'] ?? -1);
        $uniqueKey = $partKey . ':' . $damageType;

        if (!vehicle_damage_part_is_valid($partKey)
            || !vehicle_damage_type_is_valid($damageType)
            || $x < 0 || $x > 560
            || $y < 0 || $y > 879
            || isset($seen[$uniqueKey])) {
            continue;
        }

        $seen[$uniqueKey] = true;
        $marks[] = [
            'part_key' => $partKey,
            'damage_type' => $damageType,
            'x' => round($x, 2),
            'y' => round($y, 2),
        ];
    }

    return $marks;
}

function insert_damage_marks(PDO $pdo, int $jobCardId, array $marks): void
{
    if (!$marks) {
        return;
    }

    $statement = $pdo->prepare("
        INSERT INTO job_card_damage_marks (job_card_id, part_key, damage_type, x, y)
        VALUES (:job_card_id, :part_key, :damage_type, :x, :y)
    ");

    foreach ($marks as $mark) {
        $statement->execute([
            'job_card_id' => $jobCardId,
            'part_key' => $mark['part_key'],
            'damage_type' => $mark['damage_type'],
            'x' => $mark['x'],
            'y' => $mark['y'],
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $mode = $_POST['mode'] ?? 'existing';
    $complaint = join_customer_voice($_POST['customer_voice'] ?? []);
    $odometerIn = trim($_POST['odometer_in'] ?? '');
    $promisedAt = trim($_POST['promised_at'] ?? '');
    $advanceAmount = trim($_POST['advance_amount'] ?? '');
    $accessoryKeys = $_POST['accessories'] ?? [];
    $damageImageData = $_POST['damage_image_data'] ?? null;
    $damageMarks = parse_damage_marks($_POST['damage_marks_json'] ?? null);

    if ($mode === 'new') {

        // Walk-in with a vehicle that isn't in the system yet — create
        // the customer and vehicle right here instead of sending the
        // advisor away to a separate screen and back.
        $customerName = trim($_POST['new_customer_name'] ?? '');
        $customerPhone = trim($_POST['new_customer_phone'] ?? '');
        $customerEmail = trim($_POST['new_customer_email'] ?? '');
        $customerAddress = trim($_POST['new_customer_address'] ?? '');
        $registrationNo = strtoupper(trim($_POST['new_registration_no'] ?? ''));
        $make = trim($_POST['new_make'] ?? '');
        $model = trim($_POST['new_model'] ?? '');
        $year = trim($_POST['new_year'] ?? '');
        $fuelType = $_POST['new_fuel_type'] ?? 'petrol';
        $color = trim($_POST['new_color'] ?? '');
        $vin = trim($_POST['new_vin'] ?? '');

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
                        INSERT INTO customers (organization_id, name, code, phone, email, address)
                        VALUES (:organization_id, :name, :code, :phone, :email, :address)
                        RETURNING id
                    ");
                    $statement->execute([
                        'organization_id' => $organizationId,
                        'name' => $customerName,
                        'code' => $customerCode,
                        'phone' => $customerPhone,
                        'email' => $customerEmail ?: null,
                        'address' => $customerAddress ?: null
                    ]);
                    $customerId = $statement->fetchColumn();
                }

                $statement = $pdo->prepare("
                    INSERT INTO vehicles (organization_id, customer_id, registration_no, make, model, year, fuel_type, color, vin)
                    VALUES (:organization_id, :customer_id, :registration_no, :make, :model, :year, :fuel_type, :color, :vin)
                    RETURNING id
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'customer_id' => $customerId,
                    'registration_no' => $registrationNo,
                    'make' => $make,
                    'model' => $model,
                    'year' => $year ?: null,
                    'fuel_type' => $fuelType,
                    'color' => $color ?: null,
                    'vin' => $vin ?: null
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
                    'customer_complaint' => $complaint,
                    'odometer_in' => $odometerIn ?: null,
                    'promised_at' => $promisedAt ?: null,
                    'advance_amount' => $advanceAmount ?: null
                ]);

                insert_accessories($pdo, $jobCardId, $accessoryKeys);
                insert_damage_marks($pdo, $jobCardId, $damageMarks);
                attach_damage_image($pdo, $jobCardId, $damageImageData);

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

            $pdo->beginTransaction();

            try {
                $jobNo = next_job_no($pdo, $branchId);

                $jobCardId = create_job_card($pdo, [
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'job_no' => $jobNo,
                    'customer_id' => $customerId,
                    'vehicle_id' => $vehicleId,
                    'advisor_id' => $user['id'],
                    'customer_complaint' => $complaint,
                    'odometer_in' => $odometerIn ?: null,
                    'promised_at' => $promisedAt ?: null,
                    'advance_amount' => $advanceAmount ?: null
                ]);

                insert_accessories($pdo, $jobCardId, $accessoryKeys);
                insert_damage_marks($pdo, $jobCardId, $damageMarks);
                attach_damage_image($pdo, $jobCardId, $damageImageData);

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

                $pdo->commit();

                header('Location: /job-card.php?id=' . $jobCardId);
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Could not create the job card. Please try again.';
            }
        }
    }
}

$activeNav = 'job_cards';
$topbarTitle = 'New Job Card';

$extraFields = job_card_intake_extras();

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

            <?php if ($shortcutCustomer): ?>

                <a href="/customer.php?id=<?= (int) $shortcutCustomer['id'] ?>" class="link-action" style="margin-bottom:16px;"><?= icon('arrow-left', 14) ?> Back to <?= htmlspecialchars($shortcutCustomer['name']) ?></a>

                <div class="page-header">
                    <div>
                        <h1 class="page-title">New Job Card</h1>
                        <p class="page-description">For <?= htmlspecialchars($shortcutCustomer['name']) ?> (<?= htmlspecialchars($shortcutCustomer['phone']) ?>)</p>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($shortcutSelectedVehicle): ?>

                    <div class="card" style="max-width: 640px;">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('car', 15) ?></span>
                                Vehicle
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="/job-card-new.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="vehicle_id" value="<?= (int) $shortcutSelectedVehicle['id'] ?>">
                                <input type="hidden" name="customer_id" value="<?= (int) $shortcutCustomer['id'] ?>">

                                <div class="selected-summary" style="margin-bottom:16px;">
                                    <span class="icon-badge"><?= icon('car', 16) ?></span>
                                    <div>
                                        <strong><?= htmlspecialchars($shortcutSelectedVehicle['registration_no']) ?></strong>
                                        <div class="result-meta">
                                            <?= htmlspecialchars($shortcutSelectedVehicle['make'] . ' ' . $shortcutSelectedVehicle['model']) ?><?= $shortcutSelectedVehicle['year'] ? ' · ' . htmlspecialchars($shortcutSelectedVehicle['year']) : '' ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if (count($shortcutVehicles) > 1): ?>
                                    <p class="result-meta" style="margin-bottom:16px;">
                                        Not the right vehicle? <a href="/job-card-new.php?customer_id=<?= (int) $shortcutCustomer['id'] ?>" class="link-action" style="display:inline;">Choose a different one</a>
                                    </p>
                                <?php endif; ?>

                                <?= $extraFields ?>

                                <div class="form-actions">
                                    <button type="submit" class="button"><?= icon('check', 16) ?> Create job card</button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php elseif (count($shortcutVehicles) > 1): ?>

                    <div class="card" style="max-width: 640px;">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('car', 15) ?></span>
                                Which vehicle?
                            </div>
                        </div>
                        <div class="card-body">
                            <?php foreach ($shortcutVehicles as $vehicle): ?>
                                <a href="/job-card-new.php?customer_id=<?= (int) $shortcutCustomer['id'] ?>&vehicle_id=<?= (int) $vehicle['id'] ?>" class="search-result" style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                                    <span class="icon-badge"><?= icon('car', 16) ?></span>
                                    <div>
                                        <strong><?= htmlspecialchars($vehicle['registration_no']) ?></strong>
                                        <div class="result-meta">
                                            <?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?><?= $vehicle['year'] ? ' · ' . htmlspecialchars($vehicle['year']) : '' ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php else: ?>

                    <div class="card" style="max-width: 640px;">
                        <div class="card-body">
                            <div class="empty-state">
                                <?= icon('car', 28) ?>
                                <?= htmlspecialchars($shortcutCustomer['name']) ?> has no vehicles on file yet.
                                <a href="/vehicles.php?customer_id=<?= (int) $shortcutCustomer['id'] ?>" class="button secondary"><?= icon('plus', 16) ?> Add a vehicle</a>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>

            <?php else: ?>

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

            <?php endif; ?>

        </section>

    </main>

</div>

<?php if (!$shortcutCustomer): ?>
    <script src="/js/vehicle-intake.js"></script>
    <script>initVehicleIntake('jobcard');</script>
<?php endif; ?>
<script src="/js/customer-voice-list.js"></script>
<script src="/js/damage-diagram.js"></script>
<script>
    initCustomerVoiceList('jobcard');
    initDamageDiagram('jobcard');
</script>
</body>
</html>
