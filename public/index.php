<?php

require_once __DIR__ . '/../app/Auth/Auth.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

if ($auth->user()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = $auth->attempt($email, $password);

    if ($user) {
        $auth->login($user);

        header('Location: /dashboard.php');
        exit;
    }

    $error = "Invalid email or password.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Sign in</title>

    <link rel="stylesheet" href="/css/app.css">
</head>
<body>

<div class="auth-shell">

    <div class="auth-card">

        <div class="auth-brand">GarageOS</div>
        <p class="auth-subtitle">Sign in to manage your workshop.</p>

        <?php if ($error): ?>
            <div class="form-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">

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

    </div>

</div>

</body>
</html>
