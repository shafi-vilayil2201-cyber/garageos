<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Domain/Gst.php';
require_once __DIR__ . '/../app/Domain/InvoiceService.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'invoices.view');

$organizationId = $user['organization_id'];
$invoiceId = (int) ($_GET['id'] ?? 0);

// Format handling: 'a4' (default), 'thermal80' (3-inch 80mm), 'thermal58' (2-inch 58mm)
$formatParam = strtolower(trim($_GET['format'] ?? 'a4'));
if ($formatParam === '80mm') { $formatParam = 'thermal80'; }
if ($formatParam === '58mm') { $formatParam = 'thermal58'; }
$format = in_array($formatParam, ['a4', 'thermal80', 'thermal58'], true) ? $formatParam : 'a4';
$isThermal = in_array($format, ['thermal80', 'thermal58'], true);

$statement = $pdo->prepare("
    SELECT i.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
           c.gstin AS customer_gstin, c.state AS customer_state,
           jc.job_no, v.registration_no, v.make, v.model,
           o.name AS organization_name, o.phone AS organization_phone, o.address AS organization_address,
           o.tax_label AS organization_tax_label, o.tax_number AS organization_tax_number, o.state AS organization_state,
           o.logo_url AS organization_logo_url
    FROM invoices i
    INNER JOIN customers c ON c.id = i.customer_id
    INNER JOIN job_cards jc ON jc.id = i.job_card_id
    INNER JOIN vehicles v ON v.id = jc.vehicle_id
    INNER JOIN organizations o ON o.id = i.organization_id
    WHERE i.id = :id AND i.organization_id = :organization_id
");
$statement->execute(['id' => $invoiceId, 'organization_id' => $organizationId]);
$invoice = $statement->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header('Location: /job-cards.php');
    exit;
}

$statement = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = :invoice_id ORDER BY id");
$statement->execute(['invoice_id' => $invoiceId]);
$items = $statement->fetchAll(PDO::FETCH_ASSOC);

$groupedItems = InvoiceService::groupItems($items);

$statement = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = :invoice_id ORDER BY created_at");
$statement->execute(['invoice_id' => $invoiceId]);
$payments = $statement->fetchAll(PDO::FETCH_ASSOC);

$balanceDue = (float) $invoice['total'] - (float) $invoice['amount_paid'];
$isInterState = gst_is_inter_state($invoice['organization_state'], $invoice['customer_state']);

$hasGst = (float) $invoice['tax_amount'] > 0.009;

