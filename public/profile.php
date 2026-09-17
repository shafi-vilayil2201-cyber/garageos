<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

$error = null;
$errorAction = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $action = $_POST['action'] ?? '';
    $errorAction = $action;

    if ($action === 'update_profile') {

        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            $error = 'Name is required.';
        } else {
            $statement = $pdo->prepare("UPDATE users SET name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $statement->execute(['name' => $name, 'id' => $user['id']]);

            log_audit_event($pdo, $user, 'update', 'user', $user['id'], "Updated own profile ($name)");

            $user['name'] = $name;
            $success = 'Profile updated.';
        }

    } elseif ($action === 'change_password') {

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
        } else {

            $statement = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
            $statement->execute(['id' => $user['id']]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                $error = 'Current password is incorrect.';
            } else {
                $statement = $pdo->prepare("UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $statement->execute([
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'id' => $user['id']
                ]);

                log_audit_event($pdo, $user, 'update', 'user', $user['id'], 'Changed own password');

                $success = 'Password updated.';
            }
        }
    }
}

$activeNav = '';
$topbarTitle = 'Profile';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Profile</title>

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
                    <h1 class="page-title">Profile</h1>
                    <p class="page-description">Your account details and password.</p>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="form-success"><?= icon('check-circle', 16) ?> <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="settings-grid">

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('person', 15) ?></span>
                            Account details
                        </div>
                    </div>
                    <div class="card-body">

                        <?php if ($error && $errorAction === 'update_profile'): ?>
                            <div class="form-error"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_profile">
                            <div class="form-grid single">
                                <div class="form-field">
                                    <label>Name</label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
                                </div>
                                <div class="form-field">
                                    <label>Email</label>
                                    <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                                    <p class="result-meta" style="margin-top:6px;">Contact an admin to change your email.</p>
                                </div>
                                <div class="form-field">
                                    <label>Organization</label>
                                    <input type="text" value="<?= htmlspecialchars($user['organization_name']) ?>" disabled>
                                </div>
                            </div>
                            <div class="form-actions" style="margin-top:14px;">
                                <button type="submit" class="button"><?= icon('check', 16) ?> Save changes</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('logout', 15) ?></span>
                            Change password
                        </div>
                    </div>
                    <div class="card-body">

                        <?php if ($error && $errorAction === 'change_password'): ?>
                            <div class="form-error"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="change_password">
                            <div class="form-grid single">
                                <div class="form-field">
                                    <label>Current password</label>
                                    <input type="password" name="current_password" required>
                                </div>
                                <div class="form-field">
                                    <label>New password</label>
                                    <input type="password" name="new_password" minlength="8" required>
                                </div>
                                <div class="form-field">
                                    <label>Confirm new password</label>
                                    <input type="password" name="confirm_password" minlength="8" required>
                                </div>
                            </div>
                            <div class="form-actions" style="margin-top:14px;">
                                <button type="submit" class="button"><?= icon('check', 16) ?> Update password</button>
                            </div>
                        </form>
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
