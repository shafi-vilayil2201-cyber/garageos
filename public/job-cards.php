<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/VehicleIntake.php';
require_once __DIR__ . '/../app/View/JobCardIntakeExtras.php';
require_once __DIR__ . '/../app/Domain/JobCardStatus.php';
require_once __DIR__ . '/../app/Domain/Audit.php';
require_once __DIR__ . '/../app/Domain/Accessory.php';

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

// Quick edit — lets staff fix the odometer reading, customer complaint, or
// promised delivery time right from the board (the pencil icon on a card)
// instead of opening the full job card for a one-line correction. Mirrors
// job-card.php's own update_promised_at action/audit pattern, just with
// odometer_in and customer_complaint folded into the same save.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_edit') {

    require_permission($user, 'job_cards.manage');
    csrf_verify();

    $editJobCardId = (int) ($_POST['job_card_id'] ?? 0);

    $statement = $pdo->prepare("SELECT id, job_no FROM job_cards WHERE id = :id AND organization_id = :organization_id");
    $statement->execute(['id' => $editJobCardId, 'organization_id' => $organizationId]);
    $editingJobCard = $statement->fetch(PDO::FETCH_ASSOC);

    if ($editingJobCard) {
        $odometerIn = trim($_POST['odometer_in'] ?? '') !== '' ? (int) $_POST['odometer_in'] : null;
        $customerComplaint = trim($_POST['customer_complaint'] ?? '') ?: null;
        $promisedAt = trim($_POST['promised_at'] ?? '') ?: null;

        $statement = $pdo->prepare("
            UPDATE job_cards
            SET odometer_in = :odometer_in, customer_complaint = :customer_complaint, promised_at = :promised_at, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute([
            'odometer_in' => $odometerIn,
            'customer_complaint' => $customerComplaint,
            'promised_at' => $promisedAt,
            'id' => $editJobCardId,
            'organization_id' => $organizationId
        ]);

        log_audit_event($pdo, $user, 'update', 'job_card', $editJobCardId, "Updated details for {$editingJobCard['job_no']} via quick edit");
    }

    header('Location: /job-cards.php');
    exit;
}

if ($canManageJobCards) {
    $jobCardExtraFields = job_card_intake_extras();
}

