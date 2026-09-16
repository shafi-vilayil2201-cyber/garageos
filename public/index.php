<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

if ($auth->user()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = $auth->attempt($email, $password);

    if ($user) {
        $auth->login($user);

        header('Location: /dashboard.php');
        exit;
    }

    $error = "Invalid email or password, or too many attempts — try again in a few minutes.";
}

// A real install serves exactly one workshop, so the login page can show
// its name right away. The shared demo database used in development has
// more than one, so it falls back to the generic vendor name.
$organizationCount = (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$brandName = 'GarageOS';

if ($organizationCount === 1) {
    $brandName = $pdo->query('SELECT name FROM organizations LIMIT 1')->fetchColumn();
}

// Swaps the login background between the day and night workshop photos.
// PHP's default timezone is already forced to Asia/Kolkata in
// config/database.php, so date('H') here is the workshop's actual
// local hour, not a UTC-shifted one. "Night" spans 6pm through 5:59am
// so a visitor at 1am still sees the night photo instead of it
// flipping back to day at midnight.
$hour = (int) date('H');
$isNight = $hour >= 18 || $hour < 6;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($brandName) ?> — Sign in</title>

    <link rel="stylesheet" href="/css/app.css">
</head>
<body>

<div class="auth-shell<?= $isNight ? ' auth-shell-night' : '' ?>">

    <div class="auth-card">

        <div class="auth-brand"><?= htmlspecialchars($brandName) ?></div>
        <p class="auth-subtitle">Sign in to manage your workshop.</p>

        <?php if ($error): ?>
            <div class="form-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">

            <?= csrf_field() ?>

            <div class="auth-field">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="auth-field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="auth-submit">Sign in</button>

        </form>

        <?php if ($organizationCount === 1): ?>
            <p class="auth-subtitle" style="margin:20px 0 0; text-align:center; font-size:11.5px;">Powered by GarageOS</p>
        <?php endif; ?>

    </div>

</div>

</body>
</html>
