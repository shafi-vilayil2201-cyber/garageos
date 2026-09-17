<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/Pagination.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

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
$errorAction = null;
$editingUserId = (int) ($_GET['edit'] ?? 0);

// Salary is optional per user (e.g. an Owner-only account may not need it
// tracked), but if either field is filled in, both must be valid together —
// a salary type with no amount (or vice versa) is a half-filled mistake.
function read_salary_fields(array $post): array
{
    $designation = trim($post['designation'] ?? '');
    $salaryType = $post['salary_type'] ?? '';
    $salaryAmountRaw = trim($post['salary_amount'] ?? '');
    $joinedAt = trim($post['joined_at'] ?? '');

    $salaryType = in_array($salaryType, ['monthly', 'daily_wage'], true) ? $salaryType : null;
    $joinedAt = $joinedAt !== '' ? $joinedAt : null;

    if ($salaryType === null && $salaryAmountRaw === '') {
        return [$designation ?: null, null, null, $joinedAt, null];
    }

    if ($salaryType === null || $salaryAmountRaw === '' || !is_numeric($salaryAmountRaw) || (float) $salaryAmountRaw <= 0) {
        return [null, null, null, null, 'Salary type and a positive salary amount must be set together.'];
    }

    return [$designation ?: null, $salaryType, (float) $salaryAmountRaw, $joinedAt, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'users.manage');

    $action = $_POST['action'] ?? 'create';

    if ($action === 'update') {

        $editingUserId = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleId = (int) ($_POST['role_id'] ?? 0);
        [$designation, $salaryType, $salaryAmount, $joinedAt, $salaryError] = read_salary_fields($_POST);

        if ($name === '' || $email === '' || !$roleId || ($password !== '' && strlen($password) < 8)) {
            $error = 'Name, email and a role are required. If you set a new password, it must be at least 8 characters.';
            $errorAction = 'update';
        } elseif ($salaryError) {
            $error = $salaryError;
            $errorAction = 'update';
        } else {

            $statement = $pdo->prepare("SELECT 1 FROM users WHERE id = :id AND organization_id = :organization_id");
            $statement->execute(['id' => $editingUserId, 'organization_id' => $organizationId]);

            $statement2 = $pdo->prepare("SELECT 1 FROM roles WHERE id = :id AND organization_id = :organization_id");
            $statement2->execute(['id' => $roleId, 'organization_id' => $organizationId]);

            $statement3 = $pdo->prepare("SELECT r.id, r.name FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :user_id LIMIT 1");
            $statement3->execute(['user_id' => $editingUserId]);
            $previousRole = $statement3->fetch(PDO::FETCH_ASSOC);

            if (!$statement->fetch()) {
                $error = 'That user does not belong to this organization.';
                $errorAction = 'update';
            } elseif (!$statement2->fetch()) {
                $error = 'That role does not belong to this organization.';
                $errorAction = 'update';
            } else {

                $pdo->beginTransaction();

                try {
                    if ($password !== '') {
                        $statement = $pdo->prepare("
                            UPDATE users
                            SET name = :name, email = :email, password_hash = :password_hash,
                                designation = :designation, salary_type = :salary_type,
                                salary_amount = :salary_amount, joined_at = :joined_at,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");
                        $statement->execute([
                            'name' => $name,
                            'email' => $email,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'designation' => $designation,
                            'salary_type' => $salaryType,
                            'salary_amount' => $salaryAmount,
                            'joined_at' => $joinedAt,
                            'id' => $editingUserId
                        ]);
                    } else {
                        $statement = $pdo->prepare("
                            UPDATE users
                            SET name = :name, email = :email,
                                designation = :designation, salary_type = :salary_type,
                                salary_amount = :salary_amount, joined_at = :joined_at,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");
                        $statement->execute([
                            'name' => $name,
                            'email' => $email,
                            'designation' => $designation,
                            'salary_type' => $salaryType,
                            'salary_amount' => $salaryAmount,
                            'joined_at' => $joinedAt,
                            'id' => $editingUserId
                        ]);
                    }

                    $statement = $pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id");
                    $statement->execute(['user_id' => $editingUserId]);

                    $statement = $pdo->prepare("
                        INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)
                        ON CONFLICT DO NOTHING
                    ");
                    $statement->execute(['user_id' => $editingUserId, 'role_id' => $roleId]);

                    $roleChanged = !$previousRole || (int) $previousRole['id'] !== $roleId;
                    $description = "Updated staff member $name";

                    if ($roleChanged) {
                        $statement = $pdo->prepare("SELECT name FROM roles WHERE id = :id");
                        $statement->execute(['id' => $roleId]);
                        $newRoleName = $statement->fetchColumn();
                        $description .= $previousRole
                            ? ", changed role from {$previousRole['name']} to $newRoleName"
                            : ", assigned role $newRoleName";
                    }

                    log_audit_event($pdo, $user, 'update', 'user', $editingUserId, $description);

                    $pdo->commit();

                    header('Location: /users.php');
                    exit;

                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $error = str_contains($e->getMessage(), 'uq_users_organization_email')
                        ? 'A user with that email already exists in this organization.'
                        : $e->getMessage();
                    $errorAction = 'update';
                }
            }
        }

    } else {

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleId = (int) ($_POST['role_id'] ?? 0);
        [$designation, $salaryType, $salaryAmount, $joinedAt, $salaryError] = read_salary_fields($_POST);

        if ($name === '' || $email === '' || strlen($password) < 8 || !$roleId) {
            $error = 'Name, email, a role, and a password of at least 8 characters are required.';
            $errorAction = 'create';
        } elseif ($salaryError) {
            $error = $salaryError;
            $errorAction = 'create';
        } else {

            $statement = $pdo->prepare("SELECT 1 FROM roles WHERE id = :id AND organization_id = :organization_id");
            $statement->execute(['id' => $roleId, 'organization_id' => $organizationId]);

            if (!$statement->fetch()) {
                $error = 'That role does not belong to this organization.';
                $errorAction = 'create';
            } else {

                $pdo->beginTransaction();

                try {
                    $statement = $pdo->prepare("
                        INSERT INTO users (organization_id, branch_id, name, email, password_hash, status, designation, salary_type, salary_amount, joined_at)
                        VALUES (:organization_id, :branch_id, :name, :email, :password_hash, 'active', :designation, :salary_type, :salary_amount, :joined_at)
                        RETURNING id
                    ");
                    $statement->execute([
                        'organization_id' => $organizationId,
                        'branch_id' => $branchId,
                        'name' => $name,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'designation' => $designation,
                        'salary_type' => $salaryType,
                        'salary_amount' => $salaryAmount,
                        'joined_at' => $joinedAt
                    ]);
                    $newUserId = $statement->fetchColumn();

                    $statement = $pdo->prepare("
                        INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)
                    ");
                    $statement->execute(['user_id' => $newUserId, 'role_id' => $roleId]);

                    log_audit_event($pdo, $user, 'create', 'user', (int) $newUserId, "Created staff member $name");

                    $pdo->commit();

                    header('Location: /users.php');
                    exit;

                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $error = str_contains($e->getMessage(), 'uq_users_organization_email')
                        ? 'A user with that email already exists in this organization.'
                        : $e->getMessage();
                    $errorAction = 'create';
                }
            }
        }
    }
}

$statement = $pdo->prepare("SELECT COUNT(*) FROM users WHERE organization_id = :organization_id");
$statement->execute(['organization_id' => $organizationId]);
$totalUsers = (int) $statement->fetchColumn();
$page = paginate_page($totalUsers);

$statement = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.status, u.designation, u.salary_type, u.salary_amount,
        STRING_AGG(r.name, ', ' ORDER BY r.name) AS role_names
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    WHERE u.organization_id = :organization_id
    GROUP BY u.id, u.name, u.email, u.status, u.designation, u.salary_type, u.salary_amount
    ORDER BY u.name
    LIMIT :limit OFFSET :offset
");
$statement->bindValue('organization_id', $organizationId);
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$users = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("SELECT id, name, description FROM roles WHERE organization_id = :organization_id ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$roles = $statement->fetchAll(PDO::FETCH_ASSOC);

$editingUser = null;

if ($editingUserId) {
    $statement = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.designation, u.salary_type, u.salary_amount, u.joined_at,
            MIN(ur.role_id) AS role_id
        FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        WHERE u.id = :id AND u.organization_id = :organization_id
        GROUP BY u.id, u.name, u.email, u.designation, u.salary_type, u.salary_amount, u.joined_at
    ");
    $statement->execute(['id' => $editingUserId, 'organization_id' => $organizationId]);
    $editingUser = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$editingUser) {
        header('Location: /users.php');
        exit;
    }
}

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
                    <?php if (empty($users)): ?>
                        <div class="empty-state">
                            <?= icon('team', 28) ?>
                            No users yet.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Name</th><th>Email</th><th>Role</th><th>Salary</th><th></th></tr>
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
                                        <td>
                                            <?php if ($u['salary_type']): ?>
                                                &#8377;<?= number_format((float) $u['salary_amount'], 2) ?>
                                                <span class="muted"><?= $u['salary_type'] === 'monthly' ? '/ month' : '/ day' ?></span>
                                            <?php else: ?>
                                                <span class="muted">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?edit=<?= (int) $u['id'] ?>" class="link-action"><?= icon('settings', 14) ?> Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <?= render_pagination($page, $totalUsers) ?>
                    <?php endif; ?>
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

    <div class="modal-backdrop<?= $errorAction === 'create' ? ' open' : '' ?>" id="user-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('team', 16) ?></span>
                    Add User
                </div>
                <button type="button" class="modal-close" data-close-modal="user-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'create' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="">

                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">

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
                        <div class="form-field">
                            <label>Designation (optional)</label>
                            <input type="text" name="designation" placeholder="e.g. Senior Technician">
                        </div>
                        <div class="form-field">
                            <label>Salary type (optional)</label>
                            <select name="salary_type">
                                <option value="">Not tracked</option>
                                <option value="monthly">Monthly (fixed)</option>
                                <option value="daily_wage">Daily wage</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Salary amount</label>
                            <input type="number" name="salary_amount" min="0" step="0.01" placeholder="Monthly salary, or per-day rate">
                        </div>
                        <div class="form-field">
                            <label>Joined on (optional)</label>
                            <input type="date" name="joined_at">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Create user</button>
                    </div>

                </form>

            </div>
        </div>
    </div>

    <div class="modal-backdrop<?= $editingUser ? ' open' : '' ?>" id="edit-user-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('team', 16) ?></span>
                    Edit User
                </div>
                <button type="button" class="modal-close" data-close-modal="edit-user-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'update' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($editingUser): ?>
                    <form method="POST" action="">

                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="user_id" value="<?= (int) $editingUser['id'] ?>">

                        <div class="form-grid single">
                            <div class="form-field">
                                <label>Full name</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($editingUser['name']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Email</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($editingUser['email']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>New password (optional)</label>
                                <input type="password" name="password" minlength="8" placeholder="Leave blank to keep current password">
                            </div>
                            <div class="form-field">
                                <label>Role</label>
                                <select name="role_id" required>
                                    <option value="">Select role</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= (int) $role['id'] ?>" <?= (int) $editingUser['role_id'] === (int) $role['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($role['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Designation (optional)</label>
                                <input type="text" name="designation" value="<?= htmlspecialchars($editingUser['designation'] ?? '') ?>" placeholder="e.g. Senior Technician">
                            </div>
                            <div class="form-field">
                                <label>Salary type (optional)</label>
                                <select name="salary_type">
                                    <option value="">Not tracked</option>
                                    <option value="monthly" <?= $editingUser['salary_type'] === 'monthly' ? 'selected' : '' ?>>Monthly (fixed)</option>
                                    <option value="daily_wage" <?= $editingUser['salary_type'] === 'daily_wage' ? 'selected' : '' ?>>Daily wage</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Salary amount</label>
                                <input type="number" name="salary_amount" min="0" step="0.01" value="<?= htmlspecialchars($editingUser['salary_amount'] ?? '') ?>" placeholder="Monthly salary, or per-day rate">
                            </div>
                            <div class="form-field">
                                <label>Joined on (optional)</label>
                                <input type="date" name="joined_at" value="<?= htmlspecialchars($editingUser['joined_at'] ?? '') ?>">
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

</body>
</html>
