<?php

require_once __DIR__ . '/../app/Auth/Auth.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'job_cards.view');

$organizationId = $user['organization_id'];
$jobCardId = (int) ($_GET['id'] ?? 0);

$statement = $pdo->prepare("
    SELECT jc.*, v.registration_no, v.make, v.model, v.year, v.fuel_type, v.color, v.odometer_km,
           c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
           o.name AS organization_name, o.phone AS organization_phone, o.address AS organization_address,
           o.tax_label AS organization_tax_label, o.tax_number AS organization_tax_number,
           o.logo_url AS organization_logo_url
    FROM job_cards jc
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    INNER JOIN customers c ON c.id = jc.customer_id
    INNER JOIN organizations o ON o.id = jc.organization_id
    WHERE jc.id = :id AND jc.organization_id = :organization_id
");
$statement->execute(['id' => $jobCardId, 'organization_id' => $organizationId]);
$jobCard = $statement->fetch(PDO::FETCH_ASSOC);

if (!$jobCard) {
    header('Location: /job-cards.php');
    exit;
}

$statement = $pdo->prepare("
    SELECT COALESCE(jci.custom_name, s.name) AS name, jci.price, jci.discount, u.name AS technician_name
    FROM job_card_items jci
    LEFT JOIN services s ON s.id = jci.service_id
    LEFT JOIN users u ON u.id = jci.technician_id
    WHERE jci.job_card_id = :job_card_id
    ORDER BY jci.created_at
");
$statement->execute(['job_card_id' => $jobCardId]);
$serviceLines = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("
    SELECT p.name, jcp.quantity, jcp.unit_price, jcp.labour_charge, jcp.labour_quantity, u.name AS technician_name
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — <?= htmlspecialchars($jobCard['job_no']) ?> — Job Card</title>

    <link rel="stylesheet" href="/css/print.css">
    <?= favicon_tag($jobCard['organization_logo_url'] ?? null) ?>
</head>
<body>

<div class="no-print">
    <button type="button" class="print-button" onclick="window.print()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
    </button>
</div>

<div class="sheet">

    <div class="letterhead">
        <div>
            <?php if (!empty($jobCard['organization_logo_url'])): ?>
                <img class="org-logo" src="<?= htmlspecialchars($jobCard['organization_logo_url']) ?>" alt="<?= htmlspecialchars($jobCard['organization_name']) ?>">
            <?php endif; ?>
            <div class="org-name"><?= htmlspecialchars($jobCard['organization_name']) ?></div>
            <div class="org-meta">
                <?php if ($jobCard['organization_address']): ?>
                    <?= nl2br(htmlspecialchars($jobCard['organization_address'])) ?><br>
                <?php endif; ?>
                <?php if ($jobCard['organization_phone']): ?>
                    Phone: <?= htmlspecialchars($jobCard['organization_phone']) ?><br>
                <?php endif; ?>
                <?php if ($jobCard['organization_tax_number']): ?>
                    <?= htmlspecialchars($jobCard['organization_tax_label']) ?>: <?= htmlspecialchars($jobCard['organization_tax_number']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="doc-title">
            <h1>Job Card</h1>
            <div class="job-no"><?= htmlspecialchars($jobCard['job_no']) ?></div>
            <div class="job-date">Created <?= htmlspecialchars(date('d M Y, h:i A', strtotime($jobCard['created_at']))) ?></div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-block">
            <h2>Customer</h2>
            <div class="info-row"><span class="label">Name</span><span class="value"><?= htmlspecialchars($jobCard['customer_name']) ?></span></div>
            <div class="info-row"><span class="label">Phone</span><span class="value"><?= htmlspecialchars($jobCard['customer_phone']) ?></span></div>
            <?php if ($jobCard['customer_address']): ?>
                <div class="info-row"><span class="label">Address</span><span class="value"><?= htmlspecialchars($jobCard['customer_address']) ?></span></div>
            <?php endif; ?>
        </div>
        <div class="info-block">
            <h2>Vehicle</h2>
            <div class="info-row"><span class="label">Registration</span><span class="value"><?= htmlspecialchars($jobCard['registration_no']) ?></span></div>
            <div class="info-row"><span class="label">Make / Model</span><span class="value"><?= htmlspecialchars($jobCard['make'] . ' ' . $jobCard['model']) ?><?= $jobCard['year'] ? ' (' . (int) $jobCard['year'] . ')' : '' ?></span></div>
            <div class="info-row"><span class="label">Fuel type</span><span class="value"><?= htmlspecialchars(ucfirst($jobCard['fuel_type'])) ?></span></div>
            <?php if ($jobCard['color']): ?>
                <div class="info-row"><span class="label">Color</span><span class="value"><?= htmlspecialchars($jobCard['color']) ?></span></div>
            <?php endif; ?>
            <?php if ($jobCard['odometer_in'] !== null): ?>
                <div class="info-row"><span class="label">Odometer in</span><span class="value"><?= (int) $jobCard['odometer_in'] ?> km</span></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($jobCard['customer_complaint']): ?>
        <div class="section">
            <h2>Customer complaint / request</h2>
            <div class="section-box"><?= htmlspecialchars($jobCard['customer_complaint']) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($serviceLines)): ?>
        <div class="section">
            <h2>Services</h2>
            <table>
                <tr><th>Service</th><th>Technician</th><th class="num">Price</th></tr>
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

    <?php if (!empty($partLines)): ?>
        <div class="section">
            <h2>Parts used</h2>
            <table>
                <tr><th>Part</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Total</th></tr>
                <?php foreach ($partLines as $line): ?>
                    <tr>
                        <td><?= htmlspecialchars($line['name']) ?></td>
                        <td class="num"><?= rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') ?></td>
                        <td class="num">₹<?= number_format($line['unit_price'], 2) ?></td>
                        <td class="num">₹<?= number_format($line['quantity'] * $line['unit_price'], 2) ?></td>
                    </tr>
                    <?php if ($line['labour_charge'] > 0): ?>
                        <?php $labourQty = (float) $line['labour_quantity']; ?>
                        <tr class="labour-line">
                            <td colspan="4">
                                Labour<?= $labourQty != 1 ? ' ×' . rtrim(rtrim(number_format($labourQty, 2), '0'), '.') : '' ?><?= $line['technician_name'] ? ' — ' . htmlspecialchars($line['technician_name']) : '' ?> — ₹<?= number_format($line['labour_charge'] * $labourQty, 2) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <div class="total-row">
        <span>Total (before tax)</span>
        <span class="num">₹<?= number_format($runningTotal, 2) ?></span>
    </div>

    <div class="declaration">
        I hereby authorize the above repair/service work to be carried out on my vehicle. I understand that the garage
        shall not be held liable for any loss or damage to the vehicle, its accessories, or personal belongings left
        inside due to fire, theft, or accident while the vehicle is in the garage's custody. I agree to clear all dues
        before taking delivery of the vehicle.
    </div>

    <div class="signatures">
        <div class="signature-block">
            <div class="signature-line">Customer Signature &amp; Date</div>
        </div>
        <div class="signature-block">
            <div class="signature-line">For <?= htmlspecialchars($jobCard['organization_name']) ?> — Authorized Signatory</div>
        </div>
    </div>

</div>

</body>
</html>
