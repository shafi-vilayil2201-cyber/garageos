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

require_permission($user, 'invoices.view');

$organizationId = $user['organization_id'];
$invoiceId = (int) ($_GET['id'] ?? 0);

$statement = $pdo->prepare("
    SELECT i.*, c.name AS customer_name, c.phone AS customer_phone,
           jc.job_no, v.registration_no, v.make, v.model,
           o.name AS organization_name, o.phone AS organization_phone, o.address AS organization_address
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'invoices.manage');

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

            $pdo->commit();

            header('Location: /invoice.php?id=' . $invoiceId);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
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

$invoiceStatusIcons = [
    'unpaid' => 'alert-triangle',
    'partial' => 'wallet',
    'paid' => 'check-circle',
    'void' => 'x'
];

$activeNav = 'job_cards';
$topbarTitle = $invoice['invoice_no'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — <?= htmlspecialchars($invoice['invoice_no']) ?></title>

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

                <a href="/job-card.php?id=<?= (int) $invoice['job_card_id'] ?>" class="button secondary"><?= icon('arrow-left', 16) ?> Back to job card</a>
            </div>

            <div class="content-grid" style="grid-template-columns: 1fr 340px;">

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                            <?= htmlspecialchars($invoice['organization_name']) ?>
                        </div>
                        <span style="font-weight:400; color:var(--muted); font-size:13px;">
                            Billed to <?= htmlspecialchars($invoice['customer_name']) ?>
                        </span>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Total</th></tr>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['description']) ?></td>
                                        <td class="num"><?= rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') ?></td>
                                        <td class="num">₹<?= number_format($item['unit_price'], 2) ?></td>
                                        <td class="num">₹<?= number_format($item['total'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <div style="padding:18px 20px; border-top:1px solid var(--border);">
                            <div class="summary-row" style="display:flex; justify-content:space-between; padding:5px 0;">
                                <span>Subtotal</span><strong class="num">₹<?= number_format($invoice['subtotal'], 2) ?></strong>
                            </div>
                            <div class="summary-row" style="display:flex; justify-content:space-between; padding:5px 0;">
                                <span>Tax</span><strong class="num">₹<?= number_format($invoice['tax_amount'], 2) ?></strong>
                            </div>
                            <div class="summary-row" style="display:flex; justify-content:space-between; padding:10px 0; border-top:1px solid var(--border); margin-top:6px; font-size:18px;">
                                <span>Total</span><strong class="num">₹<?= number_format($invoice['total'], 2) ?></strong>
                            </div>
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

                    <?php if ($balanceDue > 0.009): ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('check-circle', 15) ?></span>
                                    Record payment
                                </div>
                            </div>
                            <div class="card-body">

                                <?php if ($error): ?>
                                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                                <?php endif; ?>

                                <form method="POST" action="">
                                    <?= csrf_field() ?>
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

</body>
</html>
