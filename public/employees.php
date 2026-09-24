<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/Pagination.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

// Same audience as users.php (Owner-only) rather than attendance.manage
// (Owner + Manager) — this page surfaces salary, which today only
// users.manage can see. Gating it any wider would quietly hand Managers
// compensation visibility they don't have anywhere else in the app.
require_permission($user, 'users.manage');

$organizationId = $user['organization_id'];

$statement = $pdo->prepare("SELECT COUNT(*) FROM users WHERE organization_id = :organization_id");
$statement->execute(['organization_id' => $organizationId]);
$totalEmployees = (int) $statement->fetchColumn();
$page = paginate_page($totalEmployees);

$statement = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.phone, u.status, u.designation, u.joined_at,
        u.salary_type, u.salary_amount,
        STRING_AGG(r.name, ', ' ORDER BY r.name) AS role_names
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    WHERE u.organization_id = :organization_id
    GROUP BY u.id, u.name, u.email, u.phone, u.status, u.designation, u.joined_at, u.salary_type, u.salary_amount
    ORDER BY u.status = 'active' DESC, u.name
    LIMIT :limit OFFSET :offset
");
$statement->bindValue('organization_id', $organizationId);
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$employees = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'employees';
$topbarTitle = 'Employees';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Employees</title>

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
                    <h1 class="page-title">Employees</h1>
                    <p class="page-description">Every staff member's profile, in one place — attendance, performance, payroll history, and recent activity.</p>
                </div>
            </div>

            <?php if (empty($employees)): ?>
                <div class="card">
                    <div class="empty-state">
                        <?= icon('team', 28) ?>
                        No staff added yet — add one from <a href="/users.php">Users</a>.
                    </div>
                </div>
            <?php else: ?>
                <div class="settings-grid">
                    <?php foreach ($employees as $employee): ?>
                        <a href="/employee.php?id=<?= (int) $employee['id'] ?>" class="card card-link">
                            <div class="card-body">
                                <div class="employee-card-top">
                                    <div class="employee-card-name">
                                        <?= htmlspecialchars($employee['name']) ?>
                                        <?php if ($employee['status'] !== 'active'): ?>
                                            <span class="badge badge-cancelled">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="icon-badge"><?= icon('person', 15) ?></span>
                                </div>
                                <div class="result-meta">
                                    <?= htmlspecialchars($employee['designation'] ?: ($employee['role_names'] ?: 'No role assigned')) ?>
                                </div>
                                <div class="employee-card-meta">
                                    <?php if ($employee['joined_at']): ?>
                                        <span><?= icon('calendar', 13) ?> Joined <?= htmlspecialchars(date('d M Y', strtotime($employee['joined_at']))) ?></span>
                                    <?php endif; ?>
                                    <?php if ($employee['salary_type']): ?>
                                        <span><?= icon('wallet', 13) ?> &#8377;<?= number_format((float) $employee['salary_amount'], 0) ?> <?= $employee['salary_type'] === 'monthly' ? '/month' : '/day' ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?= render_pagination($page, $totalEmployees) ?>
            <?php endif; ?>

        </section>

    </main>

</div>

</body>
</html>
