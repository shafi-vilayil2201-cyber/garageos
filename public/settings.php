<?php

require_once __DIR__ . '/../app/Auth/Auth.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'settings.manage');

$statement = $pdo->prepare("SELECT * FROM organizations WHERE id = :id");
$statement->execute(['id' => $user['organization_id']]);
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
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Name</label>
                            <div><?= htmlspecialchars($organization['name']) ?></div>
                        </div>
                        <div class="form-field">
                            <label>Currency</label>
                            <div><?= htmlspecialchars($organization['currency']) ?></div>
                        </div>
                        <div class="form-field">
                            <label><?= htmlspecialchars($organization['tax_label']) ?> number</label>
                            <div><?= htmlspecialchars($organization['tax_number'] ?? 'Not set') ?></div>
                        </div>
                    </div>
                    <p class="page-description" style="margin-top:16px;">
                        Editable branding, service catalog management, and notification templates are on the roadmap.
                    </p>
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
