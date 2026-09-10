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

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '' || $phone === '') {
        $error = 'Name and phone are required.';
    } else {

        $statement = $pdo->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING(code FROM 6) AS INT)), 0) + 1
            FROM customers
            WHERE organization_id = :organization_id
        ");
        $statement->execute(['organization_id' => $organizationId]);
        $nextNumber = (int) $statement->fetchColumn();
        $code = 'CUST-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        $statement = $pdo->prepare("
            INSERT INTO customers (organization_id, name, code, phone, email, address)
            VALUES (:organization_id, :name, :code, :phone, :email, :address)
        ");

        $statement->execute([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'phone' => $phone,
            'email' => $email ?: null,
            'address' => $address ?: null
        ]);

        header('Location: /customers.php');
        exit;
    }
}

$statement = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.code,
        c.phone,
        c.email,
        (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id) AS vehicle_count
    FROM customers c
    WHERE c.organization_id = :organization_id
    ORDER BY c.created_at DESC
");
$statement->execute(['organization_id' => $organizationId]);
$customers = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'customers';
$topbarTitle = 'Customers';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Customers</title>

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
                    <h1 class="page-title">Customers</h1>
                    <p class="page-description">Everyone who's ever brought a vehicle to your workshop.</p>
                </div>
            </div>

            <div class="content-grid" style="grid-template-columns: 1fr 380px;">

                <div class="card">
                    <div class="card-header">All customers</div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($customers)): ?>
                            <div class="empty-state">No customers yet. Add your first one.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Vehicles</th>
                                        <th></th>
                                    </tr>
                                    <?php foreach ($customers as $customer): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($customer['name']) ?></strong>
                                                <div class="result-meta"><?= htmlspecialchars($customer['code']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($customer['phone']) ?></td>
                                            <td><?= (int) $customer['vehicle_count'] ?></td>
                                            <td>
                                                <a href="/vehicles.php?customer_id=<?= (int) $customer['id'] ?>" style="font-size:13px; color: var(--primary); font-weight:600;">
                                                    + Add vehicle
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Add a customer</div>
                    <div class="card-body">

                        <?php if ($error): ?>
                            <div class="form-error"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">

                            <div class="form-grid single">

                                <div class="form-field">
                                    <label for="name">Full name</label>
                                    <input type="text" id="name" name="name" required>
                                </div>

                                <div class="form-field">
                                    <label for="phone">Phone</label>
                                    <input type="tel" id="phone" name="phone" required>
                                </div>

                                <div class="form-field">
                                    <label for="email">Email (optional)</label>
                                    <input type="email" id="email" name="email">
                                </div>

                                <div class="form-field">
                                    <label for="address">Address (optional)</label>
                                    <textarea id="address" name="address"></textarea>
                                </div>

                            </div>

                            <div class="form-actions">
                                <button type="submit" class="button">Save customer</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
