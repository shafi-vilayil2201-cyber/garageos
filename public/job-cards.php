<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/VehicleIntake.php';
require_once __DIR__ . '/../app/Domain/JobCardStatus.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'job_cards.view');

$organizationId = $user['organization_id'];
$canManageJobCards = user_can($user, 'job_cards.manage');

if ($canManageJobCards) {
    $jobCardExtraFields = '
        <div class="form-grid single">
            <div class="form-field">
                <label for="jobcard-odometer_in">Odometer reading (km)</label>
                <input type="number" id="jobcard-odometer_in" name="odometer_in" min="0">
            </div>
            <div class="form-field">
                <label for="jobcard-customer_complaint">Customer complaint / request</label>
                <textarea id="jobcard-customer_complaint" name="customer_complaint" placeholder="e.g. Engine noise, brakes feel soft..."></textarea>
            </div>
        </div>
    ';
}

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

$columnIcons = [
    'received' => 'clipboard-list',
    'in_progress' => 'settings',
    'quality_check' => 'check-circle',
    'ready' => 'bell',
    'delivered' => 'car'
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
                    <p class="page-description">
                        Every vehicle currently moving through the workshop.
                        <?php if ($canManageJobCards && !empty($jobCards)): ?>
                            Drag a card forward to move it along — it can't go back a stage.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($canManageJobCards): ?>
                    <button type="button" class="button" onclick="openModal('jobcard-modal')"><?= icon('plus', 16) ?> New Job Card</button>
                <?php endif; ?>
            </div>

            <?php if (empty($jobCards)): ?>
                <div class="card">
                    <div class="empty-state">
                        <?= icon('job-card', 28) ?>
                        No job cards yet. Create the first one.
                    </div>
                </div>
            <?php else: ?>
                <div class="kanban" id="kanban-board">
                    <?php foreach ($columns as $statusKey => $statusLabel): ?>
                        <div class="kanban-column col-<?= $statusKey ?>" data-status="<?= $statusKey ?>">
                            <div class="kanban-column-title">
                                <span class="kanban-column-label"><?= icon($columnIcons[$statusKey], 15) ?><?= htmlspecialchars($statusLabel) ?></span>
                                <span id="kanban-count-<?= $statusKey ?>"><?= count($byStatus[$statusKey]) ?></span>
                            </div>

                            <div class="kanban-column-cards">
                                <?php foreach ($byStatus[$statusKey] as $jobCard): ?>
                                    <a
                                        href="/job-card.php?id=<?= (int) $jobCard['id'] ?>"
                                        class="kanban-card-link"
                                        data-job-card-id="<?= (int) $jobCard['id'] ?>"
                                        data-status="<?= htmlspecialchars($jobCard['status']) ?>"
                                        <?= $canManageJobCards && $jobCard['status'] !== 'delivered' ? 'draggable="true"' : '' ?>
                                    >
                                        <div class="kanban-card card-<?= htmlspecialchars($jobCard['status']) ?>">
                                            <div class="kanban-card-top">
                                                <span class="kanban-card-job-no"><?= htmlspecialchars($jobCard['job_no']) ?></span>
                                                <?php if ($jobCard['status'] === 'on_hold'): ?>
                                                    <span class="badge badge-on_hold"><?= icon('pause', 11) ?> On hold</span>
                                                <?php endif; ?>
                                            </div>
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
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

<?php if ($canManageJobCards): ?>

    <div class="modal-backdrop" id="jobcard-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('job-card', 16) ?></span>
                    New Job Card
                </div>
                <button type="button" class="modal-close" data-close-modal="jobcard-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <?= vehicle_intake_form('jobcard', '/job-card-new.php', $jobCardExtraFields, 'check', 'Create job card') ?>
            </div>
        </div>
    </div>

    <script src="/js/modal.js"></script>
    <script src="/js/vehicle-intake.js"></script>
    <script>initVehicleIntake('jobcard');</script>

<?php endif; ?>

<?php if ($canManageJobCards && !empty($jobCards)): ?>

    <script>
        window.JOB_CARD_STATUS_RANK = <?= json_encode(JOB_CARD_STATUS_RANK) ?>;
        window.JOB_CARD_CSRF = <?= json_encode(csrf_token()) ?>;
    </script>
    <script src="/js/job-cards-board.js"></script>

<?php endif; ?>

</body>
</html>
