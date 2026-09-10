<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'users.manage');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'users.manage');

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleId = (int) ($_POST['role_id'] ?? 0);

    if ($name === '' || $email === '' || strlen($password) < 8 || !$roleId) {
        $error = 'Name, email, a role, and a password of at least 8 characters are required.';
    } else {

        $statement = $pdo->prepare("SELECT 1 FROM roles WHERE id = :id AND organization_id = :organization_id");
        $statement->execute(['id' => $roleId, 'organization_id' => $organizationId]);

        if (!$statement->fetch()) {
            $error = 'That role does not belong to this organization.';
        } else {

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare("
                    INSERT INTO users (organization_id, branch_id, name, email, password_hash, status)
                    VALUES (:organization_id, :branch_id, :name, :email, :password_hash, 'active')
                    RETURNING id
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT)
                ]);
                $newUserId = $statement->fetchColumn();

                $statement = $pdo->prepare("
                    INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)
                ");
                $statement->execute(['user_id' => $newUserId, 'role_id' => $roleId]);

                $pdo->commit();

                header('Location: /users.php');
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = str_contains($e->getMessage(), 'uq_users_organization_email')
                    ? 'A user with that email already exists in this organization.'
                    : $e->getMessage();
            }
        }
    }
}

$statement = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.status, STRING_AGG(r.name, ', ' ORDER BY r.name) AS role_names
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    WHERE u.organization_id = :organization_id
    GROUP BY u.id, u.name, u.email, u.status
    ORDER BY u.name
");
$statement->execute(['organization_id' => $organizationId]);
$users = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("SELECT id, name, description FROM roles WHERE organization_id = :organization_id ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$roles = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'users';
$topbarTitle = 'Users';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Users</title>

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
                    <h1 class="page-title">Users</h1>
                    <p class="page-description">Staff accounts and what they're allowed to do.</p>
                </div>

                <?php if (!empty($roles)): ?>
                    <button type="button" class="button" onclick="openModal('user-modal')"><?= icon('plus', 16) ?> Add User</button>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('team', 15) ?></span>
                        All users
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-wrap">
                        <table class="data-table">
                            <tr><th>Name</th><th>Email</th><th>Role</th></tr>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?= htmlspecialchars($u['name']) ?></td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td>
                                        <?php if ($u['role_names']): ?>
                                            <span class="badge badge-ready"><?= htmlspecialchars($u['role_names']) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-on_hold"><?= icon('alert-triangle', 12) ?> No role assigned</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>

            <?php if (empty($roles)): ?>
                <div class="card" style="margin-top:20px;">
                    <div class="empty-state">
                        <?= icon('team', 28) ?>
                        No roles exist for this organization yet — create one before adding staff.
                    </div>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

<?php if (!empty($roles)): ?>

    <div class="modal-backdrop<?= $error ? ' open' : '' ?>" id="user-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('team', 16) ?></span>
                    Add User
                </div>
                <button type="button" class="modal-close" data-close-modal="user-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="">

                    <?= csrf_field() ?>

                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Full name</label>
                            <input type="text" name="name" required>
                        </div>
                        <div class="form-field">
                            <label>Email</label>
                            <input type="email" name="email" required>
                        </div>
                        <div class="form-field">
                            <label>Temporary password</label>
                            <input type="password" name="password" minlength="8" required>
                        </div>
                        <div class="form-field">
                            <label>Role</label>
                            <select name="role_id" required>
                                <option value="">Select role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Create user</button>
                    </div>

                </form>

            </div>
        </div>
    </div>

    <script src="/js/modal.js"></script>

<?php endif; ?>

</body>
</html>
