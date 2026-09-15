<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Domain/Gst.php';

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

$statement = $pdo->prepare("
    SELECT i.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
           c.gstin AS customer_gstin, c.state AS customer_state,
           jc.job_no, v.registration_no, v.make, v.model,
           o.name AS organization_name, o.phone AS organization_phone, o.address AS organization_address,
           o.tax_label AS organization_tax_label, o.tax_number AS organization_tax_number, o.state AS organization_state
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

$statement = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = :invoice_id ORDER BY created_at");
$statement->execute(['invoice_id' => $invoiceId]);
$payments = $statement->fetchAll(PDO::FETCH_ASSOC);

$balanceDue = (float) $invoice['total'] - (float) $invoice['amount_paid'];
$isInterState = gst_is_inter_state($invoice['organization_state'], $invoice['customer_state']);

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

    <title>GarageOS — <?= htmlspecialchars($invoice['invoice_no']) ?> — Invoice</title>

    <link rel="stylesheet" href="/css/print.css">
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
            <div class="org-name"><?= htmlspecialchars($invoice['organization_name']) ?></div>
            <div class="org-meta">
                <?php if ($invoice['organization_address']): ?>
                    <?= nl2br(htmlspecialchars($invoice['organization_address'])) ?><br>
                <?php endif; ?>
                <?php if ($invoice['organization_phone']): ?>
                    Phone: <?= htmlspecialchars($invoice['organization_phone']) ?><br>
                <?php endif; ?>
                <?php if ($invoice['organization_tax_number']): ?>
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

    <div class="section">
        <table>
            <tr><th>Description</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">GST</th><th class="num">Total</th></tr>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['description']) ?></td>
                    <td><?= htmlspecialchars($item['hsn_sac_code'] ?? '—') ?></td>
                    <td class="num"><?= rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') ?></td>
                    <td class="num">₹<?= number_format($item['unit_price'], 2) ?></td>
                    <td class="num"><?= number_format($item['tax_rate'], 0) ?>%</td>
                    <td class="num">₹<?= number_format($item['total'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="totals-box">
        <div class="totals-row">
            <span>Subtotal</span><span class="num">₹<?= number_format($invoice['subtotal'], 2) ?></span>
        </div>
        <?php if ($isInterState): ?>
            <div class="totals-row">
                <span>IGST</span><span class="num">₹<?= number_format($invoice['tax_amount'], 2) ?></span>
            </div>
        <?php else: ?>
            <div class="totals-row">
                <span>CGST</span><span class="num">₹<?= number_format($invoice['tax_amount'] / 2, 2) ?></span>
            </div>
            <div class="totals-row">
                <span>SGST</span><span class="num">₹<?= number_format($invoice['tax_amount'] / 2, 2) ?></span>
            </div>
        <?php endif; ?>
        <div class="totals-row totals-grand">
            <span>Total</span><span class="num">₹<?= number_format($invoice['total'], 2) ?></span>
        </div>
        <?php if ((float) $invoice['amount_paid'] > 0.009): ?>
            <div class="totals-row">
                <span>Amount paid</span><span class="num">₹<?= number_format($invoice['amount_paid'], 2) ?></span>
            </div>
            <div class="totals-row <?= $balanceDue > 0.009 ? 'totals-balance' : 'totals-settled' ?>">
                <span>Balance due</span><span class="num">₹<?= number_format($balanceDue, 2) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$invoice['organization_state'] || !$invoice['customer_state']): ?>
        <p class="tax-note">
            GST shown as CGST + SGST, assuming an intra-state sale — <?= !$invoice['organization_state'] ? "your organization's" : "this customer's" ?> state isn't set, so this couldn't be verified.
        </p>
    <?php endif; ?>

    <?php if (!empty($payments)): ?>
        <div class="section">
            <h2>Payments received</h2>
            <table>
                <tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('d M Y', strtotime($payment['created_at']))) ?></td>
                        <td style="text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $payment['method'])) ?></td>
                        <td><?= htmlspecialchars($payment['reference_no'] ?? '—') ?></td>
                        <td class="num">₹<?= number_format($payment['amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <div class="declaration">
        This is a computer-generated invoice and does not require a physical signature. Goods once sold / services once
        rendered will not be taken back. Any dispute regarding this invoice must be raised within 7 days of issue.
    </div>

    <div class="signatures">
        <div class="signature-block"></div>
        <div class="signature-block">
            <div class="signature-line">For <?= htmlspecialchars($invoice['organization_name']) ?> — Authorized Signatory</div>
        </div>
    </div>

</div>

</body>
</html>