// Amount and invoice presence are pulled in via pre-aggregated subqueries
// (rather than a direct JOIN to job_card_items/job_card_parts) so a job
// card with several line items doesn't fan out into duplicate rows here —
// same running-total formula as job-card.php's own $runningTotal.
$statement = $pdo->prepare("
    SELECT
        jc.id,
        jc.job_no,
        jc.status,
        jc.odometer_in,
        jc.customer_complaint,
        jc.promised_at,
        v.registration_no,
        v.make,
        v.model,
        c.name AS customer_name,
        COALESCE(items.total, 0) + COALESCE(parts.total, 0) AS amount,
        inv.id AS invoice_id
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    INNER JOIN customers c ON c.id = jc.customer_id
    LEFT JOIN (
        SELECT job_card_id, SUM(price - discount) AS total
        FROM job_card_items
        GROUP BY job_card_id
    ) items ON items.job_card_id = jc.id
    LEFT JOIN (
        SELECT job_card_id, SUM(quantity * unit_price + labour_charge * labour_quantity) AS total
        FROM job_card_parts
        GROUP BY job_card_id
    ) parts ON parts.job_card_id = jc.id
    LEFT JOIN invoices inv ON inv.job_card_id = jc.id
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

// A card's promised delivery time, expressed the way staff actually think
// about it — "due in 3hr" or "2d overdue" rather than a raw timestamp.
// Delivered/cancelled cards never show this (the promise has already been
// kept or no longer applies), and a card with no promised_at set shows
// nothing at all rather than a false "no rush" signal.
function job_card_due_badge(?string $promisedAt, string $status): ?array
{
    if (!$promisedAt || in_array($status, ['delivered', 'cancelled'], true)) {
        return null;
    }

    $diffSeconds = strtotime($promisedAt) - time();

    if ($diffSeconds >= 0) {
        $hours = (int) ceil($diffSeconds / 3600);

        if ($hours < 24) {
            return ['text' => "due {$hours}hr", 'overdue' => false];
        }

        $days = (int) ceil($diffSeconds / 86400);

        return ['text' => "due {$days}d", 'overdue' => false];
    }

    $overdueSeconds = -$diffSeconds;
    $hours = (int) floor($overdueSeconds / 3600);

    if ($hours < 24) {
        return ['text' => $hours < 1 ? 'overdue' : "{$hours}hr overdue", 'overdue' => true];
    }

    $days = (int) floor($overdueSeconds / 86400);

    return ['text' => "{$days}d overdue", 'overdue' => true];
}

$byStatus = array_fill_keys(array_keys($columns), []);

foreach ($jobCards as $jobCard) {
    $status = $jobCard['status'] === 'on_hold' ? 'in_progress' : $jobCard['status'];

    if (isset($byStatus[$status])) {
        $byStatus[$status][] = $jobCard;
    }
}

$columnTotals = [];
foreach ($byStatus as $statusKey => $cards) {
    $columnTotals[$statusKey] = array_sum(array_column($cards, 'amount'));
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
    <?= favicon_tag($user['organization_logo_url'] ?? null) ?>
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <div class="page-header page-header-tight">
                <h1 class="page-title">Job Cards</h1>

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
                <div class="search-row">
                    <input type="search" id="job-card-filter" placeholder="Search by job #, registration, or customer..." autocomplete="off">
                </div>
                <div class="kanban" id="kanban-board">
                    <?php foreach ($columns as $statusKey => $statusLabel): ?>
                        <div class="kanban-column col-<?= $statusKey ?>" data-status="<?= $statusKey ?>">
                            <div class="kanban-column-title">
                                <span class="kanban-column-label"><?= icon($columnIcons[$statusKey], 15) ?><?= htmlspecialchars($statusLabel) ?></span>
                                <span class="kanban-column-meta">
                                    <span id="kanban-count-<?= $statusKey ?>"><?= count($byStatus[$statusKey]) ?></span>
                                    <span class="kanban-column-total" id="kanban-total-<?= $statusKey ?>" data-amount="<?= (int) round($columnTotals[$statusKey]) ?>">₹<?= number_format($columnTotals[$statusKey], 0) ?></span>
                                </span>
                            </div>

                            <div class="kanban-column-cards">
                                <?php foreach ($byStatus[$statusKey] as $jobCard): ?>
                                    <div class="kanban-card-wrap">
                                        <a
                                            href="/job-card.php?id=<?= (int) $jobCard['id'] ?>"
                                            class="kanban-card-link"
                                            data-job-card-id="<?= (int) $jobCard['id'] ?>"
                                            data-status="<?= htmlspecialchars($jobCard['status']) ?>"
                                            data-amount="<?= (int) round($jobCard['amount']) ?>"
                                            <?= $canManageJobCards && $jobCard['status'] !== 'delivered' ? 'draggable="true"' : '' ?>
                                        >
                                            <?php $dueBadge = job_card_due_badge($jobCard['promised_at'], $jobCard['status']); ?>
                                            <div class="kanban-card card-<?= htmlspecialchars($jobCard['status']) ?>">
                                                <div class="kanban-card-top">
                                                    <span class="kanban-card-job-no"><?= htmlspecialchars($jobCard['job_no']) ?></span>
                                                    <div class="kanban-card-top-right">
                                                        <?php if ($jobCard['status'] === 'on_hold'): ?>
                                                            <span class="badge badge-on_hold"><?= icon('pause', 11) ?> On hold</span>
                                                        <?php elseif ($jobCard['amount'] > 0): ?>
                                                            <span class="kanban-card-amount">₹<?= number_format($jobCard['amount'], 0) ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($dueBadge): ?>
                                                            <div class="kanban-card-due <?= $dueBadge['overdue'] ? 'kanban-card-due-overdue' : '' ?>">
                                                                <?= htmlspecialchars($dueBadge['text']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="kanban-card-vehicle">
                                                    <?= htmlspecialchars($jobCard['registration_no']) ?>
                                                </div>
                                                <div class="kanban-card-customer">
                                                    <?= htmlspecialchars($jobCard['make'] . ' ' . $jobCard['model']) ?> · <?= htmlspecialchars($jobCard['customer_name']) ?>
                                                </div>
                                            </div>
                                        </a>

                                        <div class="kanban-card-footer card-<?= htmlspecialchars($jobCard['status']) ?>">
                                            <?php if ($canManageJobCards): ?>
                                                <button
                                                    type="button"
                                                    class="kanban-card-icon-button"
                                                    data-edit-trigger
                                                    data-job-card-id="<?= (int) $jobCard['id'] ?>"
                                                    data-job-no="<?= htmlspecialchars($jobCard['job_no']) ?>"
                                                    data-odometer-in="<?= htmlspecialchars((string) $jobCard['odometer_in']) ?>"
                                                    data-customer-complaint="<?= htmlspecialchars((string) $jobCard['customer_complaint']) ?>"
                                                    data-promised-at="<?= $jobCard['promised_at'] ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($jobCard['promised_at']))) : '' ?>"
                                                    title="Edit details"
                                                >
                                                    <?= icon('edit', 13) ?>
                                                </button>
                                            <?php endif; ?>

                                            <div class="kanban-card-print">
                                                <button type="button" class="kanban-card-icon-button" data-print-trigger aria-haspopup="true" aria-expanded="false" title="Print">
                                                    <?= icon('printer', 13) ?>
                                                </button>
                                                <div class="user-menu-dropdown kanban-card-print-menu" hidden>
                                                    <a href="/job-card-print.php?id=<?= (int) $jobCard['id'] ?>" target="_blank" class="user-menu-item">
                                                        <?= icon('job-card', 14) ?> Print Job Card
                                                    </a>
                                                    <?php if ($jobCard['invoice_id']): ?>
                                                        <a href="/invoice-print.php?id=<?= (int) $jobCard['invoice_id'] ?>" target="_blank" class="user-menu-item">
                                                            <?= icon('receipt', 14) ?> Print Invoice
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
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
        <div class="modal modal-wide">
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

    <div class="modal-backdrop" id="quick-edit-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('edit', 16) ?></span>
                    Edit <span id="quick-edit-job-no"></span>
                </div>
                <button type="button" class="modal-close" data-close-modal="quick-edit-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="/job-cards.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="quick_edit">
                    <input type="hidden" name="job_card_id" id="quick-edit-job-card-id">

                    <div class="form-grid single">
                        <div class="form-field">
                            <label for="quick-edit-odometer">Odometer reading (km)</label>
                            <input type="number" id="quick-edit-odometer" name="odometer_in" min="0">
                        </div>
                        <div class="form-field">
                            <label for="quick-edit-complaint">Customer complaint / request</label>
                            <textarea id="quick-edit-complaint" name="customer_complaint" placeholder="e.g. Engine noise, brakes feel soft..."></textarea>
                        </div>
                        <div class="form-field">
                            <label for="quick-edit-promised-at">Promised delivery</label>
                            <input type="datetime-local" id="quick-edit-promised-at" name="promised_at">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="/js/modal.js"></script>
    <script src="/js/vehicle-intake.js"></script>
    <script src="/js/customer-voice-list.js"></script>
    <script src="/js/damage-diagram.js"></script>
    <script>
        initVehicleIntake('jobcard');
        initCustomerVoiceList('jobcard');
        initDamageDiagram('jobcard');
    </script>

<?php endif; ?>

<?php if (!empty($jobCards)): ?>

    <script>
        // Every card's text (job #, registration, make/model, customer) is
        // already rendered on the page, so filtering is pure client-side
        // text matching — no server round trip needed.
        document.getElementById('job-card-filter').addEventListener('input', event =>
        {
            const query = event.target.value.trim().toLowerCase();

            document.querySelectorAll('.kanban-card-wrap').forEach(card =>
            {
                const matches = !query || card.textContent.toLowerCase().includes(query);
                card.hidden = !matches;
            });
        });
    </script>

<?php endif; ?>

<?php if (!empty($jobCards)): ?>

    <script>
        window.JOB_CARD_STATUS_RANK = <?= json_encode(JOB_CARD_STATUS_RANK) ?>;
        window.JOB_CARD_CSRF = <?= json_encode(csrf_token()) ?>;
    </script>
    <script src="/js/job-cards-board.js"></script>

<?php endif; ?>

</body>
</html>
