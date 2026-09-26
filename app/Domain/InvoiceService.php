<?php

declare(strict_types=1);

require_once __DIR__ . '/Audit.php';

class InvoiceService
{
    /**
     * Compute all invoice line items from a job card (services, parts, and per-part labour).
     */
    public static function buildLineItemsFromJobCard(PDO $pdo, int $jobCardId, int $organizationId): array
    {
        $statement = $pdo->prepare("
            SELECT COALESCE(jci.custom_name, s.name) AS name,
                   jci.price - jci.discount AS total,
                   COALESCE(s.tax_rate, 0) AS tax_rate,
                   s.sac_code
            FROM job_card_items jci
            LEFT JOIN services s ON s.id = jci.service_id
            WHERE jci.job_card_id = :job_card_id
            ORDER BY jci.created_at ASC
        ");
        $statement->execute(['job_card_id' => $jobCardId]);
        $serviceLines = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $pdo->prepare("
            SELECT p.name, jcp.quantity, jcp.unit_price, p.tax_rate, p.hsn_code, jcp.labour_charge, jcp.labour_quantity
            FROM job_card_parts jcp
            INNER JOIN parts p ON p.id = jcp.part_id
            WHERE jcp.job_card_id = :job_card_id
            ORDER BY jcp.created_at ASC
        ");
        $statement->execute(['job_card_id' => $jobCardId]);
        $partLines = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $pdo->prepare("SELECT default_tax_rate FROM organizations WHERE id = :id");
        $statement->execute(['id' => $organizationId]);
        $orgDefaultTaxRate = (float) $statement->fetchColumn();

        $subtotal = 0.0;
        $taxAmount = 0.0;
        $lineItems = [];

        // 1. Services
        foreach ($serviceLines as $line) {
            $lineTotal = (float) $line['total'];
            $lineTax = $lineTotal * ((float) $line['tax_rate'] / 100);
            $subtotal += $lineTotal;
            $taxAmount += $lineTax;

            $lineItems[] = [
                'item_type' => 'service',
                'description' => $line['name'],
                'quantity' => 1.0,
                'unit_price' => $lineTotal,
                'tax_rate' => (float) $line['tax_rate'],
                'hsn_sac_code' => $line['sac_code'] ?: null,
                'total' => $lineTotal + $lineTax
            ];
        }

        // 2. Parts
        foreach ($partLines as $line) {
            $lineTotal = (float) $line['quantity'] * (float) $line['unit_price'];
            $lineTax = $lineTotal * ((float) $line['tax_rate'] / 100);
            $subtotal += $lineTotal;
            $taxAmount += $lineTax;

            $lineItems[] = [
                'item_type' => 'part',
                'description' => $line['name'],
                'quantity' => (float) $line['quantity'],
                'unit_price' => (float) $line['unit_price'],
                'tax_rate' => (float) $line['tax_rate'],
                'hsn_sac_code' => $line['hsn_code'] ?: null,
                'total' => $lineTotal + $lineTax
            ];

            // 3. Per-part Labour
            $labourCharge = (float) $line['labour_charge'];
            $labourQuantity = (float) $line['labour_quantity'];

            if ($labourCharge > 0) {
                $labourLineTotal = $labourCharge * $labourQuantity;
                $labourTax = $labourLineTotal * ($orgDefaultTaxRate / 100);
                $subtotal += $labourLineTotal;
                $taxAmount += $labourTax;

                $lineItems[] = [
                    'item_type' => 'labour',
                    'description' => 'Labour for ' . $line['name'],
                    'quantity' => $labourQuantity,
                    'unit_price' => $labourCharge,
                    'tax_rate' => $orgDefaultTaxRate,
                    'hsn_sac_code' => null,
                    'total' => $labourLineTotal + $labourTax
                ];
            }
        }

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $subtotal + $taxAmount,
            'line_items' => $lineItems
        ];
    }

    /**
     * Re-sync an existing unpaid invoice with the latest items and labour on its linked job card.
     */
    public static function syncInvoiceFromJobCard(PDO $pdo, int $invoiceId, ?array $user = null): bool
    {
        $statement = $pdo->prepare("SELECT * FROM invoices WHERE id = :id FOR UPDATE");
        $statement->execute(['id' => $invoiceId]);
        $invoice = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$invoice || (float) $invoice['amount_paid'] > 0.009) {
            return false;
        }

        $jobCardId = (int) $invoice['job_card_id'];
        $organizationId = (int) $invoice['organization_id'];

        $calculated = self::buildLineItemsFromJobCard($pdo, $jobCardId, $organizationId);

        if (empty($calculated['line_items'])) {
            return false;
        }

        $statement = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = :invoice_id");
        $statement->execute(['invoice_id' => $invoiceId]);

        $insertStmt = $pdo->prepare("
            INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, tax_rate, hsn_sac_code, total)
            VALUES (:invoice_id, :item_type, :description, :quantity, :unit_price, :tax_rate, :hsn_sac_code, :total)
        ");

        foreach ($calculated['line_items'] as $item) {
            $insertStmt->execute([
                'invoice_id' => $invoiceId,
                'item_type' => $item['item_type'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_rate' => $item['tax_rate'],
                'hsn_sac_code' => $item['hsn_sac_code'],
                'total' => $item['total']
            ]);
        }

        $updateStmt = $pdo->prepare("
            UPDATE invoices
            SET subtotal = :subtotal, tax_amount = :tax_amount, total = :total, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $updateStmt->execute([
            'id' => $invoiceId,
            'subtotal' => $calculated['subtotal'],
            'tax_amount' => $calculated['tax_amount'],
            'total' => $calculated['total']
        ]);

        if ($user && isset($user['id'])) {
            $auditUser = $user;
            if (empty($auditUser['organization_id'])) {
                $auditUser['organization_id'] = $organizationId;
            }
            log_audit_event(
                $pdo, $auditUser, 'update', 'invoice', $invoiceId,
                "Synchronized invoice {$invoice['invoice_no']} with job card changes (Total: ₹{$calculated['total']})"
            );
        }

        return true;
    }

    /**
     * Group invoice items into Parts & Materials and Labour & Services with sub-totals.
     */
    public static function groupItems(array $items): array
    {
        $parts = [];
        $labour = [];

        $partsTaxable = 0.0;
        $partsTax = 0.0;
        $partsTotal = 0.0;

        $labourTaxable = 0.0;
        $labourTax = 0.0;
        $labourTotal = 0.0;

        foreach ($items as $item) {
            $type = $item['item_type'] ?? 'service';
            $qty = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $taxRate = (float) ($item['tax_rate'] ?? 0);
            $lineTaxable = $qty * $unitPrice;
            $lineTax = $lineTaxable * ($taxRate / 100);
            $lineTotal = (float) ($item['total'] ?? ($lineTaxable + $lineTax));

            if ($type === 'part') {
                $parts[] = $item;
                $partsTaxable += $lineTaxable;
                $partsTax += $lineTax;
                $partsTotal += $lineTotal;
            } else {
                $labour[] = $item;
                $labourTaxable += $lineTaxable;
                $labourTax += $lineTax;
                $labourTotal += $lineTotal;
            }
        }

        return [
            'parts' => $parts,
            'labour' => $labour,
            'parts_taxable' => $partsTaxable,
            'parts_tax' => $partsTax,
            'parts_total' => $partsTotal,
            'labour_taxable' => $labourTaxable,
            'labour_tax' => $labourTax,
            'labour_total' => $labourTotal,
            'has_parts' => !empty($parts),
            'has_labour' => !empty($labour),
            'has_multiple_categories' => (!empty($parts) && !empty($labour))
        ];
    }

    /**
     * Convert currency amount to words in Indian numbering format (Lakhs, Crores).
     */
    public static function numberToWordsInr(float $amount): string
    {
        $amount = round($amount, 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        if ($rupees === 0 && $paise === 0) {
            return 'Zero Rupees Only';
        }

        $words = [];
        if ($rupees > 0) {
            $words[] = self::convertNumber($rupees) . ' Rupees';
        }
        if ($paise > 0) {
            $words[] = self::convertNumber($paise) . ' Paise';
        }

        return 'INR ' . implode(' and ', $words) . ' Only';
    }

    private static function convertNumber(int $num): string
    {
        $ones = [
            0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
            5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
            10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
            15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
        ];
        $tens = [
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
            6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
        ];

        if ($num === 0) return 'Zero';

        $result = '';

        // Crores
        $crore = (int) floor($num / 10000000);
        if ($crore > 0) {
            $result .= self::convertNumber($crore) . ' Crore ';
            $num %= 10000000;
        }

        // Lakhs
        $lakh = (int) floor($num / 100000);
        if ($lakh > 0) {
            $result .= self::convertNumber($lakh) . ' Lakh ';
            $num %= 100000;
        }

        // Thousands
        $thousand = (int) floor($num / 1000);
        if ($thousand > 0) {
            $result .= self::convertNumber($thousand) . ' Thousand ';
            $num %= 1000;
        }

        // Hundreds
        $hundred = (int) floor($num / 100);
        if ($hundred > 0) {
            $result .= self::convertNumber($hundred) . ' Hundred ';
            $num %= 100;
        }

        if ($num > 0) {
            if ($num < 20) {
                $result .= $ones[$num];
            } else {
                $t = (int) floor($num / 10);
                $o = $num % 10;
                $result .= $tens[$t] . ($o > 0 ? ' ' . $ones[$o] : '');
            }
        }

        return trim($result);
    }
}
