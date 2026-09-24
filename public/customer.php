<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/JobCardStatus.php';
require_once __DIR__ . '/../app/Support/Flash.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'customers.view');

$organizationId = $user['organization_id'];
$canManageJobCards = user_can($user, 'job_cards.manage');
$canRestoreJobCards = user_can($user, 'job_cards.restore');
$customerId = (int) ($_GET['id'] ?? 0);

$statement = $pdo->prepare("
    SELECT id, name, code, phone, email, address, gstin, state, created_at
    FROM customers
    WHERE id = :id AND organization_id = :organization_id
");
$statement->execute(['id' => $customerId, 'organization_id' => $organizationId]);
$customer = $statement->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header('Location: /customers.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_job_card') {
    require_permission($user, 'job_cards.restore');
    csrf_verify();

    $restoreJobCardId = (int) ($_POST['job_card_id'] ?? 0);
    $statement = $pdo->prepare("
        SELECT id, job_no, customer_id
        FROM job_cards
        WHERE id = :id
          AND customer_id = :customer_id
          AND organization_id = :organization_id
          AND deleted_at IS NOT NULL
    ");
    $statement->execute([
        'id' => $restoreJobCardId,
        'customer_id' => $customerId,
        'organization_id' => $organizationId
    ]);
    $deletedJobCard = $statement->fetch(PDO::FETCH_ASSOC);

    if ($deletedJobCard) {
        restore_job_card($pdo, $deletedJobCard, $user);
        flash_set("{$deletedJobCard['job_no']} restored to the active board.");
    }

    header('Location: /customer.php?id=' . $customerId);
    exit;
}

$statement = $pdo->prepare("
    SELECT id, registration_no, make, model, year, color, fuel_type, odometer_km, status
    FROM vehicles
    WHERE customer_id = :customer_id AND organization_id = :organization_id
    ORDER BY created_at DESC
    LIMIT 50
");
$statement->execute(['customer_id' => $customerId, 'organization_id' => $organizationId]);
$vehicles = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("
    SELECT jc.id, jc.job_no, jc.status, jc.promised_at, jc.closed_at, jc.created_at,
           v.registration_no, v.make, v.model
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    WHERE jc.customer_id = :customer_id
      AND jc.organization_id = :organization_id
      AND jc.deleted_at IS NULL
    ORDER BY jc.created_at DESC
    LIMIT 50
");
$statement->execute(['customer_id' => $customerId, 'organization_id' => $organizationId]);
$jobCards = $statement->fetchAll(PDO::FETCH_ASSOC);

$deletedJobCards = [];
if ($canRestoreJobCards) {
    $statement = $pdo->prepare("
        SELECT jc.id, jc.job_no, jc.status, jc.created_at, jc.deleted_at,
               v.registration_no, v.make, v.model, u.name AS deleted_by_name
        FROM job_cards jc
        INNER JOIN vehicles v ON v.id = jc.vehicle_id
        LEFT JOIN users u ON u.id = jc.deleted_by
        WHERE jc.customer_id = :customer_id
          AND jc.organization_id = :organization_id
          AND jc.deleted_at IS NOT NULL
        ORDER BY jc.deleted_at DESC
        LIMIT 50
    ");
    $statement->execute(['customer_id' => $customerId, 'organization_id' => $organizationId]);
    $deletedJobCards = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$statement = $pdo->prepare("
    SELECT i.id, i.invoice_no, i.status, i.total, i.amount_paid, i.created_at,
           jc.job_no
    FROM invoices i
    LEFT JOIN job_cards jc ON jc.id = i.job_card_id
    WHERE i.customer_id = :customer_id AND i.organization_id = :organization_id
    ORDER BY i.created_at DESC
    LIMIT 50
");
$statement->execute(['customer_id' => $customerId, 'organization_id' => $organizationId]);
$invoices = $statement->fetchAll(PDO::FETCH_ASSOC);

$statusIcons = [
    'received' => 'clipboard-list',
    'in_progress' => 'settings',
    'quality_check' => 'check-circle',
    'ready' => 'bell',
    'delivered' => 'car',
    'on_hold' => 'pause',
    'cancelled' => 'x'
];

$invoiceStatusIcons = [
    'unpaid' => 'alert-triangle',
    'partial' => 'wallet',
    'paid' => 'check-circle',
    'void' => 'x'
];

$activeNav = 'customers';
$topbarTitle = $customer['name'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — <?= htmlspecialchars($customer['name']) ?></title>

    <link rel="stylesheet" href="/css/app.css">
    <?= favicon_tag($user['organization_logo_url'] ?? null) ?>
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <a href="/customers.php" class="link-action" style="margin-bottom:16px;"><?= icon('arrow-left', 14) ?> Back to Customers</a>

            <div class="page-header">
                <div>
                    <h1 class="page-title"><?= htmlspecialchars($customer['name']) ?></h1>
                    <p class="page-description">
                        <?= htmlspecialchars($customer['code']) ?> · <?= htmlspecialchars($customer['phone']) ?><?= $customer['email'] ? ' · ' . htmlspecialchars($customer['email']) : '' ?>
                    </p>
                </div>

                <div style="display:flex; gap:10px; align-items:center;">
                    <a href="/customers.php?edit=<?= (int) $customer['id'] ?>" class="button secondary"><?= icon('settings', 16) ?> Edit</a>
                    <?php if ($canManageJobCards): ?>
                        <a href="/job-card-new.php?customer_id=<?= (int) $customer['id'] ?>" class="button"><?= icon('plus', 16) ?> New Job Card</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="content-grid content-grid-aside">

                <div class="stack">

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('job-card', 15) ?></span>
                                Job Cards
                            </div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <?php if (empty($jobCards)): ?>
                                <div class="empty-state">
                                    <?= icon('job-card', 26) ?>
                                    No job cards yet.
                                </div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <tr><th>Job Card</th><th>Vehicle</th><th>Status</th><th>Date</th><th></th></tr>
                                        <?php foreach ($jobCards as $jobCard): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($jobCard['job_no']) ?></strong></td>
                                                <td>
                                                    <?= htmlspecialchars($jobCard['registration_no']) ?>
                                                    <div class="result-meta"><?= htmlspecialchars($jobCard['make'] . ' ' . $jobCard['model']) ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= htmlspecialchars($jobCard['status']) ?>">
                                                        <?= icon($statusIcons[$jobCard['status']] ?? 'job-card', 12) ?>
                                                        <?= htmlspecialchars(str_replace('_', ' ', $jobCard['status'])) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars(date('d M Y', strtotime($jobCard['created_at']))) ?></td>
                                                <td><a href="/job-card.php?id=<?= (int) $jobCard['id'] ?>" class="button secondary sm">View</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($canRestoreJobCards && !empty($deletedJobCards)): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('box', 15) ?></span>
                                    Deleted Job Cards
                                </div>
                            </div>
                            <div class="card-body" style="padding:0;">
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <tr><th>Job Card</th><th>Vehicle</th><th>Deleted</th><th></th></tr>
                                        <?php foreach ($deletedJobCards as $jobCard): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($jobCard['job_no']) ?></strong>
                                                    <div class="result-meta"><?= htmlspecialchars(str_replace('_', ' ', $jobCard['status'])) ?></div>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($jobCard['registration_no']) ?>
                                                    <div class="result-meta"><?= htmlspecialchars($jobCard['make'] . ' ' . $jobCard['model']) ?></div>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars(date('d M Y, h:i A', strtotime($jobCard['deleted_at']))) ?>
                                                    <div class="result-meta"><?= htmlspecialchars($jobCard['deleted_by_name'] ?? 'Unknown') ?></div>
                                                </td>
                                                <td>
                                                    <form method="POST" action=""
                                                          data-confirm="Restore job card <?= htmlspecialchars($jobCard['job_no'], ENT_QUOTES) ?> back onto the active board?"
                                                          data-confirm-title="Restore job card?"
                                                          data-confirm-label="Restore"
                                                          data-confirm-variant="neutral">
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
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                                Invoices
                            </div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <?php if (empty($invoices)): ?>
                                <div class="empty-state">
                                    <?= icon('receipt', 26) ?>
                                    No invoices yet.
                                </div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <tr><th>Invoice</th><th>Job Card</th><th>Status</th><th>Total</th><th>Due</th><th></th></tr>
                                        <?php foreach ($invoices as $invoice): ?>
                                            <?php $due = (float) $invoice['total'] - (float) $invoice['amount_paid']; ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($invoice['invoice_no']) ?></strong></td>
                                                <td><?= $invoice['job_no'] ? htmlspecialchars($invoice['job_no']) : '—' ?></td>
                                                <td>
                                                    <span class="badge badge-<?= htmlspecialchars($invoice['status']) ?>">
                                                        <?= icon($invoiceStatusIcons[$invoice['status']] ?? 'receipt', 12) ?>
                                                        <?= htmlspecialchars($invoice['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="num">₹<?= number_format((float) $invoice['total'], 2) ?></td>
                                                <td class="num">₹<?= number_format($due, 2) ?></td>
                                                <td><a href="/invoice.php?id=<?= (int) $invoice['id'] ?>" class="button secondary sm">View</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="stack">

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('person', 15) ?></span>
                                Contact
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="result-meta" style="margin-bottom:8px;">Phone</p>
                            <p style="margin-bottom:14px;"><?= htmlspecialchars($customer['phone']) ?></p>

                            <?php if ($customer['email']): ?>
                                <p class="result-meta" style="margin-bottom:8px;">Email</p>
                                <p style="margin-bottom:14px;"><?= htmlspecialchars($customer['email']) ?></p>
                            <?php endif; ?>

                            <?php if ($customer['address']): ?>
                                <p class="result-meta" style="margin-bottom:8px;">Address</p>
                                <p style="margin-bottom:14px;"><?= nl2br(htmlspecialchars($customer['address'])) ?></p>
                            <?php endif; ?>

                            <?php if ($customer['gstin']): ?>
                                <p class="result-meta" style="margin-bottom:8px;">GSTIN</p>
                                <p style="margin-bottom:14px;"><?= htmlspecialchars($customer['gstin']) ?></p>
                            <?php endif; ?>

                            <?php if ($customer['state']): ?>
                                <p class="result-meta" style="margin-bottom:8px;">State</p>
                                <p style="margin-bottom:14px;"><?= htmlspecialchars($customer['state']) ?></p>
                            <?php endif; ?>

                            <p class="result-meta" style="margin-bottom:8px;">Customer since</p>
                            <p><?= htmlspecialchars(date('d M Y', strtotime($customer['created_at']))) ?></p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('car', 15) ?></span>
                                Vehicles
                            </div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <?php if (empty($vehicles)): ?>
                                <div class="empty-state">
                                    <?= icon('car', 26) ?>
                                    No vehicles on file.
                                    <a href="/vehicles.php?customer_id=<?= (int) $customer['id'] ?>" class="link-action"><?= icon('plus', 14) ?> Add a vehicle</a>
                                </div>
                            <?php else: ?>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <div style="padding:12px 20px; border-bottom:1px solid var(--border);">
                                        <strong><?= htmlspecialchars($vehicle['registration_no']) ?></strong>
                                        <div class="result-meta">
                                            <?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?><?= $vehicle['year'] ? ' · ' . htmlspecialchars($vehicle['year']) : '' ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
