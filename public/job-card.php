<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/JobCardStatus.php';
require_once __DIR__ . '/../app/Domain/Audit.php';
require_once __DIR__ . '/../app/Domain/PartUnit.php';
require_once __DIR__ . '/../app/Domain/Accessory.php';
require_once __DIR__ . '/../app/View/JobCardIntakeExtras.php';
require_once __DIR__ . '/../app/Support/Flash.php';

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
    WHERE jc.id = :id AND jc.organization_id = :organization_id AND jc.deleted_at IS NULL
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

            apply_job_card_status($pdo, $jobCard, $newStatus, $organizationId, $user);

            flash_set("Status updated to " . str_replace('_', ' ', $newStatus) . ".");
            header('Location: /job-card.php?id=' . $jobCardId);
            exit;
        }

    } elseif ($action === 'update_promised_at') {

        require_permission($user, 'job_cards.manage');

        $promisedAt = trim($_POST['promised_at'] ?? '');
        $previousPromisedAt = $jobCard['promised_at'] ? date('Y-m-d\TH:i', strtotime($jobCard['promised_at'])) : '';

        $statement = $pdo->prepare("
            UPDATE job_cards SET promised_at = :promised_at, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute([
            'promised_at' => $promisedAt ?: null,
            'id' => $jobCardId,
            'organization_id' => $organizationId
        ]);

        if ($promisedAt !== $previousPromisedAt) {
            log_audit_event(
                $pdo, $user, 'update', 'job_card', $jobCardId,
                "Changed promised delivery for {$jobCard['job_no']} to " . ($promisedAt ?: 'not set')
            );
        }

        flash_set('Promised delivery saved.');
        header('Location: /job-card.php?id=' . $jobCardId);
        exit;

    } elseif ($action === 'update_accessories') {

        require_permission($user, 'job_cards.manage');

        save_accessories($pdo, $jobCardId, $_POST['accessories'] ?? []);

        log_audit_event(
            $pdo, $user, 'update', 'job_card', $jobCardId,
            "Recorded accessories present for {$jobCard['job_no']}"
        );

        flash_set('Accessories saved.');
        header('Location: /job-card.php?id=' . $jobCardId);
        exit;

    } elseif ($action === 'update_damage') {

        require_permission($user, 'job_cards.manage');

        save_vehicle_damage($pdo, $jobCardId, $_POST['damage_marks_json'] ?? null, $_POST['damage_image_data'] ?? null);

        log_audit_event(
            $pdo, $user, 'update', 'job_card', $jobCardId,
            "Recorded vehicle condition for {$jobCard['job_no']}"
        );

        flash_set('Vehicle condition saved.');
        header('Location: /job-card.php?id=' . $jobCardId);
        exit;

    } elseif ($action === 'add_service') {

        require_permission($user, 'job_cards.manage');

        $serviceId = (int) ($_POST['service_id'] ?? 0) ?: null;
        $customName = trim($_POST['custom_name'] ?? '');
        $customPrice = $_POST['custom_price'] ?? '';
        $technicianId = (int) ($_POST['technician_id'] ?? 0) ?: null;

        if ($serviceId) {
            // Preset service selected — validate it exists
            $statement = $pdo->prepare("SELECT name, standard_price FROM services WHERE id = :id AND organization_id = :organization_id");
            $statement->execute(['id' => $serviceId, 'organization_id' => $organizationId]);
            $catalogService = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$catalogService) {
                $error = 'Select a valid service before adding it.';
                $errorAction = 'add_service';
            } else {
                // Use the user-entered price if provided, otherwise fall back to standard price
                $price = ($customPrice !== '') ? (float) $customPrice : (float) $catalogService['standard_price'];
                $addedServiceName = $catalogService['name'];

                $statement = $pdo->prepare("
                    INSERT INTO job_card_items (job_card_id, service_id, technician_id, price, custom_price)
                    VALUES (:job_card_id, :service_id, :technician_id, :price, :custom_price)
                ");
                $statement->execute([
                    'job_card_id' => $jobCardId,
                    'service_id' => $serviceId,
                    'technician_id' => $technicianId,
                    'price' => $price,
                    'custom_price' => $price
                ]);

                log_audit_event(
                    $pdo, $user, 'create', 'job_card_item', $jobCardId,
                    "Added service '$addedServiceName' to job card {$jobCard['job_no']}" . ($price != $catalogService['standard_price'] ? " (price adjusted to ₹$price)" : '')
                );

                flash_set("Added \"$addedServiceName\".");
                header('Location: /job-card.php?id=' . $jobCardId);
                exit;
            }
        } elseif ($customName !== '') {
            // Custom service — name and price entered manually
            $price = ($customPrice !== '') ? (float) $customPrice : 0;

            if ($price <= 0) {
                $error = 'Enter a valid price for the custom service.';
                $errorAction = 'add_service';
            } else {
                $statement = $pdo->prepare("
                    INSERT INTO job_card_items (job_card_id, service_id, technician_id, price, custom_name, custom_price)
                    VALUES (:job_card_id, NULL, :technician_id, :price, :custom_name, :custom_price)
                ");
                $statement->execute([
                    'job_card_id' => $jobCardId,
                    'technician_id' => $technicianId,
                    'price' => $price,
                    'custom_name' => $customName,
                    'custom_price' => $price
                ]);

                log_audit_event(
                    $pdo, $user, 'create', 'job_card_item', $jobCardId,
                    "Added custom service '$customName' (₹$price) to job card {$jobCard['job_no']}"
                );

                flash_set("Added \"$customName\".");
                header('Location: /job-card.php?id=' . $jobCardId);
                exit;
            }
        } else {
            $error = 'Enter a service name or select one from the suggestions.';
            $errorAction = 'add_service';
        }

    } elseif ($action === 'add_part') {

        require_permission($user, 'job_cards.manage');

        $partId = (int) ($_POST['part_id'] ?? 0);
        $quantity = (float) ($_POST['quantity'] ?? 0);
        $labourCharge = (float) ($_POST['labour_charge'] ?? 0);
        $labourQuantity = (float) ($_POST['labour_quantity'] ?? 1);
        $technicianId = (int) ($_POST['technician_id'] ?? 0) ?: null;

        if ($partId && $quantity > 0 && $labourCharge >= 0 && $labourQuantity > 0) {

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

                $statement = $pdo->prepare("SELECT selling_price, unit FROM parts WHERE id = :id");
                $statement->execute(['id' => $partId]);
                $partRow = $statement->fetch(PDO::FETCH_ASSOC);
                $unitPrice = $partRow['selling_price'];

                if (part_unit_is_whole($partRow['unit']) && fmod($quantity, 1) !== 0.0) {
                    throw new RuntimeException('Quantity must be a whole number for this part.');
                }

                $statement = $pdo->prepare("
                    INSERT INTO job_card_parts (job_card_id, part_id, quantity, unit_price, technician_id, labour_charge, labour_quantity)
                    VALUES (:job_card_id, :part_id, :quantity, :unit_price, :technician_id, :labour_charge, :labour_quantity)
                ");
                $statement->execute([
                    'job_card_id' => $jobCardId,
                    'part_id' => $partId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'technician_id' => $technicianId,
                    'labour_charge' => $labourCharge,
                    'labour_quantity' => $labourQuantity
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

                $statement = $pdo->prepare("SELECT name FROM parts WHERE id = :id");
                $statement->execute(['id' => $partId]);
                $addedPartName = $statement->fetchColumn();
                $addedPartUnit = part_unit_short($partRow['unit']);

                log_audit_event(
                    $pdo, $user, 'create', 'job_card_part', $jobCardId,
                    "Added part '$addedPartName' (x{$quantity} {$addedPartUnit}) to job card {$jobCard['job_no']}"
                    . ($labourCharge > 0 ? ", labour ₹{$labourCharge} × {$labourQuantity}" : '')
                );

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
            flash_set("Added \"$addedPartName\".");
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
            SELECT COALESCE(jci.custom_name, s.name) AS name,
                   jci.price - jci.discount AS total,
                   COALESCE(s.tax_rate, 0) AS tax_rate,
                   s.sac_code
            FROM job_card_items jci
            LEFT JOIN services s ON s.id = jci.service_id
            WHERE jci.job_card_id = :job_card_id
        ");
        $statement->execute(['job_card_id' => $jobCardId]);
        $serviceLines = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $pdo->prepare("
            SELECT p.name, jcp.quantity, jcp.unit_price, p.tax_rate, p.hsn_code, jcp.labour_charge, jcp.labour_quantity
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
                    $labourQuantity = (float) $line['labour_quantity'];

                    if ($labourCharge > 0) {

                        $labourLineTotal = $labourCharge * $labourQuantity;
                        $labourTax = $labourLineTotal * ($orgDefaultTaxRate / 100);
                        $subtotal += $labourLineTotal;
                        $taxAmount += $labourTax;

                        $lineItems[] = [
                            'item_type' => 'labour',
                            'description' => 'Labour — ' . $line['name'],
                            'quantity' => $labourQuantity,
                            'unit_price' => $labourCharge,
                            'tax_rate' => $orgDefaultTaxRate,
                            'hsn_sac_code' => null,
                            'total' => $labourLineTotal + $labourTax
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

                log_audit_event(
                    $pdo, $user, 'create', 'invoice', $invoiceId,
                    "Generated invoice $invoiceNo from job card {$jobCard['job_no']}"
                );

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
            $removedItemId = (int) ($_POST['item_id'] ?? 0);

            $statement = $pdo->prepare("
                SELECT s.name FROM job_card_items jci
                INNER JOIN services s ON s.id = jci.service_id
                WHERE jci.id = :id AND jci.job_card_id = :job_card_id
            ");
            $statement->execute(['id' => $removedItemId, 'job_card_id' => $jobCardId]);
            $removedServiceName = $statement->fetchColumn();

            $statement = $pdo->prepare("DELETE FROM job_card_items WHERE id = :id AND job_card_id = :job_card_id");
            $statement->execute(['id' => $removedItemId, 'job_card_id' => $jobCardId]);

            if ($removedServiceName !== false) {
                log_audit_event(
                    $pdo, $user, 'delete', 'job_card_item', $jobCardId,
                    "Removed service '$removedServiceName' from job card {$jobCard['job_no']}"
                );
                flash_set("Removed \"$removedServiceName\".");
            }
        }

        header('Location: /job-card.php?id=' . $jobCardId);
        exit;

    } elseif ($action === 'remove_part') {

        require_permission($user, 'job_cards.manage');

        if (!$invoice) {

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare("
                    SELECT jcp.part_id, jcp.quantity, p.name
                    FROM job_card_parts jcp
                    INNER JOIN parts p ON p.id = jcp.part_id
                    WHERE jcp.id = :id AND jcp.job_card_id = :job_card_id
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

                    log_audit_event(
                        $pdo, $user, 'delete', 'job_card_part', $jobCardId,
                        "Removed part '{$removed['name']}' from job card {$jobCard['job_no']}"
                    );
                    flash_set("Removed \"{$removed['name']}\".");
                }

                $pdo->commit();

            } catch (Throwable $e) {
                $pdo->rollBack();
            }
        }

        header('Location: /job-card.php?id=' . $jobCardId);
        exit;

    } elseif ($action === 'delete_job_card') {

        require_permission($user, 'job_cards.manage');

        $deleteError = soft_delete_job_card($pdo, $jobCard, $user, (bool) $invoice);

        if ($deleteError) {
            $error = $deleteError;
            $errorAction = 'delete_job_card';
        } else {
            flash_set("{$jobCard['job_no']} removed from the board. Restore it anytime from {$jobCard['customer_name']}'s customer page.");
            header('Location: /customer.php?id=' . (int) $jobCard['customer_id']);
            exit;
        }
    }
}

$statement = $pdo->prepare("
    SELECT jci.id, COALESCE(jci.custom_name, s.name) AS name, jci.price, jci.discount, jci.status, u.name AS technician_name
    FROM job_card_items jci
    LEFT JOIN services s ON s.id = jci.service_id
    LEFT JOIN users u ON u.id = jci.technician_id
    WHERE jci.job_card_id = :job_card_id
    ORDER BY jci.created_at
");
$statement->execute(['job_card_id' => $jobCardId]);
$serviceLines = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("
    SELECT jcp.id, p.name, p.unit, jcp.quantity, jcp.unit_price, jcp.labour_charge, jcp.labour_quantity, u.name AS technician_name
    FROM job_card_parts jcp
    INNER JOIN parts p ON p.id = jcp.part_id
    LEFT JOIN users u ON u.id = jcp.technician_id
    WHERE jcp.job_card_id = :job_card_id
    ORDER BY jcp.created_at
");
$statement->execute(['job_card_id' => $jobCardId]);
$partLines = $statement->fetchAll(PDO::FETCH_ASSOC);

$runningTotal = array_sum(array_map(fn($l) => $l['price'] - $l['discount'], $serviceLines))
    + array_sum(array_map(fn($l) => $l['quantity'] * $l['unit_price'] + $l['labour_charge'] * $l['labour_quantity'], $partLines));

$statement = $pdo->prepare("SELECT accessory_key FROM job_card_accessories WHERE job_card_id = :job_card_id");
$statement->execute(['job_card_id' => $jobCardId]);
$checkedAccessoryKeys = $statement->fetchAll(PDO::FETCH_COLUMN);

$statement = $pdo->prepare("SELECT part_key, damage_type, x, y FROM job_card_damage_marks WHERE job_card_id = :job_card_id ORDER BY id");
$statement->execute(['job_card_id' => $jobCardId]);
$damageMarks = $statement->fetchAll(PDO::FETCH_ASSOC);

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
    <?= favicon_tag($user['organization_logo_url'] ?? null) ?>
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

                <div style="display:flex; gap:10px; align-items:center;">
                    <a href="/job-card-print.php?id=<?= $jobCardId ?>" target="_blank" class="button secondary"><?= icon('printer', 16) ?> Print Job Card</a>

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
                                                        <form method="POST" action=""
                                                              data-confirm="Remove this service from the job card?"
                                                              data-confirm-title="Remove service?"
                                                              data-confirm-label="Remove">
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
                                                <td class="num"><?= rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') ?> <?= htmlspecialchars(part_unit_short($line['unit'])) ?></td>
                                                <td class="num">₹<?= number_format($line['unit_price'], 2) ?></td>
                                                <td class="num">₹<?= number_format($line['quantity'] * $line['unit_price'], 2) ?></td>
                                                <?php if ($canRemoveLines): ?>
                                                    <td>
                                                        <form method="POST" action=""
                                                              data-confirm="Remove this part and its labour charge, and restore stock?"
                                                              data-confirm-title="Remove part?"
                                                              data-confirm-label="Remove">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="remove_part">
                                                            <input type="hidden" name="item_id" value="<?= (int) $line['id'] ?>">
                                                            <button type="submit" class="link-action" style="background:none; border:none; cursor:pointer; padding:0;"><?= icon('x', 14) ?> Remove</button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php if ($line['labour_charge'] > 0): ?>
                                                <?php $labourQty = (float) $line['labour_quantity']; ?>
                                                <tr class="part-labour-row">
                                                    <td colspan="<?= $canRemoveLines ? 5 : 4 ?>">
                                                        <span class="part-labour-label">
                                                            <?= icon('wrench', 12) ?>
                                                            Labour<?= $labourQty != 1 ? ' ×' . rtrim(rtrim(number_format($labourQty, 2), '0'), '.') : '' ?><?= $line['technician_name'] ? ' — ' . htmlspecialchars($line['technician_name']) : '' ?>
                                                        </span>
                                                        <span class="num">₹<?= number_format($line['labour_charge'] * $labourQty, 2) ?></span>
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

                    <?php if (!empty($checkedAccessoryKeys)): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('check-circle', 15) ?></span>
                                    Accessories present
                                </div>
                                <?php if ($canManageJobCards): ?>
                                    <button type="button" class="card-header-icon-button" onclick="openModal('add-accessories-modal')" title="Edit accessories"><?= icon('edit', 14) ?></button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?= accessory_checklist_display($checkedAccessoryKeys) ?>
                                <?= recorded_at_label($jobCard['accessories_recorded_at'], $jobCard['accessories_updated_at']) ?>
                            </div>
                        </div>
                    <?php elseif ($canManageJobCards): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('check-circle', 15) ?></span>
                                    Accessories present
                                </div>
                            </div>
                            <div class="card-body">
                                <p class="result-meta" style="margin-bottom:12px;">Not recorded yet.</p>
                                <button type="button" class="button secondary sm" onclick="openModal('add-accessories-modal')"><?= icon('plus', 14) ?> Add accessories</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($damageMarks) || !empty($jobCard['damage_image_url'])): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('alert-triangle', 15) ?></span>
                                    Vehicle condition
                                </div>
                                <?php if ($canManageJobCards): ?>
                                    <button type="button" class="card-header-icon-button" onclick="openModal('add-damage-modal')" title="Edit vehicle condition"><?= icon('edit', 14) ?></button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body" style="text-align:center;">
                                <?php if (!empty($jobCard['damage_image_url'])): ?>
                                    <div style="max-width:220px; margin:0 auto 12px;">
                                        <?= damage_image_display($jobCard['damage_image_url']) ?>
                                    </div>
                                <?php endif; ?>
                                <?= damage_marks_display($damageMarks) ?>
                                <?= recorded_at_label($jobCard['damage_recorded_at'], $jobCard['damage_updated_at']) ?>
                            </div>
                        </div>
                    <?php elseif ($canManageJobCards): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('alert-triangle', 15) ?></span>
                                    Vehicle condition
                                </div>
                            </div>
                            <div class="card-body">
                                <p class="result-meta" style="margin-bottom:12px;">Not recorded yet.</p>
                                <button type="button" class="button secondary sm" onclick="openModal('add-damage-modal')"><?= icon('plus', 14) ?> Add vehicle condition</button>
                            </div>
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

                                <form method="POST" action="" style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_promised_at">
                                    <div class="form-field">
                                        <label>Promised delivery</label>
                                        <input type="datetime-local" name="promised_at" value="<?= $jobCard['promised_at'] ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($jobCard['promised_at']))) : '' ?>">
                                    </div>
                                    <div class="form-actions" style="margin-top:10px;">
                                        <button type="submit" class="button secondary"><?= icon('check', 16) ?> Save</button>
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

                    <?php if ($canManageJobCards): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('alert-triangle', 15) ?></span>
                                    Delete job card
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($errorAction === 'delete_job_card' && $error): ?>
                                    <div class="form-error" style="margin-bottom:12px;"><?= htmlspecialchars($error) ?></div>
                                <?php endif; ?>
                                <p class="result-meta" style="margin-bottom:12px;">
                                    <?= htmlspecialchars($jobCard['job_no']) ?> will be removed from the board. It's not deleted — you
                                    can find and restore it from <?= htmlspecialchars($jobCard['customer_name']) ?>'s customer page.
                                </p>
                                <form method="POST" action=""
                                      data-confirm="<?= htmlspecialchars($jobCard['job_no'], ENT_QUOTES) ?> will be removed from the board. It's not deleted — you can find and restore it from <?= htmlspecialchars($jobCard['customer_name'], ENT_QUOTES) ?>'s customer page."
                                      data-confirm-title="Delete job card?"
                                      data-confirm-label="Delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_job_card">
                                    <button type="submit" class="button danger"><?= icon('x', 16) ?> Delete job card</button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

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
                <form method="POST" action="" class="stack" id="add-service-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_service">
                    <input type="hidden" name="service_id" id="selected_service_id" value="">
                    <input type="hidden" name="custom_name" id="selected_custom_name" value="">
                    <div class="form-field" style="position:relative;">
                        <label for="service-search">Service</label>
                        <input type="text" id="service-search" placeholder="Type to search or enter a custom service..." autocomplete="off">
                        <div id="service-results" class="autocomplete-results"></div>
                    </div>
                    <div class="form-field">
                        <label for="service_custom_price">Amount (₹)</label>
                        <input type="number" name="custom_price" id="service_custom_price" min="0" step="0.01" placeholder="Enter amount" required>
                    </div>
                    <div class="form-field">
                        <label for="service_technician_id">Technician</label>
                        <select name="technician_id" id="service_technician_id">
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
                            <label id="part_quantity_label">Qty</label>
                            <input type="number" name="quantity" id="part_quantity" min="0.01" step="0.01" value="1" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-field">
                            <label for="part_labour_charge">Labour rate (₹)</label>
                            <input type="number" name="labour_charge" id="part_labour_charge" min="0" step="0.01" value="0">
                        </div>
                        <div class="form-field">
                            <label for="part_labour_quantity">Labour qty</label>
                            <input type="number" name="labour_quantity" id="part_labour_quantity" min="0.01" step="0.01" value="1">
                        </div>
                    </div>

                    <p class="result-meta" id="part_labour_total_preview" style="margin:-6px 0 14px;"></p>

                    <div class="form-field">
                        <label for="part_technician_id">Technician</label>
                        <select name="technician_id" id="part_technician_id">
                            <option value="">Unassigned</option>
                            <?php foreach ($technicians as $technician): ?>
                                <option value="<?= (int) $technician['id'] ?>"><?= htmlspecialchars($technician['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="actions">
                        <button type="submit" class="button"><?= icon('plus', 16) ?> Add part</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="add-accessories-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('check-circle', 16) ?></span>
                    <?= !empty($checkedAccessoryKeys) ? 'Edit Accessories' : 'Add Accessories' ?>
                </div>
                <button type="button" class="modal-close" data-close-modal="add-accessories-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_accessories">
                    <?= accessory_intake_block($checkedAccessoryKeys) ?>
                    <div class="actions actions-float">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save accessories</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="add-damage-modal">
        <div class="modal modal-wide">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('alert-triangle', 16) ?></span>
                    <?= !empty($damageMarks) ? 'Edit Vehicle Condition' : 'Add Vehicle Condition' ?>
                </div>
                <button type="button" class="modal-close" data-close-modal="add-damage-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_damage">
                    <?= damage_intake_block('adddamage', $damageMarks) ?>
                    <div class="actions actions-float">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save condition</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="/js/job-card-detail.js"></script>
    <script src="/js/damage-diagram.js"></script>
    <script>initDamageDiagram('adddamage');</script>

<?php endif; ?>

</body>
</html>
