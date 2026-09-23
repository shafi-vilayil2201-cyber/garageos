<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/JobCardStatus.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);
$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'job_cards.restore');

$organizationId = $user['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_job_card') {
    csrf_verify();

    $jobCardId = (int) ($_POST['job_card_id'] ?? 0);
    $statement = $pdo->prepare("
        SELECT id, job_no
        FROM job_cards
        WHERE id = :id
          AND organization_id = :organization_id
          AND deleted_at IS NOT NULL
    ");
    $statement->execute([
        'id' => $jobCardId,
        'organization_id' => $organizationId
    ]);
    $jobCard = $statement->fetch(PDO::FETCH_ASSOC);

    if ($jobCard) {
        restore_job_card($pdo, $jobCard, $user);
    }

    header('Location: /deleted-job-cards.php');
    exit;
}

$statement = $pdo->prepare("
    SELECT jc.id, jc.job_no, jc.status, jc.created_at, jc.deleted_at,
           c.id AS customer_id, c.name AS customer_name,
           v.registration_no, v.make, v.model,
           u.name AS deleted_by_name
    FROM job_cards jc
    INNER JOIN customers c ON c.id = jc.customer_id
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    LEFT JOIN users u ON u.id = jc.deleted_by
    WHERE jc.organization_id = :organization_id
      AND jc.deleted_at IS NOT NULL
    ORDER BY jc.deleted_at DESC
");
$statement->execute(['organization_id' => $organizationId]);
$deletedJobCards = $statement->fetchAll(PDO::FETCH_ASSOC);

$activeNav = 'deleted_job_cards';
$topbarTitle = 'Deleted Job Cards';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GarageOS — Deleted Job Cards</title>
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
                    <h1 class="page-title">Deleted Job Cards</h1>
                    <p class="page-description">Restore job cards that were removed from the active board.</p>
                </div>
                <a href="/job-cards.php" class="button secondary"><?= icon('arrow-left', 16) ?> Active Job Cards</a>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('box', 15) ?></span>
                        Deleted records
                    </div>
                    <span class="result-meta"><?= count($deletedJobCards) ?> total</span>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($deletedJobCards)): ?>
                        <div class="empty-state">
                            <?= icon('check-circle', 28) ?>
                            No deleted job cards.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Job Card</th><th>Customer</th><th>Vehicle</th><th>Deleted</th><th></th></tr>
                                <?php foreach ($deletedJobCards as $jobCard): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($jobCard['job_no']) ?></strong>
                                            <div class="result-meta"><?= htmlspecialchars(str_replace('_', ' ', $jobCard['status'])) ?></div>
                                        </td>
                                        <td><a href="/customer.php?id=<?= (int) $jobCard['customer_id'] ?>"><?= htmlspecialchars($jobCard['customer_name']) ?></a></td>
                                        <td>
                                            <?= htmlspecialchars($jobCard['registration_no']) ?>
                                            <div class="result-meta"><?= htmlspecialchars($jobCard['make'] . ' ' . $jobCard['model']) ?></div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(date('d M Y, h:i A', strtotime($jobCard['deleted_at']))) ?>
                                            <div class="result-meta"><?= htmlspecialchars($jobCard['deleted_by_name'] ?? 'Unknown') ?></div>
                                        </td>
                                        <td>
                                            <form method="POST" action="" onsubmit="return confirm('Restore job card <?= htmlspecialchars(addslashes($jobCard['job_no'])) ?>?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="restore_job_card">
                                                <input type="hidden" name="job_card_id" value="<?= (int) $jobCard['id'] ?>">
                                                <button type="submit" class="button secondary sm"><?= icon('check', 13) ?> Restore</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

</body>
</html>
