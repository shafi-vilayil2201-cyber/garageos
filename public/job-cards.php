<?php

require_once __DIR__ . '/../app/Auth/Auth.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

$organizationId = $user['organization_id'];

$statement = $pdo->prepare("
    SELECT
        jc.id,
        jc.job_no,
        jc.status,
        v.registration_no,
        v.make,
        v.model,
        c.name AS customer_name
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    INNER JOIN customers c ON c.id = jc.customer_id
    WHERE jc.organization_id = :organization_id
      AND jc.status NOT IN ('cancelled')
    ORDER BY jc.created_at DESC
");
$statement->execute(['organization_id' => $organizationId]);
$jobCards = $statement->fetchAll(PDO::FETCH_ASSOC);

$columns = [
    'received' => 'Received',
    'in_progress' => 'In Progress',
    'quality_check' => 'Quality Check',
    'ready' => 'Ready',
    'delivered' => 'Delivered'
];

$byStatus = array_fill_keys(array_keys($columns), []);

foreach ($jobCards as $jobCard) {
    $status = $jobCard['status'] === 'on_hold' ? 'in_progress' : $jobCard['status'];

    if (isset($byStatus[$status])) {
        $byStatus[$status][] = $jobCard;
    }
}

$activeNav = 'job_cards';
$topbarTitle = 'Job Cards';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Job Cards</title>

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
                    <h1 class="page-title">Job Cards</h1>
                    <p class="page-description">Every vehicle currently moving through the workshop.</p>
                </div>

                <a href="/job-card-new.php" class="button">+ New Job Card</a>
            </div>

            <?php if (empty($jobCards)): ?>
                <div class="card">
                    <div class="empty-state">No job cards yet. Create the first one.</div>
                </div>
            <?php else: ?>
                <div class="kanban">
                    <?php foreach ($columns as $statusKey => $statusLabel): ?>
                        <div class="kanban-column">
                            <div class="kanban-column-title">
                                <span><?= htmlspecialchars($statusLabel) ?></span>
                                <span><?= count($byStatus[$statusKey]) ?></span>
                            </div>

                            <?php foreach ($byStatus[$statusKey] as $jobCard): ?>
                                <a href="/job-card.php?id=<?= (int) $jobCard['id'] ?>">
                                    <div class="kanban-card">
                                        <div class="kanban-card-job-no"><?= htmlspecialchars($jobCard['job_no']) ?></div>
                                        <div class="kanban-card-vehicle">
                                            <?= htmlspecialchars($jobCard['registration_no']) ?>
                                        </div>
                                        <div class="kanban-card-customer">
                                            <?= htmlspecialchars($jobCard['make'] . ' ' . $jobCard['model']) ?> · <?= htmlspecialchars($jobCard['customer_name']) ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

</body>
</html>