$statusLabels = [
    'paid' => 'Paid',
    'partial' => 'Partially paid',
    'unpaid' => 'Unpaid',
    'void' => 'Void'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — <?= htmlspecialchars($invoice['invoice_no']) ?> — <?= $isThermal ? 'Receipt' : 'Invoice' ?></title>

    <?php if ($isThermal): ?>
        <link rel="stylesheet" href="/css/thermal-print.css">
    <?php else: ?>
        <link rel="stylesheet" href="/css/print.css">
        <link rel="stylesheet" href="/css/thermal-print.css" media="screen">
    <?php endif; ?>

    <?= favicon_tag($invoice['organization_logo_url'] ?? null) ?>
</head>
<body class="<?= $isThermal ? 'body-thermal' : 'body-a4' ?>">

<!-- TOP TOOLBAR: FORMAT SWITCHER & PRINT ACTIONS (HIDDEN WHEN PRINTED) -->
<div class="no-print" style="<?= !$isThermal ? 'max-width:210mm;' : '' ?>">
    <div class="toolbar-group">
        <a href="/invoice-print.php?id=<?= (int) $invoice['id'] ?>&format=a4" class="format-btn <?= $format === 'a4' ? 'active' : '' ?>" onclick="recordFormatChoice('a4')">
            Standard A4
        </a>
        <a href="/invoice-print.php?id=<?= (int) $invoice['id'] ?>&format=thermal80" class="format-btn <?= $format === 'thermal80' ? 'active' : '' ?>" onclick="recordFormatChoice('thermal80')">
            Thermal 80mm (3")
        </a>
        <a href="/invoice-print.php?id=<?= (int) $invoice['id'] ?>&format=thermal58" class="format-btn <?= $format === 'thermal58' ? 'active' : '' ?>" onclick="recordFormatChoice('thermal58')">
            Thermal 58mm (2")
        </a>
    </div>

    <div class="print-actions">
        <button type="button" id="set-default-btn" class="set-default-btn" onclick="setDefaultFormat('<?= htmlspecialchars($format) ?>')">
            Set as Default
        </button>
        <button type="button" class="print-button" onclick="window.print()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print <?= $isThermal ? 'Receipt' : 'Invoice' ?>
        </button>
    </div>
</div>

<?php if ($isThermal): ?>

    <!-- ====================================================================
         THERMAL POS RECEIPT LAYOUT (80mm / 58mm)
         ==================================================================== -->
    <div class="thermal-receipt <?= $format === 'thermal58' ? 'thermal-58' : 'thermal-80' ?>">

        <!-- Header -->
        <div class="receipt-header">
            <?php if (!empty($invoice['organization_logo_url'])): ?>
                <img class="receipt-logo" src="<?= htmlspecialchars($invoice['organization_logo_url']) ?>" alt="<?= htmlspecialchars($invoice['organization_name']) ?>">
            <?php endif; ?>
            <div class="receipt-org-name"><?= htmlspecialchars($invoice['organization_name']) ?></div>
            <div class="receipt-org-details">
                <?php if ($invoice['organization_address']): ?>
                    <?= nl2br(htmlspecialchars($invoice['organization_address'])) ?><br>
                <?php endif; ?>
                <?php if ($invoice['organization_phone']): ?>
                    Ph: <?= htmlspecialchars($invoice['organization_phone']) ?><br>
                <?php endif; ?>
                <?php if ($invoice['organization_tax_number'] && $hasGst): ?>
                    <?= htmlspecialchars($invoice['organization_tax_label'] ?: 'GSTIN') ?>: <?= htmlspecialchars($invoice['organization_tax_number']) ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="receipt-divider-double"></div>

        <!-- Title & Status -->
        <div class="receipt-title-box">
            <div class="receipt-title"><?= $hasGst ? 'TAX INVOICE' : 'RECEIPT' ?></div>
            <div class="receipt-status-tag">
                <?= htmlspecialchars($statusLabels[$invoice['status']] ?? strtoupper($invoice['status'])) ?>
            </div>
        </div>

        <div class="receipt-divider-dashed"></div>

        <!-- Meta info -->
        <div class="receipt-meta">
            <div class="receipt-row">
                <span class="r-label">Invoice No:</span>
                <span class="r-val"><?= htmlspecialchars($invoice['invoice_no']) ?></span>
            </div>
            <div class="receipt-row">
                <span class="r-label">Date:</span>
                <span class="r-val"><?= htmlspecialchars(date('d-m-Y h:i A', strtotime($invoice['created_at']))) ?></span>
            </div>
            <div class="receipt-row">
                <span class="r-label">Job Card:</span>
                <span class="r-val"><?= htmlspecialchars($invoice['job_no']) ?></span>
            </div>
            <div class="receipt-row">
                <span class="r-label">Vehicle:</span>
                <span class="r-val"><?= htmlspecialchars($invoice['registration_no']) ?> (<?= htmlspecialchars($invoice['make'] . ' ' . $invoice['model']) ?>)</span>
            </div>
            <div class="receipt-row">
                <span class="r-label">Customer:</span>
                <span class="r-val"><?= htmlspecialchars($invoice['customer_name']) ?></span>
            </div>
            <?php if ($invoice['customer_phone']): ?>
                <div class="receipt-row">
                    <span class="r-label">Phone:</span>
                    <span class="r-val"><?= htmlspecialchars($invoice['customer_phone']) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($invoice['customer_gstin']): ?>
                <div class="receipt-row">
                    <span class="r-label">GSTIN:</span>
                    <span class="r-val"><?= htmlspecialchars($invoice['customer_gstin']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Items table -->
        <table class="receipt-table">
            <thead>
                <tr>
                    <th class="col-desc">Item</th>
                    <th class="col-qty">Qty</th>
                    <th class="col-rate">Price</th>
                    <th class="col-total">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($groupedItems['parts'])): ?>
                    <tr>
                        <td colspan="4" style="font-weight:800; font-size:9.5px; padding-top:6px; text-transform:uppercase; border-bottom:1px solid #000;">
                            -- PARTS &amp; MATERIALS --
                        </td>
                    </tr>
                    <?php foreach ($groupedItems['parts'] as $item): ?>
                        <tr>
                            <td class="col-desc">
                                <?= htmlspecialchars($item['description']) ?>
                                <?php if ($hasGst && (float) $item['tax_rate'] > 0): ?>
                                    <div class="col-sub">GST @ <?= number_format($item['tax_rate'], 0) ?>%<?= $item['hsn_sac_code'] ? ' · HSN ' . htmlspecialchars($item['hsn_sac_code']) : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="col-qty"><?= rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') ?></td>
                            <td class="col-rate">₹<?= number_format($item['unit_price'], 2) ?></td>
                            <td class="col-total">₹<?= number_format($item['total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($groupedItems['labour'])): ?>
                    <tr>
                        <td colspan="4" style="font-weight:800; font-size:9.5px; padding-top:6px; text-transform:uppercase; border-bottom:1px solid #000; <?= !empty($groupedItems['parts']) ? 'border-top:1px dashed #000;' : '' ?>">
                            -- LABOUR &amp; SERVICES --
                        </td>
                    </tr>
                    <?php foreach ($groupedItems['labour'] as $item): ?>
                        <tr>
                            <td class="col-desc">
                                <?= htmlspecialchars($item['description']) ?>
                                <?php if ($hasGst && (float) $item['tax_rate'] > 0): ?>
                                    <div class="col-sub">GST @ <?= number_format($item['tax_rate'], 0) ?>%</div>
                                <?php endif; ?>
                            </td>
                            <td class="col-qty"><?= rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') ?></td>
                            <td class="col-rate">₹<?= number_format($item['unit_price'], 2) ?></td>
                            <td class="col-total">₹<?= number_format($item['total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="receipt-divider-dashed"></div>

        <!-- Totals -->
        <div class="receipt-totals">
            <?php if (!empty($groupedItems['parts'])): ?>
                <div class="t-row">
                    <span>Parts Total:</span>
                    <span>₹<?= number_format($groupedItems['parts_total'], 2) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($groupedItems['labour'])): ?>
                <div class="t-row">
                    <span>Labour Total:</span>
                    <span>₹<?= number_format($groupedItems['labour_total'], 2) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($hasGst): ?>
                <?php if ($isInterState): ?>
                    <div class="t-row">
                        <span>IGST:</span>
                        <span>₹<?= number_format($invoice['tax_amount'], 2) ?></span>
                    </div>
                <?php else: ?>
                    <div class="t-row">
                        <span>CGST:</span>
                        <span>₹<?= number_format($invoice['tax_amount'] / 2, 2) ?></span>
                    </div>
                    <div class="t-row">
                        <span>SGST:</span>
                        <span>₹<?= number_format($invoice['tax_amount'] / 2, 2) ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="t-row grand-total">
                <span>TOTAL:</span>
                <span>₹<?= number_format($invoice['total'], 2) ?></span>
            </div>
            <?php if ((float) $invoice['amount_paid'] > 0.009): ?>
                <div class="t-row">
                    <span>Amount Paid:</span>
                    <span>₹<?= number_format($invoice['amount_paid'], 2) ?></span>
                </div>
                <div class="t-row balance-due">
                    <span>Balance Due:</span>
                    <span>₹<?= number_format($balanceDue, 2) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div style="font-size:9.5px; margin:6px 0; font-style:italic;">
            Amount in Words: <?= htmlspecialchars(InvoiceService::numberToWordsInr((float) $invoice['total'])) ?>
        </div>

        <!-- Payments received -->
        <?php if (!empty($payments)): ?>
            <div class="receipt-divider-dashed"></div>
            <div class="receipt-payments">
                <div class="receipt-payments-title">Payments Log:</div>
                <?php foreach ($payments as $payment): ?>
                    <div class="receipt-row">
                        <span class="r-label" style="text-transform:capitalize;">
                            <?= htmlspecialchars(str_replace('_', ' ', $payment['method'])) ?> (<?= htmlspecialchars(date('d/m/y', strtotime($payment['created_at']))) ?>)
                            <?= $payment['reference_no'] ? ' · ' . htmlspecialchars($payment['reference_no']) : '' ?>
                        </span>
                        <span class="r-val">₹<?= number_format($payment['amount'], 2) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="receipt-divider-solid"></div>

        <!-- Footer -->
        <div class="receipt-footer">
            <div class="thank-you">THANK YOU</div>
            <div>Drive Safe &amp; Visit Again!</div>
            <div class="disclaimer">
                Goods once sold / services rendered will not be taken back. Computer generated receipt.
            </div>
        </div>

    </div>

<?php else: ?>

    <!-- ====================================================================
         STANDARD A4 INVOICE LAYOUT (STRUCTURED PARTS & LABOUR)
         ==================================================================== -->
    <div class="sheet">

        <div class="letterhead">
            <div>
                <?php if (!empty($invoice['organization_logo_url'])): ?>
                    <img class="org-logo" src="<?= htmlspecialchars($invoice['organization_logo_url']) ?>" alt="<?= htmlspecialchars($invoice['organization_name']) ?>">
                <?php endif; ?>
                <div class="org-name"><?= htmlspecialchars($invoice['organization_name']) ?></div>
                <div class="org-meta">
                    <?php if ($invoice['organization_address']): ?>
                        <?= nl2br(htmlspecialchars($invoice['organization_address'])) ?><br>
                    <?php endif; ?>
                    <?php if ($invoice['organization_phone']): ?>
                        Phone: <?= htmlspecialchars($invoice['organization_phone']) ?><br>
                    <?php endif; ?>
                    <?php if ($invoice['organization_tax_number'] && $hasGst): ?>
                        <?= htmlspecialchars($invoice['organization_tax_label']) ?>: <?= htmlspecialchars($invoice['organization_tax_number']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="doc-title">
                <h1>Tax Invoice</h1>
                <div class="job-no"><?= htmlspecialchars($invoice['invoice_no']) ?></div>
                <div class="job-date">Dated <?= htmlspecialchars(date('d M Y', strtotime($invoice['created_at']))) ?></div>
                <div class="status-stamp status-stamp-<?= htmlspecialchars($invoice['status']) ?>">
                    <?= htmlspecialchars($statusLabels[$invoice['status']] ?? $invoice['status']) ?>
                </div>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-block">
                <h2>Billed to</h2>
                <div class="info-row"><span class="label">Name</span><span class="value"><?= htmlspecialchars($invoice['customer_name']) ?></span></div>
                <div class="info-row"><span class="label">Phone</span><span class="value"><?= htmlspecialchars($invoice['customer_phone']) ?></span></div>
                <?php if ($invoice['customer_address']): ?>
                    <div class="info-row"><span class="label">Address</span><span class="value"><?= htmlspecialchars($invoice['customer_address']) ?></span></div>
                <?php endif; ?>
                <?php if ($invoice['customer_gstin']): ?>
                    <div class="info-row"><span class="label">GSTIN</span><span class="value"><?= htmlspecialchars($invoice['customer_gstin']) ?></span></div>
                <?php endif; ?>
            </div>
            <div class="info-block">
                <h2>Vehicle &amp; job reference</h2>
                <div class="info-row"><span class="label">Job card</span><span class="value"><?= htmlspecialchars($invoice['job_no']) ?></span></div>
                <div class="info-row"><span class="label">Registration</span><span class="value"><?= htmlspecialchars($invoice['registration_no']) ?></span></div>
                <div class="info-row"><span class="label">Make / Model</span><span class="value"><?= htmlspecialchars($invoice['make'] . ' ' . $invoice['model']) ?></span></div>
            </div>
        </div>

        <!-- SECTION 1: PARTS TABLE (IF PARTS EXIST) -->
        <?php if (!empty($groupedItems['parts'])): ?>
            <div class="section" style="margin-bottom:16px;">
                <table style="border:1px solid #000000; border-collapse:collapse; width:100%;">
                    <thead>
                        <tr>
                            <th style="width:32px; text-align:center; border:1px solid #000000; color:#000000; font-weight:800;">#</th>
                            <th style="border:1px solid #000000; color:#000000; font-weight:800;">Part Name</th>
                            <th style="border:1px solid #000000; color:#000000; font-weight:800;">Description</th>
                            <th class="num" style="border:1px solid #000000; color:#000000; font-weight:800;">Quantity</th>
                            <th class="num" style="border:1px solid #000000; color:#000000; font-weight:800;">Unit Price (₹)</th>
                            <th class="num" style="border:1px solid #000000; color:#000000; font-weight:800;">Amount (₹)</th>
                            <th class="num" style="border:1px solid #000000; color:#000000; font-weight:800;">Parts Total (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $pIdx = 1; foreach ($groupedItems['parts'] as $item): ?>
                            <?php
                                $lineTaxable = (float) $item['quantity'] * (float) $item['unit_price'];
                                $lineTotal = (float) $item['total'];
                            ?>
                            <tr>
                                <td style="text-align:center; border:1px solid #000000; color:#000000;"><?= $pIdx++ ?></td>
                                <td style="border:1px solid #000000; color:#000000;"><strong><?= htmlspecialchars($item['description']) ?></strong></td>
                                <td style="border:1px solid #000000; color:#000000; font-size:11px;"><?= $item['hsn_sac_code'] ? 'HSN ' . htmlspecialchars($item['hsn_sac_code']) : 'Part' ?></td>
                                <td class="num" style="border:1px solid #000000; color:#000000;"><?= rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.') ?></td>
                                <td class="num" style="border:1px solid #000000; color:#000000;">₹<?= number_format((float)$item['unit_price'], 2) ?></td>
                                <td class="num" style="border:1px solid #000000; color:#000000;">₹<?= number_format($lineTaxable, 2) ?></td>
                                <td class="num" style="border:1px solid #000000; color:#000000; font-weight:700;">₹<?= number_format($lineTotal, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="display:flex; justify-content:flex-end; margin-top:4px;">
                    <table style="width:280px; border:1px solid #000000; border-collapse:collapse; font-size:11.5px;">
                        <tr>
                            <td style="padding:4px 8px; border:1px solid #000000; font-weight:600; color:#000000;">Taxable Value</td>
                            <td class="num" style="padding:4px 8px; border:1px solid #000000; font-weight:700; color:#000000;">₹<?= number_format($groupedItems['parts_taxable'], 2) ?></td>
                        </tr>
                        <tr>
                            <td style="padding:4px 8px; border:1px solid #000000; font-weight:600; color:#000000;">Discount Total</td>
                            <td class="num" style="padding:4px 8px; border:1px solid #000000; color:#000000;">₹0.00</td>
                        </tr>
                        <tr>
                            <td style="padding:5px 8px; border:1.5px solid #000000; font-weight:800; color:#000000;">Parts Total</td>
                            <td class="num" style="padding:5px 8px; border:1.5px solid #000000; font-weight:800; color:#000000;">₹<?= number_format($groupedItems['parts_total'], 2) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- SECTION 2: LABOUR & SERVICES TABLE -->
        <?php if (!empty($groupedItems['labour'])): ?>
            <div class="section" style="margin-bottom:16px;">
                <table style="border:1px solid #000000; border-collapse:collapse; width:100%;">
                    <thead>
                        <tr>
                            <th style="width:32px; text-align:center; border:1px solid #000000; color:#000000; font-weight:800;">#</th>
                            <th style="border:1px solid #000000; color:#000000; font-weight:800;">Service</th>
                            <th style="border:1px solid #000000; color:#000000; font-weight:800;">Description</th>
                            <th class="num" style="border:1px solid #000000; color:#000000; font-weight:800;">Quantity</th>
                            <th class="num" style="border:1px solid #000000; color:#000000; font-weight:800;">Unit Price (₹)</th>
                            <th class="num" style="border:1px solid #000000; color:#000000; font-weight:800;">Amount (₹)</th>
                            <th class="num" style="border:1px solid #000000; color:#000000; font-weight:800;">Labour Total (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $lIdx = count($groupedItems['parts']) + 1; foreach ($groupedItems['labour'] as $item): ?>
                            <?php
                                $lineTaxable = (float) $item['quantity'] * (float) $item['unit_price'];
                                $lineTotal = (float) $item['total'];
                            ?>
                            <tr>
                                <td style="text-align:center; border:1px solid #000000; color:#000000;"><?= $lIdx++ ?></td>
                                <td style="border:1px solid #000000; color:#000000;"><strong><?= htmlspecialchars($item['description']) ?></strong></td>
                                <td style="border:1px solid #000000; color:#000000; font-size:11px;"><?= $item['hsn_sac_code'] ? 'SAC ' . htmlspecialchars($item['hsn_sac_code']) : 'Labour' ?></td>
                                <td class="num" style="border:1px solid #000000; color:#000000;"><?= rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.') ?></td>
                                <td class="num" style="border:1px solid #000000; color:#000000;">₹<?= number_format((float)$item['unit_price'], 2) ?></td>
                                <td class="num" style="border:1px solid #000000; color:#000000;">₹<?= number_format($lineTaxable, 2) ?></td>
                                <td class="num" style="border:1px solid #000000; color:#000000; font-weight:700;">₹<?= number_format($lineTotal, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="display:flex; justify-content:flex-end; margin-top:4px;">
                    <table style="width:280px; border:1px solid #000000; border-collapse:collapse; font-size:11.5px;">
                        <tr>
                            <td style="padding:4px 8px; border:1px solid #000000; font-weight:600; color:#000000;">Taxable Value</td>
                            <td class="num" style="padding:4px 8px; border:1px solid #000000; font-weight:700; color:#000000;">₹<?= number_format($groupedItems['labour_taxable'], 2) ?></td>
                        </tr>
                        <tr>
                            <td style="padding:4px 8px; border:1px solid #000000; font-weight:600; color:#000000;">Discount Total</td>
                            <td class="num" style="padding:4px 8px; border:1px solid #000000; color:#000000;">₹0.00</td>
                        </tr>
                        <tr>
                            <td style="padding:5px 8px; border:1.5px solid #000000; font-weight:800; color:#000000;">Labour Total</td>
                            <td class="num" style="padding:5px 8px; border:1.5px solid #000000; font-weight:800; color:#000000;">₹<?= number_format($groupedItems['labour_total'], 2) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- SECTION 3: GRAND TOTALS BLOCK -->
        <div style="display:flex; justify-content:flex-end; margin-top:14px;">
            <table style="width:300px; border:1.5px solid #000000; border-collapse:collapse; font-size:12.5px;">
                <?php if (!empty($groupedItems['parts'])): ?>
                    <tr>
                        <td style="padding:5px 10px; border:1px solid #000000; font-weight:600; color:#000000;">Parts Total</td>
                        <td class="num" style="padding:5px 10px; border:1px solid #000000; color:#000000;">₹<?= number_format($groupedItems['parts_total'], 2) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if (!empty($groupedItems['labour'])): ?>
                    <tr>
                        <td style="padding:5px 10px; border:1px solid #000000; font-weight:600; color:#000000;">Labour Total</td>
                        <td class="num" style="padding:5px 10px; border:1px solid #000000; color:#000000;">₹<?= number_format($groupedItems['labour_total'], 2) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($hasGst): ?>
                    <?php if ($isInterState): ?>
                        <tr>
                            <td style="padding:5px 10px; border:1px solid #000000; color:#000000;">IGST</td>
                            <td class="num" style="padding:5px 10px; border:1px solid #000000; color:#000000;">₹<?= number_format($invoice['tax_amount'], 2) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td style="padding:5px 10px; border:1px solid #000000; color:#000000;">CGST</td>
                            <td class="num" style="padding:5px 10px; border:1px solid #000000; color:#000000;">₹<?= number_format($invoice['tax_amount'] / 2, 2) ?></td>
                        </tr>
                        <tr>
                            <td style="padding:5px 10px; border:1px solid #000000; color:#000000;">SGST</td>
                            <td class="num" style="padding:5px 10px; border:1px solid #000000; color:#000000;">₹<?= number_format($invoice['tax_amount'] / 2, 2) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
                <tr style="font-weight:800; font-size:13.5px;">
                    <td style="padding:7px 10px; border:1.5px solid #000000; color:#000000;">Grand Total</td>
                    <td class="num" style="padding:7px 10px; border:1.5px solid #000000; color:#000000;">₹<?= number_format($invoice['total'], 2) ?></td>
                </tr>
                <tr>
                    <td style="padding:5px 10px; border:1px solid #000000; color:#000000;">Round off</td>
                    <td class="num" style="padding:5px 10px; border:1px solid #000000; color:#000000;">₹<?= number_format($invoice['total'], 2) ?></td>
                </tr>
                <tr style="font-weight:800;">
                    <td style="padding:6px 10px; border:1.5px solid #000000; color:#000000;">Balance</td>
                    <td class="num" style="padding:6px 10px; border:1.5px solid #000000; color:#000000;">₹<?= number_format($balanceDue, 2) ?></td>
                </tr>
            </table>
        </div>

        <!-- AMOUNT IN WORDS -->
        <div style="margin-top:16px; padding:8px 12px; border:1.5px solid #000000; font-size:12.5px; color:#000000;">
            <strong style="text-transform:uppercase; letter-spacing:0.04em;">Amount ( in Words ):</strong> <?= htmlspecialchars(InvoiceService::numberToWordsInr((float) $invoice['total'])) ?>
        </div>

        <?php if ($hasGst && (!$invoice['organization_state'] || !$invoice['customer_state'])): ?>
            <p class="tax-note" style="margin-top:8px; color:#000000;">
                GST shown as CGST + SGST, assuming an intra-state sale — <?= !$invoice['organization_state'] ? "your organization's" : "this customer's" ?> state isn't set, so this couldn't be verified.
            </p>
        <?php endif; ?>

        <?php if (!empty($payments)): ?>
            <div class="section" style="margin-top:16px;">
                <h2 style="color:#000000; font-weight:800;">Payments received</h2>
                <table style="border:1px solid #000000; border-collapse:collapse; width:100%;">
                    <thead>
                        <tr>
                            <th style="border:1px solid #000000; color:#000000; font-weight:800;">Date</th>
                            <th style="border:1px solid #000000; color:#000000; font-weight:800;">Method</th>
                            <th style="border:1px solid #000000; color:#000000; font-weight:800;">Reference</th>
                            <th class="num" style="border:1px solid #000000; color:#000000; font-weight:800;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td style="border:1px solid #000000; color:#000000;"><?= htmlspecialchars(date('d M Y', strtotime($payment['created_at']))) ?></td>
                                <td style="border:1px solid #000000; color:#000000; text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $payment['method'])) ?></td>
                                <td style="border:1px solid #000000; color:#000000;"><?= htmlspecialchars($payment['reference_no'] ?? '—') ?></td>
                                <td class="num" style="border:1px solid #000000; color:#000000;">₹<?= number_format($payment['amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="declaration" style="border-top:1px solid #000000; color:#000000;">
            This is a computer-generated invoice and does not require a physical signature. Goods once sold / services once
            rendered will not be taken back. Any dispute regarding this invoice must be raised within 7 days of issue.
        </div>

        <div class="signatures">
            <div class="signature-block"></div>
            <div class="signature-block">
                <div class="signature-line" style="border-top:1.5px solid #000000; color:#000000; font-weight:600;">For <?= htmlspecialchars($invoice['organization_name']) ?> — Authorized Signatory</div>
            </div>
        </div>

    </div>

<?php endif; ?>

<script>
const CURRENT_FORMAT = <?= json_encode($format) ?>;
const STORAGE_KEY = 'garageos_invoice_print_format';

function updateDefaultButtonState() {
    const saved = localStorage.getItem(STORAGE_KEY) || 'a4';
    const btn = document.getElementById('set-default-btn');
    if (!btn) return;
    if (saved === CURRENT_FORMAT) {
        btn.classList.add('is-default');
        btn.innerHTML = '✓ Default Format';
    } else {
        btn.classList.remove('is-default');
        btn.innerHTML = 'Set as Default';
    }
}

function setDefaultFormat(format) {
    localStorage.setItem(STORAGE_KEY, format);
    updateDefaultButtonState();
    
    const btn = document.getElementById('set-default-btn');
    if (btn) {
        btn.innerHTML = '✓ Saved as Default!';
        setTimeout(updateDefaultButtonState, 1500);
    }
}

function recordFormatChoice(format) {
    if (!localStorage.getItem(STORAGE_KEY)) {
        localStorage.setItem(STORAGE_KEY, format);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    updateDefaultButtonState();
});
</script>

</body>
</html>
