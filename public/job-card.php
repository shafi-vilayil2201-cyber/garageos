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

require_permission($user, 'job_cards.view');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];
$jobCardId = (int) ($_GET['id'] ?? 0);

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

$error = null;
$statuses = ['received', 'in_progress', 'quality_check', 'ready', 'delivered', 'on_hold', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {

        require_permission($user, 'job_cards.manage');

        $newStatus = $_POST['status'] ?? '';

        if (in_array($newStatus, $statuses, true)) {

            $statement = $pdo->prepare("
                UPDATE job_cards
                SET status = :status, closed_at = " . ($newStatus === 'delivered' ? 'CURRENT_TIMESTAMP' : 'closed_at') . ", updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $statement->execute(['status' => $newStatus, 'id' => $jobCardId]);

            if ($newStatus === 'delivered') {

                // A fresh service resolves any reminder that was nudging
                // the customer to come back — and starts the countdown
                // to the next one.
                $statement = $pdo->prepare("
                    UPDATE reminders
                    SET status = 'dismissed'
                    WHERE vehicle_id = :vehicle_id AND due_type = 'service_due' AND status = 'pending'
                ");
                $statement->execute(['vehicle_id' => $jobCard['vehicle_id']]);

                $statement = $pdo->prepare("
                    INSERT INTO reminders (organization_id, customer_id, vehicle_id, due_type, due_date)
                    VALUES (:organization_id, :customer_id, :vehicle_id, 'service_due', CURRENT_DATE + INTERVAL '90 days')
                    ON CONFLICT (vehicle_id, due_type, due_date) DO NOTHING
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'customer_id' => $jobCard['customer_id'],
                    'vehicle_id' => $jobCard['vehicle_id']
                ]);
            }

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

        if ($price !== false) {
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
        }

        header('Location: /job-card.php?id=' . $jobCardId);
        exit;

    } elseif ($action === 'add_part') {

        require_permission($user, 'job_cards.manage');

        $partId = (int) ($_POST['part_id'] ?? 0);
        $quantity = (float) ($_POST['quantity'] ?? 0);

        if ($partId && $quantity > 0) {

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
                    INSERT INTO job_card_parts (job_card_id, part_id, quantity, unit_price)
                    VALUES (:job_card_id, :part_id, :quantity, :unit_price)
                ");
                $statement->execute([
                    'job_card_id' => $jobCardId,
                    'part_id' => $partId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice
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
            }
        }

        if (!$error) {
            header('Location: /job-card.php?id=' . $jobCardId);
            exit;
        }

    } elseif ($action === 'generate_invoice') {

        require_permission($user, 'invoices.manage');

        $statement = $pdo->prepare("SELECT id FROM invoices WHERE job_card_id = :job_card_id");
        $statement->execute(['job_card_id' => $jobCardId]);
        $existingInvoiceId = $statement->fetchColumn();

        if ($existingInvoiceId) {
            header('Location: /invoice.php?id=' . $existingInvoiceId);
            exit;
        }

        $statement = $pdo->prepare("
            SELECT s.name, jci.price - jci.discount AS total, s.tax_rate
            FROM job_card_items jci
            INNER JOIN services s ON s.id = jci.service_id
            WHERE jci.job_card_id = :job_card_id
        ");
        $statement->execute(['job_card_id' => $jobCardId]);
        $serviceLines = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $pdo->prepare("
            SELECT p.name, jcp.quantity, jcp.unit_price, p.tax_rate
            FROM job_card_parts jcp
            INNER JOIN parts p ON p.id = jcp.part_id
            WHERE jcp.job_card_id = :job_card_id
        ");
        $statement->execute(['job_card_id' => $jobCardId]);
        $partLines = $statement->fetchAll(PDO::FETCH_ASSOC);

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
                        'total' => $lineTotal + $lineTax
                    ];
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
                    INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, tax_rate, total)
                    VALUES (:invoice_id, :item_type, :description, :quantity, :unit_price, :tax_rate, :total)
                ");

                foreach ($lineItems as $item) {
                    $statement->execute([
                        'invoice_id' => $invoiceId,
                        'item_type' => $item['item_type'],
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'tax_rate' => $item['tax_rate'],
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
    SELECT jcp.id, p.name, jcp.quantity, jcp.unit_price
    FROM job_card_parts jcp
    INNER JOIN parts p ON p.id = jcp.part_id
    WHERE jcp.job_card_id = :job_card_id
    ORDER BY jcp.created_at
");
$statement->execute(['job_card_id' => $jobCardId]);
$partLines = $statement->fetchAll(PDO::FETCH_ASSOC);

$runningTotal = array_sum(array_map(fn($l) => $l['price'] - $l['discount'], $serviceLines))
    + array_sum(array_map(fn($l) => $l['quantity'] * $l['unit_price'], $partLines));

$statement = $pdo->prepare("SELECT id, name FROM services WHERE organization_id = :organization_id AND status = 'active' ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$services = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = :organization_id AND status = 'active' ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$technicians = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("SELECT id, invoice_no, status FROM invoices WHERE job_card_id = :job_card_id");
$statement->execute(['job_card_id' => $jobCardId]);
$invoice = $statement->fetch(PDO::FETCH_ASSOC);

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
                <?php else: ?>
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="generate_invoice">
                        <button type="submit" class="button"><?= icon('receipt', 16) ?> Generate invoice</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <div class="form-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="content-grid" style="grid-template-columns: 1fr 340px;">

                <div class="stack">

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('settings', 15) ?></span>
                                Services
                            </div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <?php if (empty($serviceLines)): ?>
                                <div class="empty-state"><?= icon('settings', 26) ?>No services added yet.</div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <tr><th>Service</th><th>Technician</th><th>Price</th></tr>
                                        <?php foreach ($serviceLines as $line): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($line['name']) ?></td>
                                                <td><?= htmlspecialchars($line['technician_name'] ?? '—') ?></td>
                                                <td class="num">₹<?= number_format($line['price'] - $line['discount'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <form method="POST" action="" class="panel-footer actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_service">
                                <div class="form-field" style="flex:1;">
                                    <label>Add service</label>
                                    <select name="service_id" required>
                                        <?php foreach ($services as $service): ?>
                                            <option value="<?= (int) $service['id'] ?>"><?= htmlspecialchars($service['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-field" style="flex:1;">
                                    <label>Technician</label>
                                    <select name="technician_id">
                                        <option value="">Unassigned</option>
                                        <?php foreach ($technicians as $technician): ?>
                                            <option value="<?= (int) $technician['id'] ?>"><?= htmlspecialchars($technician['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="button secondary"><?= icon('plus', 16) ?> Add</button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('box', 15) ?></span>
                                Parts used
                            </div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <?php if (empty($partLines)): ?>
                                <div class="empty-state"><?= icon('box', 26) ?>No parts added yet.</div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <tr><th>Part</th><th>Qty</th><th>Unit price</th><th>Total</th></tr>
                                        <?php foreach ($partLines as $line): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($line['name']) ?></td>
                                                <td class="num"><?= rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') ?></td>
                                                <td class="num">₹<?= number_format($line['unit_price'], 2) ?></td>
                                                <td class="num">₹<?= number_format($line['quantity'] * $line['unit_price'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <div class="panel-footer">
                                <div class="search-row" style="margin-bottom:10px;">
                                    <input type="search" id="part-search" placeholder="Search part by name or SKU..." autocomplete="off">
                                    <button type="button" class="button secondary" id="part-search-button"><?= icon('search', 16) ?> Search</button>
                                </div>
                                <div id="part-results"></div>

                                <form method="POST" action="" id="add-part-form" style="display:none;">
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
                                        <button type="submit" class="button secondary"><?= icon('plus', 16) ?> Add</button>
                                    </div>
                                </form>
                            </div>
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

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('settings', 15) ?></span>
                                Update status
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_status">
                                <div class="form-field">
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

<script src="/js/job-card-detail.js"></script>
</body>
</html>
