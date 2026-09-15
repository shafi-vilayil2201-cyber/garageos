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

require_permission($user, 'job_cards.view');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];
$jobCardId = (int) ($_GET['id'] ?? 0);
$canManageJobCards = user_can($user, 'job_cards.manage');
$canManageInvoices = user_can($user, 'invoices.manage');

$statement = $pdo->prepare("
    SELECT jc.*, v.registration_no, v.make, v.model, v.year, v.fuel_type,
           c.name AS customer_name, c.phone AS customer_phone
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    INNER JOIN customers c ON c.id = jc.customer_id
    WHERE jc.id = :id AND jc.organization_id = :organization_id
");
$statement->execute(['id' => $jobCardId, 'organization_id' => $organizationId]);
$jobCard = $statement->fetch(PDO::FETCH_ASSOC);

if (!$jobCard) {
    header('Location: /job-cards.php');
    exit;
}

// Fetched early (not just at render time) so it can also gate the
// remove_service/remove_part actions below — once an invoice exists,
// what's on the job card must stay exactly what was actually invoiced.
$statement = $pdo->prepare("SELECT id, invoice_no, status FROM invoices WHERE job_card_id = :job_card_id");
$statement->execute(['job_card_id' => $jobCardId]);
$invoice = $statement->fetch(PDO::FETCH_ASSOC);

$canRemoveLines = $canManageJobCards && !$invoice;

$error = null;
$errorAction = null;
$allStatuses = ['received', 'in_progress', 'quality_check', 'ready', 'delivered', 'on_hold', 'cancelled'];

// Only the current status and whatever it can legally move to next —
// never a stage it's already passed.
$statuses = array_values(array_filter(
    $allStatuses,
    fn(string $status) => job_card_can_transition($jobCard['status'], $status)
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {

        require_permission($user, 'job_cards.manage');

        $newStatus = $_POST['status'] ?? '';

        if (in_array($newStatus, $allStatuses, true) && job_card_can_transition($jobCard['status'], $newStatus)) {

            apply_job_card_status($pdo, $jobCard, $newStatus, $organizationId);

            header('Location: /job-card.php?id=' . $jobCardId);
            exit;
        }

    } elseif ($action === 'add_service') {

        require_permission($user, 'job_cards.manage');

        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $technicianId = (int) ($_POST['technician_id'] ?? 0) ?: null;

        $statement = $pdo->prepare("SELECT standard_price FROM services WHERE id = :id AND organization_id = :organization_id");
        $statement->execute(['id' => $serviceId, 'organization_id' => $organizationId]);
        $price = $statement->fetchColumn();

        if ($price === false) {
            $error = 'Select a valid service before adding it.';
            $errorAction = 'add_service';
        } else {

            $statement = $pdo->prepare("
                INSERT INTO job_card_items (job_card_id, service_id, technician_id, price)
                VALUES (:job_card_id, :service_id, :technician_id, :price)
            ");
            $statement->execute([
                'job_card_id' => $jobCardId,
                'service_id' => $serviceId,
                'technician_id' => $technicianId,
                'price' => $price
            ]);

            header('Location: /job-card.php?id=' . $jobCardId);
            exit;
        }

    } elseif ($action === 'add_part') {

        require_permission($user, 'job_cards.manage');

        $partId = (int) ($_POST['part_id'] ?? 0);
        $quantity = (float) ($_POST['quantity'] ?? 0);
        $labourCharge = (float) ($_POST['labour_charge'] ?? 0);
        $technicianId = (int) ($_POST['technician_id'] ?? 0) ?: null;

        if ($partId && $quantity > 0 && $labourCharge >= 0) {

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare("
                    SELECT quantity FROM inventory
                    WHERE part_id = :part_id AND branch_id = :branch_id
                    FOR UPDATE
                ");
                $statement->execute(['part_id' => $partId, 'branch_id' => $branchId]);
                $available = (float) $statement->fetchColumn();

                if ($available < $quantity) {
                    throw new RuntimeException('Not enough stock for this part.');
                }

                $statement = $pdo->prepare("SELECT selling_price FROM parts WHERE id = :id");
                $statement->execute(['id' => $partId]);
                $unitPrice = $statement->fetchColumn();

                $statement = $pdo->prepare("
                    INSERT INTO job_card_parts (job_card_id, part_id, quantity, unit_price, technician_id, labour_charge)
                    VALUES (:job_card_id, :part_id, :quantity, :unit_price, :technician_id, :labour_charge)
                ");
                $statement->execute([
                    'job_card_id' => $jobCardId,
                    'part_id' => $partId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'technician_id' => $technicianId,
                    'labour_charge' => $labourCharge
                ]);

                $statement = $pdo->prepare("
                    UPDATE inventory SET quantity = quantity - :quantity, updated_at = CURRENT_TIMESTAMP
                    WHERE part_id = :part_id AND branch_id = :branch_id
                ");
                $statement->execute(['quantity' => $quantity, 'part_id' => $partId, 'branch_id' => $branchId]);

                $statement = $pdo->prepare("
                    INSERT INTO inventory_movements (organization_id, branch_id, part_id, quantity, direction, reason, reference_type, reference_id, created_by)
                    VALUES (:organization_id, :branch_id, :part_id, :quantity, 'out', 'job_card', 'job_card', :reference_id, :created_by)
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'part_id' => $partId,
                    'quantity' => $quantity,
                    'reference_id' => $jobCardId,
                    'created_by' => $user['id']
                ]);

                $pdo->commit();

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
                $errorAction = 'add_part';
            }
        } else {
            $error = 'Search for a part and enter a quantity before adding it.';
            $errorAction = 'add_part';
        }

        if (!$error) {
            header('Location: /job-card.php?id=' . $jobCardId);
            exit;
        }

    } elseif ($action === 'generate_invoice') {

        require_permission($user, 'invoices.manage');

        if ($invoice) {
            header('Location: /invoice.php?id=' . $invoice['id']);
            exit;
        }

        $statement = $pdo->prepare("
            SELECT s.name, jci.price - jci.discount AS total, s.tax_rate, s.sac_code
            FROM job_card_items jci
            INNER JOIN services s ON s.id = jci.service_id
            WHERE jci.job_card_id = :job_card_id
        ");
        $statement->execute(['job_card_id' => $jobCardId]);
        $serviceLines = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $pdo->prepare("
            SELECT p.name, jcp.quantity, jcp.unit_price, p.tax_rate, p.hsn_code, jcp.labour_charge
            FROM job_card_parts jcp
            INNER JOIN parts p ON p.id = jcp.part_id
            WHERE jcp.job_card_id = :job_card_id
        ");
        $statement->execute(['job_card_id' => $jobCardId]);
        $partLines = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Ad-hoc per-part labour has no catalog price/SAC code to draw a
        // tax rate from (unlike a Service), so it's taxed at the
        // organization's default rate — same lookup parts.php and
        // services.php already use for their own GST defaults.
        $statement = $pdo->prepare("SELECT default_tax_rate FROM organizations WHERE id = :id");
        $statement->execute(['id' => $organizationId]);
        $orgDefaultTaxRate = (float) $statement->fetchColumn();

        if (empty($serviceLines) && empty($partLines)) {
            $error = 'Add at least one service or part before generating an invoice.';
        } else {

            $pdo->beginTransaction();

            try {
                $subtotal = 0;
                $taxAmount = 0;
                $lineItems = [];

                foreach ($serviceLines as $line) {
                    $lineTotal = (float) $line['total'];
                    $lineTax = $lineTotal * ((float) $line['tax_rate'] / 100);
                    $subtotal += $lineTotal;
                    $taxAmount += $lineTax;

                    $lineItems[] = [
                        'item_type' => 'service',
                        'description' => $line['name'],
                        'quantity' => 1,
                        'unit_price' => $lineTotal,
                        'tax_rate' => $line['tax_rate'],
                        'hsn_sac_code' => $line['sac_code'],
                        'total' => $lineTotal + $lineTax
                    ];
                }

                foreach ($partLines as $line) {
                    $lineTotal = (float) $line['quantity'] * (float) $line['unit_price'];
                    $lineTax = $lineTotal * ((float) $line['tax_rate'] / 100);
                    $subtotal += $lineTotal;
                    $taxAmount += $lineTax;

                    $lineItems[] = [
                        'item_type' => 'part',
                        'description' => $line['name'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'tax_rate' => $line['tax_rate'],
                        'hsn_sac_code' => $line['hsn_code'],
                        'total' => $lineTotal + $lineTax
                    ];

                    $labourCharge = (float) $line['labour_charge'];

                    if ($labourCharge > 0) {

                        $labourTax = $labourCharge * ($orgDefaultTaxRate / 100);
                        $subtotal += $labourCharge;
                        $taxAmount += $labourTax;

                        $lineItems[] = [
                            'item_type' => 'labour',
                            'description' => 'Labour — ' . $line['name'],
                            'quantity' => 1,
                            'unit_price' => $labourCharge,
                            'tax_rate' => $orgDefaultTaxRate,
                            'hsn_sac_code' => null,
                            'total' => $labourCharge + $labourTax
                        ];
                    }
                }

                $total = $subtotal + $taxAmount;

                $statement = $pdo->prepare("
                    SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_no FROM 5) AS INT)), 0) + 1
                    FROM invoices WHERE branch_id = :branch_id
                ");
                $statement->execute(['branch_id' => $branchId]);
                $nextNumber = (int) $statement->fetchColumn();
                $invoiceNo = 'INV-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

                $statement = $pdo->prepare("
                    INSERT INTO invoices (organization_id, branch_id, job_card_id, customer_id, invoice_no, subtotal, tax_amount, total)
                    VALUES (:organization_id, :branch_id, :job_card_id, :customer_id, :invoice_no, :subtotal, :tax_amount, :total)
                    RETURNING id
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'job_card_id' => $jobCardId,
                    'customer_id' => $jobCard['customer_id'],
                    'invoice_no' => $invoiceNo,
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total
                ]);
                $invoiceId = $statement->fetchColumn();

                $statement = $pdo->prepare("
                    INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, tax_rate, hsn_sac_code, total)
                    VALUES (:invoice_id, :item_type, :description, :quantity, :unit_price, :tax_rate, :hsn_sac_code, :total)
                ");

                foreach ($lineItems as $item) {
                    $statement->execute([
                        'invoice_id' => $invoiceId,
                        'item_type' => $item['item_type'],
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'tax_rate' => $item['tax_rate'],
                        'hsn_sac_code' => $item['hsn_sac_code'] ?: null,
                        'total' => $item['total']
                    ]);
                }

                $pdo->commit();

                header('Location: /invoice.php?id=' . $invoiceId);
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }

    } elseif ($action === 'remove_service') {

        require_permission($user, 'job_cards.manage');

        // Once an invoice exists, what's on the job card must stay exactly
        // what was actually invoiced — same boundary as GST on an invoice
        // locking once a payment exists.
        if (!$invoice) {
            $statement = $pdo->prepare("DELETE FROM job_card_items WHERE id = :id AND job_card_id = :job_card_id");
            $statement->execute(['id' => (int) ($_POST['item_id'] ?? 0), 'job_card_id' => $jobCardId]);
        }

        header('Location: /job-card.php?id=' . $jobCardId);
        exit;

    } elseif ($action === 'remove_part') {

        require_permission($user, 'job_cards.manage');

        if (!$invoice) {

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare("
                    SELECT part_id, quantity FROM job_card_parts
                    WHERE id = :id AND job_card_id = :job_card_id
                    FOR UPDATE
                ");
                $statement->execute(['id' => (int) ($_POST['item_id'] ?? 0), 'job_card_id' => $jobCardId]);
                $removed = $statement->fetch(PDO::FETCH_ASSOC);

                if ($removed) {

                    $statement = $pdo->prepare("DELETE FROM job_card_parts WHERE id = :id");
                    $statement->execute(['id' => (int) ($_POST['item_id'] ?? 0)]);

                    $statement = $pdo->prepare("
                        UPDATE inventory SET quantity = quantity + :quantity, updated_at = CURRENT_TIMESTAMP
                        WHERE part_id = :part_id AND branch_id = :branch_id
                    ");
                    $statement->execute([
                        'quantity' => $removed['quantity'],
                        'part_id' => $removed['part_id'],
                        'branch_id' => $branchId
                    ]);

                    $statement = $pdo->prepare("
                        INSERT INTO inventory_movements (organization_id, branch_id, part_id, quantity, direction, reason, reference_type, reference_id, created_by)
                        VALUES (:organization_id, :branch_id, :part_id, :quantity, 'in', 'adjustment', 'job_card', :reference_id, :created_by)
                    ");
                    $statement->execute([
                        'organization_id' => $organizationId,
                        'branch_id' => $branchId,
                        'part_id' => $removed['part_id'],
                        'quantity' => $removed['quantity'],
                        'reference_id' => $jobCardId,
                        'created_by' => $user['id']
                    ]);
                }

                $pdo->commit();

            } catch (Throwable $e) {
                $pdo->rollBack();
            }
        }

        header('Location: /job-card.php?id=' . $jobCardId);
        exit;
    }
}

$statement = $pdo->prepare("
    SELECT jci.id, s.name, jci.price, jci.discount, jci.status, u.name AS technician_name
    FROM job_card_items jci
    INNER JOIN services s ON s.id = jci.service_id
    LEFT JOIN users u ON u.id = jci.technician_id
    WHERE jci.job_card_id = :job_card_id
    ORDER BY jci.created_at
");
$statement->execute(['job_card_id' => $jobCardId]);
$serviceLines = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("
    SELECT jcp.id, p.name, jcp.quantity, jcp.unit_price, jcp.labour_charge, u.name AS technician_name
    FROM job_card_parts jcp
    INNER JOIN parts p ON p.id = jcp.part_id
    LEFT JOIN users u ON u.id = jcp.technician_id
    WHERE jcp.job_card_id = :job_card_id
    ORDER BY jcp.created_at
");
$statement->execute(['job_card_id' => $jobCardId]);
$partLines = $statement->fetchAll(PDO::FETCH_ASSOC);

$runningTotal = array_sum(array_map(fn($l) => $l['price'] - $l['discount'], $serviceLines))
    + array_sum(array_map(fn($l) => $l['quantity'] * $l['unit_price'] + $l['labour_charge'], $partLines));

$statement = $pdo->prepare("SELECT id, name FROM services WHERE organization_id = :organization_id AND status = 'active' ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$services = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = :organization_id AND status = 'active' ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$technicians = $statement->fetchAll(PDO::FETCH_ASSOC);

$statusIcons = [
    'received' => 'clipboard-list',
    'in_progress' => 'settings',
    'quality_check' => 'check-circle',
    'ready' => 'bell',
    'delivered' => 'car',
    'on_hold' => 'pause',
    'cancelled' => 'x'
];

$activeNav = 'job_cards';
$topbarTitle = $jobCard['job_no'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — <?= htmlspecialchars($jobCard['job_no']) ?></title>

    <link rel="stylesheet" href="/css/app.css">
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <a href="/job-cards.php" class="link-action" style="margin-bottom:16px;"><?= icon('arrow-left', 14) ?> Back to Job Cards</a>

            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <?= htmlspecialchars($jobCard['job_no']) ?>
                        <span class="badge badge-<?= htmlspecialchars($jobCard['status']) ?>" style="margin-left:10px; vertical-align:middle;">
                            <?= icon($statusIcons[$jobCard['status']] ?? 'job-card', 12) ?>
                            <?= htmlspecialchars(str_replace('_', ' ', $jobCard['status'])) ?>
                        </span>
                    </h1>
                    <p class="page-description">
                        <?= htmlspecialchars($jobCard['registration_no']) ?> —
                        <?= htmlspecialchars($jobCard['make'] . ' ' . $jobCard['model']) ?>
                        · <?= htmlspecialchars($jobCard['customer_name']) ?> (<?= htmlspecialchars($jobCard['customer_phone']) ?>)
                    </p>
                </div>

                <?php if ($invoice): ?>
                    <a href="/invoice.php?id=<?= (int) $invoice['id'] ?>" class="button secondary"><?= icon('receipt', 16) ?> View invoice <?= htmlspecialchars($invoice['invoice_no']) ?></a>
                <?php elseif ($canManageInvoices): ?>
                    <?php $hasLines = !empty($serviceLines) || !empty($partLines); ?>
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="generate_invoice">
                        <button
                            type="submit"
                            class="button"
                            <?= $hasLines ? '' : 'disabled title="Add at least one service or part first"' ?>
                        ><?= icon('receipt', 16) ?> Generate invoice</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <div class="form-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="content-grid content-grid-aside">

                <div class="stack">

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('settings', 15) ?></span>
                                Services
                            </div>
                            <?php if ($canManageJobCards): ?>
                                <button type="button" class="button" onclick="openModal('add-service-modal')"><?= icon('plus', 16) ?> Add Service</button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <?php if (empty($serviceLines)): ?>
                                <div class="empty-state">
                                    <?= icon('settings', 26) ?>
                                    <?= $canManageJobCards ? 'No services added yet — add one above.' : 'No services added yet.' ?>
                                </div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <tr><th>Service</th><th>Technician</th><th>Price</th><?php if ($canRemoveLines): ?><th></th><?php endif; ?></tr>
                                        <?php foreach ($serviceLines as $line): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($line['name']) ?></td>
                                                <td><?= htmlspecialchars($line['technician_name'] ?? '—') ?></td>
                                                <td class="num">₹<?= number_format($line['price'] - $line['discount'], 2) ?></td>
                                                <?php if ($canRemoveLines): ?>
                                                    <td>
                                                        <form method="POST" action="" onsubmit="return confirm('Remove this service from the job card?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="remove_service">
                                                            <input type="hidden" name="item_id" value="<?= (int) $line['id'] ?>">
                                                            <button type="submit" class="link-action" style="background:none; border:none; cursor:pointer; padding:0;"><?= icon('x', 14) ?> Remove</button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('box', 15) ?></span>
                                Parts used
                            </div>
                            <?php if ($canManageJobCards): ?>
                                <button type="button" class="button" onclick="openModal('add-part-modal')"><?= icon('plus', 16) ?> Add Part</button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <?php if (empty($partLines)): ?>
                                <div class="empty-state">
                                    <?= icon('box', 26) ?>
                                    <?= $canManageJobCards ? 'No parts added yet — add one above.' : 'No parts added yet.' ?>
                                </div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <tr><th>Part</th><th>Qty</th><th>Unit price</th><th>Total</th><?php if ($canRemoveLines): ?><th></th><?php endif; ?></tr>
                                        <?php foreach ($partLines as $line): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($line['name']) ?></td>
                                                <td class="num"><?= rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') ?></td>
                                                <td class="num">₹<?= number_format($line['unit_price'], 2) ?></td>
                                                <td class="num">₹<?= number_format($line['quantity'] * $line['unit_price'], 2) ?></td>
                                                <?php if ($canRemoveLines): ?>
                                                    <td>
                                                        <form method="POST" action="" onsubmit="return confirm('Remove this part and its labour charge, and restore stock?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="remove_part">
                                                            <input type="hidden" name="item_id" value="<?= (int) $line['id'] ?>">
                                                            <button type="submit" class="link-action" style="background:none; border:none; cursor:pointer; padding:0;"><?= icon('x', 14) ?> Remove</button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php if ($line['labour_charge'] > 0): ?>
                                                <tr class="part-labour-row">
                                                    <td colspan="<?= $canRemoveLines ? 5 : 4 ?>">
                                                        <span class="part-labour-label">
                                                            <?= icon('wrench', 12) ?>
                                                            Labour<?= $line['technician_name'] ? ' — ' . htmlspecialchars($line['technician_name']) : '' ?>
                                                        </span>
                                                        <span class="num">₹<?= number_format($line['labour_charge'], 2) ?></span>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($jobCard['customer_complaint']): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('message', 15) ?></span>
                                    Customer complaint
                                </div>
                            </div>
                            <div class="card-body"><?= nl2br(htmlspecialchars($jobCard['customer_complaint'])) ?></div>
                        </div>
                    <?php endif; ?>

                </div>

                <div class="stack">

                    <?php if ($canManageJobCards): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('settings', 15) ?></span>
                                    Update status
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($jobCard['status'] === 'cancelled'): ?>
                                    <p class="stat-meta">This job card has been cancelled.</p>
                                <?php else: ?>
                                    <?php
                                        $trackerStages = [
                                            'received' => 'Received',
                                            'in_progress' => 'In Progress',
                                            'quality_check' => 'Quality Check',
                                            'ready' => 'Ready',
                                            'delivered' => 'Delivered'
                                        ];
                                        $trackerColors = [
                                            'received' => '#4338ca',
                                            'in_progress' => '#b45309',
                                            'on_hold' => '#b91c1c',
                                            'quality_check' => '#6d28d9',
                                            'ready' => '#15803d',
                                            'delivered' => '#78716c'
                                        ];
                                        $currentRank = job_card_status_rank($jobCard['status']);
                                    ?>
                                    <div class="status-tracker">
                                        <?php foreach ($trackerStages as $stageKey => $stageLabel): ?>
                                            <?php
                                                $stageRank = job_card_status_rank($stageKey);
                                                $isOnHoldHere = $jobCard['status'] === 'on_hold' && $stageKey === 'in_progress';
                                                $filled = $stageRank <= $currentRank;
                                                $color = $isOnHoldHere ? $trackerColors['on_hold'] : $trackerColors[$stageKey];
                                            ?>
                                            <div class="status-tracker-segment<?= $filled ? ' filled' : '' ?>" style="<?= $filled ? '--stage-color:' . $color . ';' : '' ?>">
                                                <span class="status-tracker-dot"></span>
                                                <span class="status-tracker-label"><?= htmlspecialchars($stageLabel) ?><?= $isOnHoldHere ? ' (On hold)' : '' ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <div class="form-field">
                                        <label>Status</label>
                                        <select name="status" onchange="this.form.submit()">
                                            <?php foreach ($statuses as $status): ?>
                                                <option value="<?= $status ?>" <?= $status === $jobCard['status'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(str_replace('_', ' ', ucfirst($status))) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('wallet', 15) ?></span>
                                Running total
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value">₹<?= number_format($runningTotal, 2) ?></div>
                            <p class="stat-meta">Before tax — generate the invoice for the final amount.</p>
                        </div>
                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

<?php if ($canManageJobCards): ?>

    <div class="modal-backdrop<?= $errorAction === 'add_service' ? ' open' : '' ?>" id="add-service-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('settings', 16) ?></span>
                    Add Service
                </div>
                <button type="button" class="modal-close" data-close-modal="add-service-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <?php if ($errorAction === 'add_service' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST" action="" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_service">
                    <div class="form-field">
                        <label for="service_id">Service</label>
                        <select name="service_id" id="service_id" required>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= (int) $service['id'] ?>"><?= htmlspecialchars($service['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="technician_id">Technician</label>
                        <select name="technician_id" id="technician_id">
                            <option value="">Unassigned</option>
                            <?php foreach ($technicians as $technician): ?>
                                <option value="<?= (int) $technician['id'] ?>"><?= htmlspecialchars($technician['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="actions">
                        <button type="submit" class="button"><?= icon('plus', 16) ?> Add service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-backdrop<?= $errorAction === 'add_part' ? ' open' : '' ?>" id="add-part-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('box', 16) ?></span>
                    Add Part
                </div>
                <button type="button" class="modal-close" data-close-modal="add-part-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <?php if ($errorAction === 'add_part' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <div class="search-row" style="margin-bottom:10px;">
                    <input type="search" id="part-search" placeholder="Search part by name or SKU..." autocomplete="off">
                </div>
                <div id="part-results"></div>

                <form method="POST" action="" id="add-part-form" class="stack" style="display:none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_part">
                    <input type="hidden" name="part_id" id="selected_part_id">
                    <div class="actions" style="align-items:end;">
                        <div class="selected-summary" style="flex:1; margin-bottom:0;">
                            <span class="icon-badge"><?= icon('box', 16) ?></span>
                            <div>
                                <strong id="selected_part_label"></strong>
                                <div class="result-meta" id="selected_part_stock"></div>
                            </div>
                        </div>
                        <div class="form-field" style="width:100px;">
                            <label>Qty</label>
                            <input type="number" name="quantity" id="part_quantity" min="0.01" step="0.01" value="1" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-field">
                            <label for="part_labour_charge">Labour charge (₹)</label>
                            <input type="number" name="labour_charge" id="part_labour_charge" min="0" step="0.01" value="0">
                        </div>
                        <div class="form-field">
                            <label for="part_technician_id">Technician</label>
                            <select name="technician_id" id="part_technician_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($technicians as $technician): ?>
                                    <option value="<?= (int) $technician['id'] ?>"><?= htmlspecialchars($technician['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="button"><?= icon('plus', 16) ?> Add part</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="/js/modal.js"></script>
    <script src="/js/job-card-detail.js"></script>

<?php endif; ?>

</body>
</html>
