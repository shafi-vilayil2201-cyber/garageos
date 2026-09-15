<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Gst.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'settings.manage');

$organizationId = $user['organization_id'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'settings.manage');

    $name = trim($_POST['name'] ?? '');
    $currency = trim($_POST['currency'] ?? '');
    $taxNumber = trim($_POST['tax_number'] ?? '');
    $defaultTaxRate = $_POST['default_tax_rate'] ?? '';
    $state = trim($_POST['state'] ?? '');

    if ($name === '' || $currency === '') {
        $error = 'Name and currency are required.';
    } elseif (!gst_rate_is_valid($defaultTaxRate)) {
        $error = 'Choose a valid GST rate.';
    } elseif ($state !== '' && !in_array($state, INDIAN_STATES, true)) {
        $error = 'Choose a valid state.';
    } else {
        $statement = $pdo->prepare("
            UPDATE organizations
            SET name = :name, currency = :currency, tax_number = :tax_number,
                default_tax_rate = :default_tax_rate, state = :state, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $statement->execute([
            'name' => $name,
            'currency' => $currency,
            'tax_number' => $taxNumber ?: null,
            'default_tax_rate' => $defaultTaxRate,
            'state' => $state ?: null,
            'id' => $organizationId
        ]);

        header('Location: /settings.php');
        exit;
    }
}

$statement = $pdo->prepare("SELECT * FROM organizations WHERE id = :id");
$statement->execute(['id' => $organizationId]);
$organization = $statement->fetch(PDO::FETCH_ASSOC);

$activeNav = 'settings';
$topbarTitle = 'Settings';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Settings</title>

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
                    <h1 class="page-title">Settings</h1>
                    <p class="page-description">This organization's branding and business details.</p>
                </div>
            </div>

            <div class="card" style="max-width: 480px;">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('warehouse', 15) ?></span>
                        Organization
                    </div>
                </div>
                <div class="card-body">

                    <?php if ($error): ?>
                        <div class="form-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <div class="form-grid single">
                            <div class="form-field">
                                <label>Name</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($organization['name']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Currency</label>
                                <input type="text" name="currency" value="<?= htmlspecialchars($organization['currency']) ?>" maxlength="10" required>
                            </div>
                            <div class="form-field">
                                <label><?= htmlspecialchars($organization['tax_label']) ?> number (GSTIN)</label>
                                <input type="text" name="tax_number" value="<?= htmlspecialchars($organization['tax_number'] ?? '') ?>" placeholder="e.g. 32AAAAA0000A1Z5" maxlength="50">
                                <p class="result-meta" style="margin-top:6px;">Shown on every invoice you generate — required for a valid GST tax invoice.</p>
                            </div>
                            <div class="form-field">
                                <label>State</label>
                                <select name="state">
                                    <?= state_options($organization['state'] ?? null) ?>
                                </select>
                                <p class="result-meta" style="margin-top:6px;">Compared against each customer's state to show GST as CGST+SGST (same state) or IGST (different state) on invoices.</p>
                            </div>
                            <div class="form-field">
                                <label>Default GST rate</label>
                                <select name="default_tax_rate">
                                    <?= gst_rate_options((float) $organization['default_tax_rate']) ?>
                                </select>
                                <p class="result-meta" style="margin-top:6px;">Used to pre-fill the GST rate when adding a new part or service — change it here once instead of picking it every time, or when the GST rate itself changes.</p>
                            </div>
                        </div>
                        <div class="form-actions" style="margin-top:14px;">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Save changes</button>
                        </div>
                    </form>

                    <p class="page-description" style="margin-top:16px;">
                        Editable branding and notification templates are on the roadmap.
                    </p>
                </div>
            </div>

            <div class="card" style="max-width: 480px; margin-top:20px;">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('settings', 15) ?></span>
                        Service catalog
                    </div>
                </div>
                <div class="card-body">
                    <p class="page-description">Add, price, and edit the services you offer.</p>
                    <a href="/services.php" class="button secondary" style="margin-top:10px;"><?= icon('settings', 16) ?> Manage services</a>
                </div>
            </div>

            <?php if (user_can($user, 'users.manage')): ?>
                <div class="card" style="max-width: 480px; margin-top:20px;">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('team', 15) ?></span>
                            Staff &amp; roles
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="page-description">Create staff accounts and assign what they can access.</p>
                        <a href="/users.php" class="button secondary" style="margin-top:10px;"><?= icon('team', 16) ?> Manage users</a>
                    </div>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

</body>
</html>
