<?php

require_once __DIR__ . '/../../app/Auth/Auth.php';
require_once __DIR__ . '/../../app/Domain/SupplierLedger.php';

$pdo = require __DIR__ . '/../../config/database.php';

$auth = new Auth($pdo);
$user = $auth->user();

if (!$user) {
    http_response_code(401);
    header('Location: /');
    exit;
}

require_permission($user, 'suppliers.view');

$organizationId = $user['organization_id'];
$supplierId     = (int) ($_GET['id'] ?? 0);
$dateFrom       = $_GET['from'] ?? null;
$dateTo         = $_GET['to'] ?? null;

$isValidDate = static function (?string $value): bool {
    if (!$value) {
        return false;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $value);
    return $parsed && $parsed->format('Y-m-d') === $value;
};
$dateFrom = $isValidDate($dateFrom) ? $dateFrom : null;
$dateTo   = $isValidDate($dateTo) ? $dateTo : null;

$statement = $pdo->prepare("
    SELECT id, name, code, phone, email, address, gstin
    FROM suppliers
    WHERE id = :id AND organization_id = :organization_id
");
$statement->execute(['id' => $supplierId, 'organization_id' => $organizationId]);
$supplier = $statement->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    http_response_code(404);
    echo "Supplier not found";
    exit;
}

$ledger = new SupplierLedger($pdo);
$rows = $ledger->getStatementData($organizationId, $supplierId, $dateFrom, $dateTo);

$filename = 'Statement_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $supplier['code'] ?: $supplier['name']) . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// PHP 8.4 deprecates calling fputcsv() without an explicit $escape, and
// the global error handler turns that deprecation into a 500 — so every
// row goes through here with all arguments spelled out.
$writeRow = static function (array $fields) use ($output): void {
    fputcsv($output, $fields, ',', '"', '\\');
};

// BOM for UTF-8 Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header metadata
$writeRow(['Supplier Statement']);
$writeRow(['Supplier Name', $supplier['name']]);
$writeRow(['Supplier Code', $supplier['code'] ?? '']);
$writeRow(['Phone', $supplier['phone'] ?? '']);
$writeRow(['GSTIN', $supplier['gstin'] ?? '']);
if ($dateFrom || $dateTo) {
    $writeRow(['Period', ($dateFrom ?: 'Beginning') . ' to ' . ($dateTo ?: 'Today')]);
}
$writeRow(['Generated On', date('Y-m-d H:i:s')]);
$writeRow([]); // blank row

// Table columns
$writeRow([
    'Date',
    'Type',
    'Reference No',
    'Description',
    'Debit (Payable Increases)',
    'Credit (Payable Decreases)',
    'Running Balance'
]);

$txnTypeLabels = [
    'OPENING_BALANCE'    => 'Opening Balance',
    'PURCHASE'           => 'Purchase',
    'PAYMENT'            => 'Payment',
    'PURCHASE_RETURN'    => 'Purchase Return',
    'CREDIT_ADJUSTMENT'  => 'Credit Adjustment',
    'DEBIT_ADJUSTMENT'   => 'Debit Adjustment',
    'REFUND'             => 'Refund',
    'PAYMENT_REVERSAL'   => 'Payment Reversal',
    'PURCHASE_REVERSAL'  => 'Purchase Reversal'
];

$totalDebit = 0;
$totalCredit = 0;
$finalBalance = 0;

foreach ($rows as $row) {
    $totalDebit += (float) $row['debit'];
    $totalCredit += (float) $row['credit'];
    $finalBalance = (float) $row['running_balance'];

    $writeRow([
        $row['transaction_date'],
        $txnTypeLabels[$row['transaction_type']] ?? $row['transaction_type'],
        $row['reference_no'] ?? '',
        $row['description'],
        $row['debit'] > 0 ? number_format((float) $row['debit'], 2, '.', '') : '',
        $row['credit'] > 0 ? number_format((float) $row['credit'], 2, '.', '') : '',
        number_format((float) $row['running_balance'], 2, '.', '')
    ]);
}

$writeRow([]); // blank row
$writeRow([
    'Total / Current Balance',
    '',
    '',
    '',
    number_format($totalDebit, 2, '.', ''),
    number_format($totalCredit, 2, '.', ''),
    number_format($finalBalance, 2, '.', '')
]);

fclose($output);
exit;
