<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/Pagination.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'vehicles.view');

$organizationId = $user['organization_id'];
$canManageVehicles = user_can($user, 'vehicles.manage');

$error = null;
$errorAction = null;
$preselectedCustomerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : null;
$editingVehicleId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'vehicles.manage');

    $action = $_POST['action'] ?? 'create';
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $registrationNo = strtoupper(trim($_POST['registration_no'] ?? ''));
    $make = trim($_POST['make'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $fuelType = $_POST['fuel_type'] ?? 'petrol';

    if ($action === 'update') {

        $editingVehicleId = (int) ($_POST['vehicle_id'] ?? 0);

        if (!$customerId || $registrationNo === '' || $make === '' || $model === '') {
            $error = 'Customer, registration number, make and model are required.';
            $errorAction = 'update';
        } else {
            $statement = $pdo->prepare("
                UPDATE vehicles
                SET customer_id = :customer_id, registration_no = :registration_no, make = :make,
                    model = :model, year = :year, fuel_type = :fuel_type, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND organization_id = :organization_id
            ");
            $statement->execute([
                'customer_id' => $customerId,
                'registration_no' => $registrationNo,
                'make' => $make,
                'model' => $model,
                'year' => $year ?: null,
                'fuel_type' => $fuelType,
                'id' => $editingVehicleId,
                'organization_id' => $organizationId
            ]);

            header('Location: /vehicles.php');
            exit;
        }

    } else {

        if (!$customerId || $registrationNo === '' || $make === '' || $model === '') {
            $error = 'Customer, registration number, make and model are required.';
            $errorAction = 'create';
            $preselectedCustomerId = $customerId;
        } else {

            $statement = $pdo->prepare("
                INSERT INTO vehicles (organization_id, customer_id, registration_no, make, model, year, fuel_type)
                VALUES (:organization_id, :customer_id, :registration_no, :make, :model, :year, :fuel_type)
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

            header('Location: /vehicles.php');
            exit;
        }
    }
}

$statement = $pdo->prepare("
    SELECT id, name FROM customers
    WHERE organization_id = :organization_id
    ORDER BY name
");
$statement->execute(['organization_id' => $organizationId]);
$customers = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE organization_id = :organization_id");
$statement->execute(['organization_id' => $organizationId]);
$totalVehicles = (int) $statement->fetchColumn();
$page = paginate_page($totalVehicles);

$statement = $pdo->prepare("
    SELECT
        v.id,
        v.registration_no,
        v.make,
        v.model,
        v.year,
        v.fuel_type,
        c.name AS customer_name
    FROM vehicles v
    INNER JOIN customers c ON c.id = v.customer_id
    WHERE v.organization_id = :organization_id
    ORDER BY v.created_at DESC
    LIMIT :limit OFFSET :offset
");
$statement->bindValue('organization_id', $organizationId);
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$vehicles = $statement->fetchAll(PDO::FETCH_ASSOC);

$editingVehicle = null;

if ($editingVehicleId) {
    $statement = $pdo->prepare("
        SELECT id, customer_id, registration_no, make, model, year, fuel_type
        FROM vehicles
        WHERE id = :id AND organization_id = :organization_id
    ");
    $statement->execute(['id' => $editingVehicleId, 'organization_id' => $organizationId]);
    $editingVehicle = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$editingVehicle) {
        header('Location: /vehicles.php');
        exit;
    }
}

// Open straight to the form if we arrived from "+ Add vehicle" on a
// customer's row, or if a submission just failed and needs fixing.
$openModalOnLoad = $errorAction === 'create' || $preselectedCustomerId;

$activeNav = 'vehicles';
$topbarTitle = 'Vehicles';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Vehicles</title>

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
                    <h1 class="page-title">Vehicles</h1>
                    <p class="page-description">Every vehicle that's passed through your workshop.</p>
                </div>

                <?php if ($canManageVehicles): ?>
                    <button type="button" class="button" onclick="openModal('vehicle-modal')"><?= icon('plus', 16) ?> Add Vehicle</button>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('car', 15) ?></span>
                        All vehicles
                    </div>
                    <?php if (!empty($vehicles)): ?>
                        <input type="search" id="vehicle-filter" placeholder="Search by registration, make, or owner..." autocomplete="off" style="max-width:280px;">
                    <?php endif; ?>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($vehicles)): ?>
                        <div class="empty-state">
                            <?= icon('car', 28) ?>
                            No vehicles yet. Add one for an existing customer.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Registration</th>
                                        <th>Vehicle</th>
                                        <th>Owner</th>
                                        <th>Fuel</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="vehicles-tbody">
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($vehicle['registration_no']) ?></strong></td>
                                        <td>
                                            <?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?>
                                            <?php if ($vehicle['year']): ?>
                                                <div class="result-meta"><?= htmlspecialchars($vehicle['year']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($vehicle['customer_name']) ?></td>
                                        <td style="text-transform:capitalize;"><?= htmlspecialchars($vehicle['fuel_type']) ?></td>
                                        <td>
                                            <?php if ($canManageVehicles): ?>
                                                <a href="?edit=<?= (int) $vehicle['id'] ?>" class="link-action"><?= icon('settings', 14) ?> Edit</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="vehicles-pagination"><?= render_pagination($page, $totalVehicles) ?></div>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

<?php if ($canManageVehicles): ?>

    <div class="modal-backdrop<?= $openModalOnLoad ? ' open' : '' ?>" id="vehicle-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('car', 16) ?></span>
                    Add Vehicle
                </div>
                <button type="button" class="modal-close" data-close-modal="vehicle-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'create' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if (empty($customers)): ?>
                    <p class="page-description">Add a customer first before registering their vehicle.</p>
                <?php else: ?>
                    <form method="POST" action="">

                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">

                        <div class="form-grid single">

                            <div class="form-field">
                                <label for="customer_id">Owner</label>
                                <select id="customer_id" name="customer_id" required>
                                    <option value="">Select customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= (int) $customer['id'] ?>" <?= $preselectedCustomerId === (int) $customer['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($customer['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-field">
                                <label for="registration_no">Registration number</label>
                                <input type="text" id="registration_no" name="registration_no" placeholder="KL-14-AB-1234" required>
                            </div>

                            <div class="form-field">
                                <label for="make">Make</label>
                                <input type="text" id="make" name="make" placeholder="Maruti Suzuki" required>
                            </div>

                            <div class="form-field">
                                <label for="model">Model</label>
                                <input type="text" id="model" name="model" placeholder="Swift" required>
                            </div>

                            <div class="form-field">
                                <label for="year">Year</label>
                                <input type="number" id="year" name="year" min="1980" max="2100">
                            </div>

                            <div class="form-field">
                                <label for="fuel_type">Fuel type</label>
                                <select id="fuel_type" name="fuel_type">
                                    <option value="petrol">Petrol</option>
                                    <option value="diesel">Diesel</option>
                                    <option value="ev">EV</option>
                                    <option value="hybrid">Hybrid</option>
                                    <option value="cng">CNG</option>
                                </select>
                            </div>

                        </div>

                        <div class="form-actions">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Save vehicle</button>
                        </div>

                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="modal-backdrop<?= $editingVehicle ? ' open' : '' ?>" id="edit-vehicle-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('car', 16) ?></span>
                    Edit Vehicle
                </div>
                <button type="button" class="modal-close" data-close-modal="edit-vehicle-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'update' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($editingVehicle): ?>
                    <form method="POST" action="">

                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="vehicle_id" value="<?= (int) $editingVehicle['id'] ?>">

                        <div class="form-grid single">

                            <div class="form-field">
                                <label>Owner</label>
                                <select name="customer_id" required>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= (int) $customer['id'] ?>" <?= (int) $editingVehicle['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($customer['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-field">
                                <label>Registration number</label>
                                <input type="text" name="registration_no" value="<?= htmlspecialchars($editingVehicle['registration_no']) ?>" required>
                            </div>

                            <div class="form-field">
                                <label>Make</label>
                                <input type="text" name="make" value="<?= htmlspecialchars($editingVehicle['make']) ?>" required>
                            </div>

                            <div class="form-field">
                                <label>Model</label>
                                <input type="text" name="model" value="<?= htmlspecialchars($editingVehicle['model']) ?>" required>
                            </div>

                            <div class="form-field">
                                <label>Year</label>
                                <input type="number" name="year" min="1980" max="2100" value="<?= htmlspecialchars($editingVehicle['year'] ?? '') ?>">
                            </div>

                            <div class="form-field">
                                <label>Fuel type</label>
                                <select name="fuel_type">
                                    <?php foreach (['petrol' => 'Petrol', 'diesel' => 'Diesel', 'ev' => 'EV', 'hybrid' => 'Hybrid', 'cng' => 'CNG'] as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $editingVehicle['fuel_type'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>

                        <div class="form-actions">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Save changes</button>
                        </div>

                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="/js/modal.js"></script>

<?php endif; ?>

<?php if (!empty($vehicles)): ?>

    <script src="/js/live-table-search.js"></script>
    <script>
        const canManageVehicles = <?= json_encode($canManageVehicles) ?>;
        const editIcon = <?= json_encode(icon('settings', 14)) ?>;

        initLiveTableSearch({
            inputId: 'vehicle-filter',
            tbodyId: 'vehicles-tbody',
            paginationId: 'vehicles-pagination',
            endpoint: '/api/vehicles/search.php',
            resultsKey: 'vehicles',
            colspan: 5,
            emptyMessage: 'No vehicles found.',
            renderRow: (vehicle, escapeHtml) => `
                <tr>
                    <td><strong>${escapeHtml(vehicle.registration_no)}</strong></td>
                    <td>
                        ${escapeHtml(vehicle.make)} ${escapeHtml(vehicle.model)}
                        ${vehicle.year ? `<div class="result-meta">${escapeHtml(vehicle.year)}</div>` : ''}
                    </td>
                    <td>${escapeHtml(vehicle.customer_name)}</td>
                    <td style="text-transform:capitalize;">${escapeHtml(vehicle.fuel_type)}</td>
                    <td>
                        ${canManageVehicles ? `<a href="?edit=${vehicle.id}" class="link-action">${editIcon} Edit</a>` : ''}
                    </td>
                </tr>
            `
        });
    </script>

<?php endif; ?>

</body>
</html>
