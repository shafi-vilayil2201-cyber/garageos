<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Gst.php';
require_once __DIR__ . '/../app/Domain/Logo.php';

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
$logoError = null;
$logoSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_logo') {

    csrf_verify();
    require_permission($user, 'settings.manage');

    $statement = $pdo->prepare("SELECT logo_url FROM organizations WHERE id = :id");
    $statement->execute(['id' => $organizationId]);
    $previousLogoUrl = $statement->fetchColumn();

    $result = upload_organization_logo($_FILES['logo'] ?? null);

    if ($result['error']) {
        $logoError = $result['error'];
    } else {
        $pdo->prepare("UPDATE organizations SET logo_url = :logo_url, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute(['logo_url' => $result['path'], 'id' => $organizationId]);

        delete_old_logo_file($previousLogoUrl ?: null);

        $logoSuccess = 'Logo updated.';
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_logo') {

    csrf_verify();
    require_permission($user, 'settings.manage');

    $statement = $pdo->prepare("SELECT logo_url FROM organizations WHERE id = :id");
    $statement->execute(['id' => $organizationId]);
    $previousLogoUrl = $statement->fetchColumn();

    delete_old_logo_file($previousLogoUrl ?: null);

    $pdo->prepare("UPDATE organizations SET logo_url = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
        ->execute(['id' => $organizationId]);

    $logoSuccess = 'Logo removed.';

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_organization') {

    csrf_verify();
    require_permission($user, 'settings.manage');

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $currency = trim($_POST['currency'] ?? '');
    $taxNumber = trim($_POST['tax_number'] ?? '');
    $defaultTaxRate = $_POST['default_tax_rate'] ?? '';
    $state = trim($_POST['state'] ?? '');
    $serviceIntervalKm = (int) ($_POST['service_interval_km'] ?? 0);
    $serviceIntervalMonths = (int) ($_POST['service_interval_months'] ?? 0);

    if ($name === '' || $currency === '') {
        $error = 'Name and currency are required.';
    } elseif (!gst_rate_is_valid($defaultTaxRate)) {
        $error = 'Choose a valid GST rate.';
    } elseif ($state !== '' && !in_array($state, INDIAN_STATES, true)) {
        $error = 'Choose a valid state.';
    } elseif ($serviceIntervalKm <= 0 || $serviceIntervalMonths <= 0) {
        $error = 'Service interval must be a positive number of km and months.';
    } else {
        $statement = $pdo->prepare("
            UPDATE organizations
            SET name = :name, phone = :phone, email = :email, address = :address, currency = :currency,
                tax_number = :tax_number, default_tax_rate = :default_tax_rate, state = :state,
                service_interval_km = :service_interval_km, service_interval_months = :service_interval_months,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $statement->execute([
            'name' => $name,
            'phone' => $phone ?: null,
            'email' => $email ?: null,
            'address' => $address ?: null,
            'currency' => $currency,
            'tax_number' => $taxNumber ?: null,
            'default_tax_rate' => $defaultTaxRate,
            'state' => $state ?: null,
            'service_interval_km' => $serviceIntervalKm,
            'service_interval_months' => $serviceIntervalMonths,
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
                    <h1 class="page-title">Settings</h1>
                    <p class="page-description">Organization, tax, and service configuration.</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="form-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="settings-grid">

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('sparkle', 15) ?></span>
                            Logo
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($logoError): ?>
                            <div class="form-error"><?= htmlspecialchars($logoError) ?></div>
                        <?php endif; ?>
                        <?php if ($logoSuccess): ?>
                            <div class="form-success"><?= icon('check-circle', 16) ?> <?= htmlspecialchars($logoSuccess) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($organization['logo_url'])): ?>
                            <img src="<?= htmlspecialchars($organization['logo_url']) ?>" alt="Current logo" style="max-height:64px; display:block; margin-bottom:14px;">
                        <?php endif; ?>

                        <form method="POST" action="" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="upload_logo">
                            <div class="form-field">
                                <label>Upload new logo (PNG, JPEG, or WEBP, max 2MB)</label>
                                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
                            </div>
                            <div class="form-actions" style="margin-top:14px;">
                                <button type="submit" class="button"><?= icon('check', 16) ?> Upload</button>
                            </div>
                        </form>

                        <?php if (!empty($organization['logo_url'])): ?>
                            <form method="POST" action="" style="margin-top:10px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_logo">
                                <button type="submit" class="button secondary">Remove logo</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" action="" style="display:contents;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_organization">

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('warehouse', 15) ?></span>
                                Organization
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="form-grid single">
                                <div class="form-field">
                                    <label>Name</label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($organization['name']) ?>" required>
                                </div>
                                <div class="form-field">
                                    <label>Phone</label>
                                    <input type="tel" name="phone" value="<?= htmlspecialchars($organization['phone'] ?? '') ?>">
                                </div>
                                <div class="form-field">
                                    <label>Email</label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($organization['email'] ?? '') ?>">
                                </div>
                                <div class="form-field">
                                    <label>Address</label>
                                    <textarea name="address"><?= htmlspecialchars($organization['address'] ?? '') ?></textarea>
                                </div>
                                <div class="form-field">
                                    <label>Currency</label>
                                    <input type="text" name="currency" value="<?= htmlspecialchars($organization['currency']) ?>" maxlength="10" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                                Tax
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="form-grid single">
                                <div class="form-field">
                                    <label><?= htmlspecialchars($organization['tax_label']) ?> number</label>
                                    <input type="text" name="tax_number" value="<?= htmlspecialchars($organization['tax_number'] ?? '') ?>" placeholder="e.g. 32AAAAA0000A1Z5" maxlength="50">
                                </div>
                                <div class="form-field">
                                    <label>State</label>
                                    <select name="state">
                                        <?= state_options($organization['state'] ?? null) ?>
                                    </select>
                                </div>
                                <div class="form-field">
                                    <label>Default GST rate</label>
                                    <select name="default_tax_rate">
                                        <?= gst_rate_options((float) $organization['default_tax_rate']) ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('bell', 15) ?></span>
                                Service &amp; reminders
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="form-field">
                                <label>Service interval</label>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <input type="number" name="service_interval_km" min="1" value="<?= (int) $organization['service_interval_km'] ?>" style="width:110px;"> <span>km, or</span>
                                    <input type="number" name="service_interval_months" min="1" value="<?= (int) $organization['service_interval_months'] ?>" style="width:80px;"> <span>months</span>
                                </div>
                            </div>
                            <div class="form-actions" style="margin-top:14px;">
                                <button type="submit" class="button"><?= icon('check', 16) ?> Save changes</button>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('settings', 15) ?></span>
                            Service catalog
                        </div>
                    </div>
                    <div class="card-body">
                        <a href="/services.php" class="button secondary"><?= icon('settings', 16) ?> Manage services</a>
                    </div>
                </div>

                <?php if (user_can($user, 'users.manage')): ?>
                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('team', 15) ?></span>
                                Staff &amp; roles
                            </div>
                        </div>
                        <div class="card-body">
                            <a href="/users.php" class="button secondary"><?= icon('team', 16) ?> Manage users</a>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

</body>
</html>
