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
$branchId = $user['branch_id'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $complaint = trim($_POST['customer_complaint'] ?? '');
    $odometerIn = trim($_POST['odometer_in'] ?? '');

    if (!$vehicleId || !$customerId) {
        $error = 'Search for a vehicle and select it before creating the job card.';
    } else {

        $statement = $pdo->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING(job_no FROM 4) AS INT)), 0) + 1
            FROM job_cards
            WHERE branch_id = :branch_id
        ");
        $statement->execute(['branch_id' => $branchId]);
        $nextNumber = (int) $statement->fetchColumn();
        $jobNo = 'JC-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

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

        $statement->execute([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'job_no' => $jobNo,
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'advisor_id' => $user['id'],
            'customer_complaint' => $complaint ?: null,
            'odometer_in' => $odometerIn ?: null
        ]);

        $jobCardId = $statement->fetchColumn();

        header('Location: /job-card.php?id=' . $jobCardId);
        exit;
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
                    <p class="page-description">Look up the vehicle by registration number or the customer's phone.</p>
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

                    <form method="POST" action="" id="job-card-form" style="display:none;">

                        <input type="hidden" name="vehicle_id" id="selected_vehicle_id">
                        <input type="hidden" name="customer_id" id="selected_customer_id">

                        <div class="card" style="background:#faf9f6; padding:14px 16px; margin-bottom:16px;">
                            <strong id="selected_vehicle_label"></strong>
                            <div class="result-meta" id="selected_customer_label"></div>
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
                            <button type="submit" class="button">Create job card</button>
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
