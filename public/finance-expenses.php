<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'finance.view');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];

$periodMonth = $_GET['month'] ?? date('Y-m');

if (!DateTime::createFromFormat('Y-m', $periodMonth)) {
    $periodMonth = date('Y-m');
}

$periodStart = $periodMonth . '-01';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'finance.manage');

    $category = $_POST['category'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $expenseDate = $_POST['expense_date'] ?? '';

    $validCategories = ['rent', 'electricity', 'maintenance', 'misc', 'other'];

    if (!in_array($category, $validCategories, true)) {
        $error = 'Choose a valid category.';
    } elseif ($amount <= 0) {
        $error = 'Enter a valid amount.';
    } elseif (!DateTime::createFromFormat('Y-m-d', $expenseDate)) {
        $error = 'Choose a valid date.';
    } else {

        $statement = $pdo->prepare("
            INSERT INTO expenses (organization_id, branch_id, category, description, amount, expense_date, created_by)
            VALUES (:organization_id, :branch_id, :category, :description, :amount, :expense_date, :created_by)
            RETURNING id
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'category' => $category,
            'description' => $description ?: null,
            'amount' => $amount,
            'expense_date' => $expenseDate,
            'created_by' => $user['id']
        ]);
        $newExpenseId = (int) $statement->fetchColumn();

        log_audit_event(
            $pdo, $user, 'create', 'expense', $newExpenseId,
            'Logged ' . ucfirst($category) . ' expense of ₹' . number_format($amount, 2)
        );

        header('Location: /finance-expenses.php?month=' . urlencode(date('Y-m', strtotime($expenseDate))));
        exit;
    }
}

// Three separate, differently-shaped sources rolled into one common
// {date, category, description, amount} shape in PHP — simpler to
// reason about than a SQL UNION across tables that don't share a
// schema, and matches how reports.php already composes multiple
// separate queries per widget rather than one combined query.
$rows = [];

$statement = $pdo->prepare("
    SELECT expense_date AS date, category, description, amount
    FROM expenses
    WHERE organization_id = :organization_id
      AND expense_date >= :period_start
      AND expense_date < (:period_start_end::date + INTERVAL '1 month')
");
$statement->execute([
    'organization_id' => $organizationId,
    'period_start' => $periodStart,
    'period_start_end' => $periodStart
]);
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rows[] = [
        'date' => $row['date'],
        'category' => ucfirst($row['category']),
        'description' => $row['description'] ?: '—',
        'amount' => (float) $row['amount']
    ];
}

$statement = $pdo->prepare("
    SELECT p.created_at AS date, p.purchase_no, s.name AS supplier_name, p.total
    FROM purchases p
    INNER JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.organization_id = :organization_id
      AND p.created_at >= :period_start
      AND p.created_at < (:period_start_end::date + INTERVAL '1 month')
");
$statement->execute([
    'organization_id' => $organizationId,
    'period_start' => $periodStart,
    'period_start_end' => $periodStart
]);
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rows[] = [
        'date' => $row['date'],
        'category' => 'Purchase',
        'description' => $row['purchase_no'] . ' — ' . $row['supplier_name'],
        'amount' => (float) $row['total']
    ];
}

// Keyed on paid_at — an unpaid run isn't a real expense yet (see
// migration 054), and with per-employee pay cycles period_month is
// often a mid-month date rather than always matching this page's
// calendar-month selector.
$statement = $pdo->prepare("
    SELECT pr.paid_at::date AS date, u.name AS employee_name, pr.net_salary
    FROM payroll_runs pr
    INNER JOIN users u ON u.id = pr.user_id
    WHERE pr.organization_id = :organization_id AND pr.payment_status = 'paid'
      AND pr.paid_at >= :period_start AND pr.paid_at < (:period_start_end::date + INTERVAL '1 month')
");
$statement->execute(['organization_id' => $organizationId, 'period_start' => $periodStart, 'period_start_end' => $periodStart]);
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rows[] = [
        'date' => $row['date'],
        'category' => 'Payroll',
        'description' => $row['employee_name'],
        'amount' => (float) $row['net_salary']
    ];
}

usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));

$monthTotal = array_sum(array_column($rows, 'amount'));

$activeNav = 'finance_expenses';
$topbarTitle = 'Expenses';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Expenses</title>

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
                    <h1 class="page-title">Expenses</h1>
                    <p class="page-description">Everything going out — rent, electricity, purchases, and payroll, together.</p>
                </div>
            </div>

            <div class="card stat-card" style="max-width:280px; margin-bottom:16px;">
                <div class="stat-top">
                    <div>
                        <div class="stat-label">Total expenses</div>
                        <div class="stat-value">₹<?= number_format($monthTotal, 2) ?></div>
                    </div>
                    <div class="stat-icon"><?= icon('receipt', 17) ?></div>
                </div>
                <div class="stat-meta">In <?= htmlspecialchars((new DateTime($periodStart))->format('F Y')) ?></div>
            </div>

            <div class="content-grid content-grid-aside">

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-title">
                            <span class="icon-badge"><?= icon('receipt', 15) ?></span>
                            <?= htmlspecialchars((new DateTime($periodStart))->format('F Y')) ?>
                        </div>
                        <form method="GET" action="">
                            <div class="header-date-wrap">
                                <button type="button" class="button secondary header-date-trigger" aria-label="Choose month" onclick="openDatePicker(this)"><?= icon('calendar', 16) ?> <?= htmlspecialchars((new DateTime($periodStart))->format('F Y')) ?></button>
                                <input type="month" name="month" value="<?= htmlspecialchars($periodMonth) ?>" onchange="this.form.submit()">
                            </div>
                        </form>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($rows)): ?>
                            <div class="empty-state">
                                <?= icon('receipt', 28) ?>
                                No expenses recorded for this month.
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date('d M Y', strtotime($row['date']))) ?></td>
                                            <td><?= htmlspecialchars($row['category']) ?></td>
                                            <td><?= htmlspecialchars($row['description']) ?></td>
                                            <td class="num">₹<?= number_format($row['amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (user_can($user, 'finance.manage')): ?>
                    <div class="stack">
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-title">
                                    <span class="icon-badge"><?= icon('plus', 15) ?></span>
                                    Add expense
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
                                            <label>Category</label>
                                            <select name="category" required>
                                                <option value="rent">Rent</option>
                                                <option value="electricity">Electricity</option>
                                                <option value="maintenance">Maintenance</option>
                                                <option value="misc">Miscellaneous</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label>Description (optional)</label>
                                            <input type="text" name="description" maxlength="255">
                                        </div>
                                        <div class="form-field">
                                            <label>Amount</label>
                                            <input type="number" name="amount" min="0.01" step="0.01" required>
                                        </div>
                                        <div class="form-field">
                                            <label>Date</label>
                                            <input type="date" name="expense_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                                        </div>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="button"><?= icon('check', 16) ?> Add expense</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

</body>
</html>
