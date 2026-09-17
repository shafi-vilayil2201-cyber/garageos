<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/View/Pagination.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'audit.view');

$organizationId = $user['organization_id'];

$filterUserId = (int) ($_GET['user_id'] ?? 0);

$whereClause = "WHERE al.organization_id = :organization_id";
$params = ['organization_id' => $organizationId];

if ($filterUserId) {
    $whereClause .= " AND al.user_id = :user_id";
    $params['user_id'] = $filterUserId;
}

$statement = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al $whereClause");
$statement->execute($params);
$totalLogs = (int) $statement->fetchColumn();
$page = paginate_page($totalLogs);

$statement = $pdo->prepare("
    SELECT al.id, al.action, al.entity_type, al.entity_id, al.description, al.created_at,
           u.name AS user_name
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    $whereClause
    ORDER BY al.created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $statement->bindValue($key, $value);
}
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$logs = $statement->fetchAll(PDO::FETCH_ASSOC);

// For the filter dropdown — every staff member who has ever appeared
// in this organization's log, not the full staff list, so someone who
// never did anything logged doesn't show up as a dead-end filter.
$statement = $pdo->prepare("
    SELECT DISTINCT u.id, u.name
    FROM audit_logs al
    INNER JOIN users u ON u.id = al.user_id
    WHERE al.organization_id = :organization_id
    ORDER BY u.name
");
$statement->execute(['organization_id' => $organizationId]);
$staffInLog = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = '';
$topbarTitle = 'Audit Logs';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Audit Logs</title>

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
                    <h1 class="page-title">Audit Logs</h1>
                    <p class="page-description">Who did what, across the whole workshop.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('clipboard-list', 15) ?></span>
                        All activity
                    </div>
                    <form method="GET" action="">
                        <select name="user_id" onchange="this.form.submit()">
                            <option value="">All staff</option>
                            <?php foreach ($staffInLog as $staff): ?>
                                <option value="<?= (int) $staff['id'] ?>" <?= $filterUserId === (int) $staff['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($staff['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($logs)): ?>
                        <div class="empty-state">
                            <?= icon('clipboard-list', 28) ?>
                            No activity recorded yet.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Date</th><th>Staff</th><th>Action</th><th>Description</th></tr>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="num" style="white-space:nowrap;"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($log['created_at']))) ?></td>
                                        <td><?= htmlspecialchars($log['user_name'] ?? 'Unknown') ?></td>
                                        <td>
                                            <span class="badge badge-<?= htmlspecialchars($log['action']) ?>">
                                                <?= htmlspecialchars(ucfirst($log['action'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($log['description']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <?= render_pagination($page, $totalLogs, PAGINATION_PER_PAGE, $filterUserId ? ['user_id' => $filterUserId] : []) ?>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

</body>
</html>
