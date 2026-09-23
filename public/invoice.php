<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Gst.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

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
    SELECT i.*, c.name AS customer_name, c.phone AS customer_phone, c.gstin AS customer_gstin,
           c.state AS customer_state,
           jc.job_no, v.registration_no, v.make, v.model,
           o.name AS organization_name, o.phone AS organization_phone, o.address AS organization_address,
           o.tax_label AS organization_tax_label, o.tax_number AS organization_tax_number,
           o.default_tax_rate AS organization_default_tax_rate, o.state AS organization_state
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

$error = null;
$errorAction = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'invoices.manage');

    $action = $_POST['action'] ?? 'record_payment';
    $errorAction = $action;

    if ($action === 'update_gst') {

        // Only allowed before any payment exists — an invoice's GST must
        // never change retroactively once money has actually been
        // collected against it (same principle as invoice_items already
        // snapshotting their rate at generation time, see
        // database/migrations/030_add_gst_fields.sql).
        $gstRate = $_POST['gst_rate'] ?? '';

        // Only two choices ever make sense here: the organization's own
        // GST rate (set once by the Owner in Settings), or zero for an
        // exempt case — not the full slab list. This also means a GST
        // slab change next year only ever needs updating in one place.
        $validRates = array_unique([0.0, (float) $invoice['organization_default_tax_rate']]);

        if ((float) $invoice['amount_paid'] > 0.009) {
            $error = 'GST can no longer be adjusted — a payment has already been recorded against this invoice.';
        } elseif ($gstRate === '' || !in_array((float) $gstRate, $validRates, true)) {
            $error = 'Choose a valid GST rate.';
        } else {

            $gstRate = (float) $gstRate;

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare("SELECT id, quantity, unit_price FROM invoice_items WHERE invoice_id = :invoice_id");
                $statement->execute(['invoice_id' => $invoiceId]);
                $lineItems = $statement->fetchAll(PDO::FETCH_ASSOC);

                $updateItem = $pdo->prepare("
                    UPDATE invoice_items SET tax_rate = :tax_rate, total = :total WHERE id = :id
                ");

                $subtotal = 0;
                $taxAmount = 0;

                foreach ($lineItems as $line) {
                    $preTax = (float) $line['quantity'] * (float) $line['unit_price'];
                    $lineTax = $preTax * ($gstRate / 100);

                    $updateItem->execute([
                        'tax_rate' => $gstRate,
                        'total' => $preTax + $lineTax,
                        'id' => $line['id']
                    ]);

                    $subtotal += $preTax;
                    $taxAmount += $lineTax;
                }

                $statement = $pdo->prepare("
                    UPDATE invoices
                    SET subtotal = :subtotal, tax_amount = :tax_amount, total = :total, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $statement->execute([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total' => $subtotal + $taxAmount,
                    'id' => $invoiceId
                ]);

                log_audit_event(
                    $pdo, $user, 'update', 'invoice', $invoiceId,
                    "Changed GST rate to {$gstRate}% on invoice {$invoice['invoice_no']}"
                );

                $pdo->commit();

                header('Location: /invoice.php?id=' . $invoiceId);
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }

    } else {

        $method = $_POST['method'] ?? '';
        $amount = (float) ($_POST['amount'] ?? 0);
        $referenceNo = trim($_POST['reference_no'] ?? '');

        $balanceDue = (float) $invoice['total'] - (float) $invoice['amount_paid'];

        if ($amount <= 0 || $amount > $balanceDue + 0.01) {
            $error = 'Enter a valid amount up to the balance due (₹' . number_format($balanceDue, 2) . ').';
        } else {

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare("
                    INSERT INTO payments (organization_id, branch_id, invoice_id, method, amount, reference_no, received_by)
                    VALUES (:organization_id, :branch_id, :invoice_id, :method, :amount, :reference_no, :received_by)
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'branch_id' => $invoice['branch_id'],
                    'invoice_id' => $invoiceId,
                    'method' => $method,
                    'amount' => $amount,
                    'reference_no' => $referenceNo ?: null,
                    'received_by' => $user['id']
                ]);

                $newAmountPaid = (float) $invoice['amount_paid'] + $amount;
                $newStatus = $newAmountPaid >= (float) $invoice['total'] - 0.01 ? 'paid' : 'partial';

                $statement = $pdo->prepare("
                    UPDATE invoices
                    SET amount_paid = :amount_paid, status = :status, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $statement->execute([
                    'amount_paid' => $newAmountPaid,
                    'status' => $newStatus,
                    'id' => $invoiceId
                ]);

                log_audit_event(
                    $pdo, $user, 'create', 'payment', $invoiceId,
                    'Recorded payment of ₹' . number_format($amount, 2) . " on invoice {$invoice['invoice_no']}"
                );

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

$statement = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = :invoice_id ORDER BY id");
$statement->execute(['invoice_id' => $invoiceId]);
$items = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = :invoice_id ORDER BY created_at");
$statement->execute(['invoice_id' => $invoiceId]);
$payments = $statement->fetchAll(PDO::FETCH_ASSOC);

$balanceDue = (float) $invoice['total'] - (float) $invoice['amount_paid'];
$isInterState = gst_is_inter_state($invoice['organization_state'], $invoice['customer_state']);

// A per-line tax amount can never be negative, so tax_amount == 0 is
// guaranteed to mean every line was billed at 0% — there's no mixed-rate
// case where this could be wrong. Drives every GST-related element on
// this page: the org's GST number, the GST column, and the CGST/SGST/
// IGST rows all disappear together when an invoice genuinely has none.
$hasGst = (float) $invoice['tax_amount'] > 0.009;

$invoiceStatusIcons = [
    'unpaid' => 'alert-triangle',
    'partial' => 'wallet',
    'paid' => 'check-circle',
    'void' => 'x'
];

$activeNav = 'job_cards';
$topbarTitle = $invoice['invoice_no'];

// WhatsApp's "click to chat" links can only pre-fill a text message —
// there's no way to auto-attach a file through them, and this app has
// no hosted, publicly-reachable PDF to attach anyway (Print Invoice is
// a browser-printed HTML page, not a generated file). So this opens
// WhatsApp with the invoice summary ready to send; staff still tap
// Send themselves. The phone field is free-text (no format enforced —
// see public/customers.php), so normalize the common ways an Indian
// mobile number ends up stored: a bare 10-digit number, one with a
// leading trunk "0" some people still type out of landline habit, or
// one that already includes the 91 country code.
$customerPhoneDigits = preg_replace('/\D+/', '', $invoice['customer_phone'] ?? '');
if (strlen($customerPhoneDigits) === 11 && $customerPhoneDigits[0] === '0') {
    $customerPhoneDigits = substr($customerPhoneDigits, 1);
}
if (strlen($customerPhoneDigits) === 10) {
    $customerPhoneDigits = '91' . $customerPhoneDigits;
}
$hasWhatsappNumber = strlen($customerPhoneDigits) === 12 && str_starts_with($customerPhoneDigits, '91');

if ($hasWhatsappNumber) {
    $whatsappMessage = sprintf(
        "Hi %s, here's your invoice from %s.\n\nInvoice: %s\nJob card: %s — %s %s (%s)\nTotal: ₹%s\nBalance due: ₹%s\n\nThank you for choosing %s!",
        $invoice['customer_name'],
        $invoice['organization_name'],
        $invoice['invoice_no'],
        $invoice['job_no'],
        $invoice['make'],
        $invoice['model'],
        $invoice['registration_no'],
        number_format((float) $invoice['total'], 2),
        number_format($balanceDue, 2),
        $invoice['organization_name']
    );
    $whatsappUrl = 'https://wa.me/' . $customerPhoneDigits . '?text=' . rawurlencode($whatsappMessage);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — <?= htmlspecialchars($invoice['invoice_no']) ?></title>

    <link rel="stylesheet" href="/css/app.css">
    <?= favicon_tag($user['organization_logo_url'] ?? null) ?>
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
                        <?= htmlspecialchars($invoice['invoice_no']) ?>
                        <span class="badge badge-<?= htmlspecialchars($invoice['status']) ?>" style="margin-left:10px; vertical-align:middle;">
                            <?= icon($invoiceStatusIcons[$invoice['status']] ?? 'receipt', 12) ?>
                            <?= htmlspecialchars($invoice['status']) ?>
                        </span>
                    </h1>
                    <p class="page-description">
                        Job card <?= htmlspecialchars($invoice['job_no']) ?> ·
                        <?= htmlspecialchars($invoice['registration_no']) ?> —
                        <?= htmlspecialchars($invoice['make'] . ' ' . $invoice['model']) ?>
                    </p>
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:10px;">
                    <a href="/invoice-print.php?id=<?= (int) $invoice['id'] ?>" target="_blank" class="button secondary"><?= icon('printer', 16) ?> Print Invoice</a>
                    <?php if ($hasWhatsappNumber): ?>
                        <button
                            type="button"
                            id="whatsapp-share-btn"
                            class="button secondary"
                            data-invoice-url="/invoice-print.php?id=<?= (int) $invoice['id'] ?>"
                            data-wa-number="<?= htmlspecialchars($customerPhoneDigits) ?>"
                            data-wa-message="<?= htmlspecialchars($whatsappMessage) ?>"
                            data-filename="<?= htmlspecialchars($invoice['invoice_no']) ?>.pdf"
                        ><?= brand_icon('whatsapp', 16) ?> Share via WhatsApp</button>
                    <?php else: ?>
                        <span class="button secondary" aria-disabled="true" title="No phone number on file for this customer"><?= brand_icon('whatsapp', 16) ?> Share via WhatsApp</span>
                    <?php endif; ?>
                    <a href="/job-card.php?id=<?= (int) $invoice['job_card_id'] ?>" class="button secondary"><?= icon('arrow-left', 16) ?> Back to job card</a>
                </div>
            </div>

            <div class="content-grid content-grid-aside">

                <div class="card">
                    <div class="card-header" style="flex-direction:column; align-items:flex-start; gap:4px;">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                            Tax Invoice — <?= htmlspecialchars($invoice['organization_name']) ?>
                        </div>
                        <div style="font-weight:400; color:var(--muted); font-size:12.5px;">
                            <?php if ($invoice['organization_tax_number'] && $hasGst): ?>
                                <?= htmlspecialchars($invoice['organization_tax_label']) ?>IN: <?= htmlspecialchars($invoice['organization_tax_number']) ?> ·
                            <?php endif; ?>
                            Billed to <?= htmlspecialchars($invoice['customer_name']) ?>
                            <?php if ($invoice['customer_gstin']): ?>
                                (GSTIN: <?= htmlspecialchars($invoice['customer_gstin']) ?>)
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Description</th><th>HSN/SAC</th><th>Qty</th><th>Unit price</th><?php if ($hasGst): ?><th>GST</th><?php endif; ?><th>Total</th></tr>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['description']) ?></td>
                                        <td><?= htmlspecialchars($item['hsn_sac_code'] ?? '—') ?></td>
                                        <td class="num"><?= rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') ?></td>
                                        <td class="num">₹<?= number_format($item['unit_price'], 2) ?></td>
                                        <?php if ($hasGst): ?><td class="num"><?= number_format($item['tax_rate'], 0) ?>%</td><?php endif; ?>
                                        <td class="num">₹<?= number_format($item['total'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <div style="padding:18px 20px; border-top:1px solid var(--border);">
                            <div class="summary-row" style="display:flex; justify-content:space-between; padding:5px 0;">
                                <span>Subtotal</span><strong class="num">₹<?= number_format($invoice['subtotal'], 2) ?></strong>
                            </div>
                            <?php if ($hasGst): ?>
                                <?php if ($isInterState): ?>
                                    <div class="summary-row" style="display:flex; justify-content:space-between; padding:5px 0;">
                                        <span>IGST</span><strong class="num">₹<?= number_format($invoice['tax_amount'], 2) ?></strong>
                                    </div>
                                <?php else: ?>
                                    <div class="summary-row" style="display:flex; justify-content:space-between; padding:5px 0;">
                                        <span>CGST</span><strong class="num">₹<?= number_format($invoice['tax_amount'] / 2, 2) ?></strong>
                                    </div>
                                    <div class="summary-row" style="display:flex; justify-content:space-between; padding:5px 0;">
                                        <span>SGST</span><strong class="num">₹<?= number_format($invoice['tax_amount'] / 2, 2) ?></strong>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="summary-row" style="display:flex; justify-content:space-between; padding:10px 0; border-top:1px solid var(--border); margin-top:6px; font-size:18px;">
                                <span>Total</span><strong class="num">₹<?= number_format($invoice['total'], 2) ?></strong>
                            </div>
                            <?php if ($hasGst && (!$invoice['organization_state'] || !$invoice['customer_state'])): ?>
                                <p class="result-meta" style="margin-top:10px;">
                                    GST shown as CGST + SGST, assuming an intra-state sale — <?= !$invoice['organization_state'] ? 'your organization\'s' : 'this customer\'s' ?> state isn't set, so this couldn't be verified. Set it in <?= !$invoice['organization_state'] ? '<a href="/settings.php">Settings</a>' : '<a href="/customers.php?edit=' . (int) $invoice['customer_id'] . '">Customers</a>' ?> to switch to IGST automatically for inter-state sales.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="stack">

                    <div class="card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <span class="icon-badge"><?= icon('wallet', 15) ?></span>
                                Balance due
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value">₹<?= number_format($balanceDue, 2) ?></div>
                            <p class="stat-meta">of ₹<?= number_format($invoice['total'], 2) ?> total</p>
                        </div>
                    </div>

                    <?php if (user_can($user, 'invoices.manage') && (float) $invoice['amount_paid'] <= 0.009): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                                    Adjust GST
                                </div>
                            </div>
                            <div class="card-body">

                                <?php if ($error && $errorAction === 'update_gst'): ?>
                                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                                <?php endif; ?>

                                <form method="POST" action="">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_gst">
                                    <div class="form-grid single">
                                        <div class="form-field">
                                            <label>GST rate</label>
                                            <?php $orgDefaultRate = (float) $invoice['organization_default_tax_rate']; ?>
                                            <select name="gst_rate">
                                                <option value="<?= $orgDefaultRate ?>" selected><?= rtrim(rtrim(number_format($orgDefaultRate, 2), '0'), '.') ?>% (your organization's rate)</option>
                                                <?php if ($orgDefaultRate !== 0.0): ?>
                                                    <option value="0">No GST (0%)</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="button secondary"><?= icon('check', 16) ?> Update GST</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($balanceDue > 0.009): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('check-circle', 15) ?></span>
                                    Record payment
                                </div>
                            </div>
                            <div class="card-body">

                                <?php if ($error && $errorAction === 'record_payment'): ?>
                                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                                <?php endif; ?>

                                <form method="POST" action="">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="record_payment">
                                    <div class="form-grid single">
                                        <div class="form-field">
                                            <label>Method</label>
                                            <select name="method" required>
                                                <option value="cash">Cash</option>
                                                <option value="upi">UPI</option>
                                                <option value="card">Card</option>
                                                <option value="bank_transfer">Bank transfer</option>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label>Amount</label>
                                            <input type="number" name="amount" min="0.01" max="<?= $balanceDue ?>" step="0.01" value="<?= $balanceDue ?>" required>
                                        </div>
                                        <div class="form-field">
                                            <label>Reference no. (optional)</label>
                                            <input type="text" name="reference_no">
                                        </div>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="button"><?= icon('check', 16) ?> Record payment</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($payments)): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                                    Payments received
                                </div>
                            </div>
                            <div class="card-body" style="padding:0;">
                                <div class="table-wrap">
                                    <table class="data-table">
                                        <tr><th>Method</th><th>Amount</th></tr>
                                        <?php foreach ($payments as $payment): ?>
                                            <tr>
                                                <td style="text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $payment['method'])) ?></td>
                                                <td class="num">₹<?= number_format($payment['amount'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

        </section>

    </main>

</div>

<?php if ($hasWhatsappNumber): ?>
    <!-- Only loaded here (not app-wide) — these exist purely to turn the
         printable invoice into a real PDF for the WhatsApp share button,
         see public/js/invoice-share.js. Vendored locally rather than
         from a CDN, matching this app's no-external-runtime-dependency
         convention (config/database.php's load_env() comment).

         Each ?v= is that file's own mtime, so a deploy that changes one
         of these automatically busts any cached copy on the visitor's
         phone — this feature has an active-development track record of
         confusing "is the bug still there?" reports with mobile Safari
         just serving a stale cached invoice-share.js from before the fix. -->
    <?php
        $jspdfPath = __DIR__ . '/js/vendor/jspdf.umd.min.js';
        $html2canvasPath = __DIR__ . '/js/vendor/html2canvas.min.js';
        $invoiceSharePath = __DIR__ . '/js/invoice-share.js';
    ?>
    <script src="/js/vendor/jspdf.umd.min.js?v=<?= filemtime($jspdfPath) ?>"></script>
    <script src="/js/vendor/html2canvas.min.js?v=<?= filemtime($html2canvasPath) ?>"></script>
    <script src="/js/invoice-share.js?v=<?= filemtime($invoiceSharePath) ?>"></script>
<?php endif; ?>

</body>
</html>
