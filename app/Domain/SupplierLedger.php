<?php

// Central service for all supplier financial operations.
//
// Every page that changes or reads a supplier's outstanding balance
// must go through this class — finance-dues.php, supplier.php,
// purchase-new.php, finance.php, etc.  That way the "single source
// of truth" rule from the requirements holds, and balance numbers
// can never drift between pages.

class SupplierLedger
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // -----------------------------------------------------------------
    //  READ — Summaries and ledger entries
    // -----------------------------------------------------------------

    /**
     * Financial summary for one supplier: total purchases, payments,
     * credits, debits, and the current outstanding balance.
     */
    public function getSupplierSummary(int $organizationId, int $supplierId): array
    {
        // The category totals skip every reversed entry and every reversal:
        // a cancelled ₹100,000 credit and the entry cancelling it net to
        // nothing, so neither should inflate "Credits" (or "Paid", etc.).
        // outstanding_balance still sums every row — the pair cancels there.
        $statement = $this->pdo->prepare("
            WITH entries AS (
                SELECT
                    st.transaction_type,
                    st.debit,
                    st.credit,
                    (
                        st.reference_type IS NOT DISTINCT FROM 'supplier_transaction'
                        OR EXISTS (
                            SELECT 1 FROM supplier_transactions r
                            WHERE r.organization_id = st.organization_id
                              AND r.reference_type  = 'supplier_transaction'
                              AND r.reference_id    = st.id
                        )
                    ) AS is_cancelled_pair
                FROM supplier_transactions st
                WHERE st.organization_id = :organization_id
                  AND st.supplier_id     = :supplier_id
            )
            SELECT
                COALESCE(SUM(CASE WHEN NOT is_cancelled_pair AND transaction_type = 'PURCHASE'        THEN debit END), 0) AS total_purchases,
                COALESCE(SUM(CASE WHEN NOT is_cancelled_pair AND transaction_type = 'PAYMENT'         THEN credit END), 0) AS total_payments,
                COALESCE(SUM(CASE WHEN NOT is_cancelled_pair AND transaction_type IN ('CREDIT_ADJUSTMENT', 'PURCHASE_RETURN', 'REFUND') THEN credit END), 0) AS total_credits,
                COALESCE(SUM(CASE WHEN NOT is_cancelled_pair AND transaction_type = 'DEBIT_ADJUSTMENT' THEN debit END), 0) AS total_debits,
                COALESCE(SUM(CASE WHEN NOT is_cancelled_pair AND transaction_type = 'OPENING_BALANCE'  THEN debit END), 0) AS total_opening,
                COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS outstanding_balance
            FROM entries
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Outstanding balance for one supplier — a single number.
     */
    public function getOutstandingBalance(int $organizationId, int $supplierId): float
    {
        $statement = $this->pdo->prepare("
            SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)
            FROM supplier_transactions
            WHERE organization_id = :organization_id
              AND supplier_id     = :supplier_id
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ]);

        return (float) $statement->fetchColumn();
    }

    /**
     * Ledger entries with a running balance, for display in the
     * Excel-style table on supplier.php.
     *
     * Returns: [rows[], total_count]
     */
    public function getLedgerEntries(
        int $organizationId,
        int $supplierId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $typeFilter = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        // Total count for pagination
        $countSql = "
            SELECT COUNT(*)
            FROM supplier_transactions
            WHERE organization_id = :organization_id
              AND supplier_id     = :supplier_id
        ";
        $countParams = [
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ];

        if ($dateFrom) {
            $countSql .= " AND transaction_date >= :date_from";
            $countParams['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $countSql .= " AND transaction_date <= :date_to";
            $countParams['date_to'] = $dateTo;
        }
        if ($typeFilter) {
            $countSql .= " AND transaction_type = :type_filter";
            $countParams['type_filter'] = $typeFilter;
        }

        $statement = $this->pdo->prepare($countSql);
        $statement->execute($countParams);
        $totalCount = (int) $statement->fetchColumn();

        // Ledger rows with window-function running balance computed across all transactions
        $sql = "
            WITH full_ledger AS (
                SELECT
                    st.id,
                    st.transaction_type,
                    st.transaction_date,
                    st.reference_no,
                    st.description,
                    st.debit,
                    st.credit,
                    st.reference_type,
                    st.reference_id,
                    SUM(st.debit - st.credit) OVER (
                        ORDER BY st.transaction_date ASC, st.id ASC
                        ROWS UNBOUNDED PRECEDING
                    ) AS running_balance,
                    st.created_at,
                    u.name AS created_by_name
                FROM supplier_transactions st
                LEFT JOIN users u ON u.id = st.created_by
                WHERE st.organization_id = :organization_id
                  AND st.supplier_id     = :supplier_id
            )
            SELECT *
            FROM full_ledger
            WHERE 1=1
        ";
        $params = [
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ];

        if ($dateFrom) {
            $sql .= " AND transaction_date >= :date_from";
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND transaction_date <= :date_to";
            $params['date_to'] = $dateTo;
        }
        if ($typeFilter) {
            $sql .= " AND transaction_type = :type_filter";
            $params['type_filter'] = $typeFilter;
        }

        $sql .= " ORDER BY transaction_date DESC, id DESC LIMIT :limit OFFSET :offset";

        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [$statement->fetchAll(PDO::FETCH_ASSOC), $totalCount];
    }

    /**
     * All-suppliers outstanding balances for finance-dues.php.
     * Returns rows: [supplier_id, supplier_name, supplier_code, outstanding_balance]
     */
    public function getOrganizationPayables(int $organizationId): array
    {
        $statement = $this->pdo->prepare("
            SELECT
                s.id   AS supplier_id,
                s.name AS supplier_name,
                s.code AS supplier_code,
                COALESCE(SUM(st.debit), 0) - COALESCE(SUM(st.credit), 0) AS outstanding_balance
            FROM suppliers s
            LEFT JOIN supplier_transactions st
                ON st.supplier_id = s.id
               AND st.organization_id = s.organization_id
            WHERE s.organization_id = :organization_id
              AND s.status = 'active'
            GROUP BY s.id, s.name, s.code
            HAVING COALESCE(SUM(st.debit), 0) - COALESCE(SUM(st.credit), 0) > 0.009
            ORDER BY outstanding_balance DESC
        ");
        $statement->execute(['organization_id' => $organizationId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Total supplier payable for the entire organisation (one number).
     */
    public function getTotalPayable(int $organizationId): float
    {
        // Per-supplier balances first, then add up only the positive ones:
        // a supplier who owes *you* (credit surplus) must not reduce what
        // you owe everyone else.
        $statement = $this->pdo->prepare("
            SELECT COALESCE(SUM(balance), 0)
            FROM (
                SELECT SUM(st.debit) - SUM(st.credit) AS balance
                FROM supplier_transactions st
                INNER JOIN suppliers s ON s.id = st.supplier_id AND s.organization_id = st.organization_id
                WHERE st.organization_id = :organization_id
                  AND s.status = 'active'
                GROUP BY st.supplier_id
            ) supplier_balances
            WHERE balance > 0.009
        ");
        $statement->execute(['organization_id' => $organizationId]);

        return (float) $statement->fetchColumn();
    }

    // -----------------------------------------------------------------
    //  WRITE — Mutations (all require an active transaction from the
    //  caller so they can be composed with related writes atomically)
    // -----------------------------------------------------------------

    /**
     * Create a ledger entry when a purchase is saved.
     * Call inside the same DB transaction as the purchase INSERT.
     */
    public function createLedgerEntryForPurchase(
        int $organizationId,
        int $supplierId,
        int $purchaseId,
        string $purchaseNo,
        float $total,
        string $description,
        ?int $userId
    ): int {
        return $this->insertTransaction(
            $organizationId,
            $supplierId,
            'PURCHASE',
            date('Y-m-d'),
            $purchaseNo,
            $description,
            $total,
            0,
            'purchase',
            $purchaseId,
            $userId
        );
    }

    /**
     * Record a payment to a supplier.
     *
     * This method creates:
     * 1. A supplier_payments row
     * 2. A supplier_transactions ledger entry
     * 3. Payment allocation(s) if purchase IDs are provided or FIFO auto-allocated
     * 4. Updates purchases.amount_paid and payment_status
     *
     * The caller must have already begun a transaction.
     */
    public function recordPayment(
        int $organizationId,
        int $branchId,
        int $supplierId,
        float $amount,
        string $method,
        ?string $referenceNo,
        ?string $paymentDate,
        array $allocations,  // [{purchase_id, amount}] or empty for auto-FIFO
        array $user
    ): int {
        $paymentDate = $paymentDate ?: date('Y-m-d');
        $this->assertPastOrToday($paymentDate, 'payment date');
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw new RuntimeException('Choose a valid payment method.');
        }

        $this->lockSupplier($organizationId, $supplierId);

        // Guard: prevent payments when supplier already has a credit surplus
        $currentBalance = $this->getOutstandingBalance($organizationId, $supplierId);
        if ($currentBalance <= 0.009) {
            throw new RuntimeException($currentBalance < -0.009
                ? 'This supplier has a credit surplus of ₹' . number_format(abs($currentBalance), 2) . '. No payment is due.'
                : 'Nothing is due to this supplier — the balance is already settled.'
            );
        }

        // Guard: warn if payment exceeds outstanding (creates advance/credit surplus)
        if ($amount > $currentBalance + 0.01) {
            throw new RuntimeException(
                'Payment of ₹' . number_format($amount, 2) . ' exceeds the outstanding balance of ₹'
                . number_format($currentBalance, 2) . '. Reduce the amount or record a credit adjustment instead.'
            );
        }

        // Generate reference number
        $payRefNo = $this->generateReferenceNo($organizationId, 'PAY');

        $resolvedAllocations = $this->resolveBillAllocations($organizationId, $supplierId, $amount, $allocations, 'Payment');

        $firstPurchaseId = count($resolvedAllocations) === 1
            ? $resolvedAllocations[0]['purchase_id']
            : null;

        // 1. Insert supplier_payments
        $statement = $this->pdo->prepare("
            INSERT INTO supplier_payments
                (organization_id, branch_id, supplier_id, purchase_id, method, amount, reference_no, paid_by, payment_date)
            VALUES
                (:organization_id, :branch_id, :supplier_id, :purchase_id, :method, :amount, :reference_no, :paid_by, :payment_date)
            RETURNING id
        ");

        $statement->execute([
            'organization_id' => $organizationId,
            'branch_id'       => $branchId,
            'supplier_id'     => $supplierId,
            'purchase_id'     => $firstPurchaseId,
            'method'          => $method,
            'amount'          => $amount,
            'reference_no'    => $referenceNo ?: null,
            'paid_by'         => $user['id'],
            'payment_date'    => $paymentDate
        ]);
        $paymentId = (int) $statement->fetchColumn();

        // 2. Description for ledger
        $desc = 'Payment via ' . strtoupper(str_replace('_', ' ', $method));
        if ($referenceNo) {
            $desc .= ' — ' . $referenceNo;
        }

        // 3. Create ledger entry
        $this->insertTransaction(
            $organizationId,
            $supplierId,
            'PAYMENT',
            $paymentDate,
            $payRefNo,
            $desc,
            0,
            $amount,
            'supplier_payment',
            $paymentId,
            $user['id']
        );

        // 4. Payment allocations + update purchase amount_paid cache & status
        if (!empty($resolvedAllocations)) {
            $allocStatement = $this->pdo->prepare("
                INSERT INTO supplier_payment_allocations (supplier_payment_id, purchase_id, amount)
                VALUES (:payment_id, :purchase_id, :amount)
            ");

            foreach ($resolvedAllocations as $alloc) {
                $allocStatement->execute([
                    'payment_id'  => $paymentId,
                    'purchase_id' => $alloc['purchase_id'],
                    'amount'      => $alloc['amount']
                ]);

                $this->adjustBillSettlement($organizationId, $alloc['purchase_id'], 'amount_paid', $alloc['amount']);
            }
        }

        return $paymentId;
    }

    /**
     * Decide which bills a payment or credit note settles. With an explicit
     * [{purchase_id, amount}] list, each bill is checked against this
     * supplier and its remaining balance; with an empty list, the amount is
     * spread across unpaid bills oldest-first. Anything left over (e.g. it
     * covers an opening balance or extra charge) stays unallocated.
     */
    private function resolveBillAllocations(
        int $organizationId,
        int $supplierId,
        float $amount,
        array $allocations,
        string $label
    ): array {
        $resolved  = [];
        $remaining = $amount;

        if (!empty($allocations)) {
            $billStatement = $this->pdo->prepare("
                SELECT purchase_no, status, total - amount_paid - amount_credited AS balance_due
                FROM purchases
                WHERE id = :id AND organization_id = :organization_id AND supplier_id = :supplier_id
                FOR UPDATE
            ");
            foreach ($allocations as $alloc) {
                $pId  = (int) ($alloc['purchase_id'] ?? 0);
                $pAmt = (float) ($alloc['amount'] ?? 0);
                if ($pId <= 0 || $pAmt <= 0) {
                    continue;
                }
                $billStatement->execute([
                    'id'              => $pId,
                    'organization_id' => $organizationId,
                    'supplier_id'     => $supplierId
                ]);
                $bill = $billStatement->fetch(PDO::FETCH_ASSOC);
                if (!$bill) {
                    throw new RuntimeException('The selected bill does not belong to this supplier.');
                }
                if ($bill['status'] === 'cancelled') {
                    throw new RuntimeException('Bill ' . $bill['purchase_no'] . ' has been cancelled.');
                }
                $billDue = (float) $bill['balance_due'];
                if ($billDue <= 0.009) {
                    throw new RuntimeException('Bill ' . $bill['purchase_no'] . ' is already fully settled.');
                }
                if ($pAmt > $billDue + 0.01) {
                    throw new RuntimeException(
                        $label . ' of ₹' . number_format($pAmt, 2) . ' exceeds the ₹' . number_format($billDue, 2)
                        . ' due on bill ' . $bill['purchase_no'] . '. Reduce the amount or choose the oldest-bills-first option to spread it across bills.'
                    );
                }
                $resolved[] = ['purchase_id' => $pId, 'amount' => $pAmt];
            }

            return $resolved;
        }

        foreach ($this->getUnpaidPurchases($organizationId, $supplierId) as $bill) {
            if ($remaining <= 0.009) {
                break;
            }
            $allocAmt = min($remaining, (float) $bill['balance_due']);
            if ($allocAmt > 0.009) {
                $resolved[] = ['purchase_id' => (int) $bill['id'], 'amount' => round($allocAmt, 2)];
                $remaining -= $allocAmt;
            }
        }

        return $resolved;
    }

    /**
     * Add (or with a negative amount, remove) a payment or credit on one
     * bill and recompute its status from paid + credited against total.
     */
    private function adjustBillSettlement(int $organizationId, int $purchaseId, string $column, float $amount): void
    {
        if (!in_array($column, ['amount_paid', 'amount_credited'], true)) {
            throw new InvalidArgumentException('Unknown settlement column.');
        }
        $other = $column === 'amount_paid' ? 'amount_credited' : 'amount_paid';

        $statement = $this->pdo->prepare("
            UPDATE purchases
            SET {$column} = GREATEST(0, LEAST(total - {$other}, {$column} + :amount)),
                payment_status = CASE
                    WHEN GREATEST(0, LEAST(total - {$other}, {$column} + :amount2)) + {$other} >= total - 0.01 THEN 'paid'
                    WHEN GREATEST(0, LEAST(total - {$other}, {$column} + :amount3)) + {$other} <= 0.009 THEN 'unpaid'
                    ELSE 'partial'
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute([
            'amount'          => $amount,
            'amount2'         => $amount,
            'amount3'         => $amount,
            'id'              => $purchaseId,
            'organization_id' => $organizationId
        ]);
    }

    /**
     * Record a credit adjustment (reduces what the garage owes).
     */
    public function recordCreditAdjustment(
        int $organizationId,
        int $supplierId,
        float $amount,
        string $reason,
        array $user,
        ?int $purchaseId = null
    ): int {
        $this->lockSupplier($organizationId, $supplierId);

        // Guard: prevent credit adjustments that exceed the outstanding balance
        $currentBalance = $this->getOutstandingBalance($organizationId, $supplierId);
        if ($currentBalance <= 0.009) {
            throw new RuntimeException($currentBalance < -0.009
                ? 'This supplier already has a credit surplus of ₹' . number_format(abs($currentBalance), 2) . '. A credit is not applicable.'
                : 'Nothing is due to this supplier, so there is nothing to credit.'
            );
        }
        if ($amount > $currentBalance + 0.01) {
            throw new RuntimeException(
                'Credit adjustment of ₹' . number_format($amount, 2) . ' exceeds the outstanding balance of ₹'
                . number_format($currentBalance, 2) . '. The maximum credit you can apply is ₹'
                . number_format($currentBalance, 2) . '.'
            );
        }

        $allocations = $this->resolveBillAllocations(
            $organizationId,
            $supplierId,
            $amount,
            $purchaseId ? [['purchase_id' => $purchaseId, 'amount' => $amount]] : [],
            'Credit'
        );

        $refNo = $this->generateReferenceNo($organizationId, 'ADJ');

        $transactionId = $this->insertTransaction(
            $organizationId,
            $supplierId,
            'CREDIT_ADJUSTMENT',
            date('Y-m-d'),
            $refNo,
            $reason,
            0,
            $amount,
            null,
            null,
            $user['id']
        );

        $allocStatement = $this->pdo->prepare("
            INSERT INTO supplier_credit_allocations (supplier_transaction_id, purchase_id, amount)
            VALUES (:transaction_id, :purchase_id, :amount)
        ");
        foreach ($allocations as $alloc) {
            $allocStatement->execute([
                'transaction_id' => $transactionId,
                'purchase_id'    => $alloc['purchase_id'],
                'amount'         => $alloc['amount']
            ]);
            $this->adjustBillSettlement($organizationId, $alloc['purchase_id'], 'amount_credited', $alloc['amount']);
        }

        return $transactionId;
    }

    /**
     * Record a debit adjustment (increases what the garage owes).
     */
    public function recordDebitAdjustment(
        int $organizationId,
        int $supplierId,
        float $amount,
        string $reason,
        array $user
    ): int {
        $this->lockSupplier($organizationId, $supplierId);

        $refNo = $this->generateReferenceNo($organizationId, 'ADJ');

        return $this->insertTransaction(
            $organizationId,
            $supplierId,
            'DEBIT_ADJUSTMENT',
            date('Y-m-d'),
            $refNo,
            $reason,
            $amount,
            0,
            null,
            null,
            $user['id']
        );
    }

    /**
     * Record an opening balance (the amount owed before GarageOS
     * started tracking this supplier).
     */
    public function recordOpeningBalance(
        int $organizationId,
        int $supplierId,
        float $amount,
        string $description,
        string $asOfDate,
        array $user
    ): int {
        $this->assertPastOrToday($asOfDate, 'as-of date');
        $this->lockSupplier($organizationId, $supplierId);

        if ($this->hasActiveOpeningBalance($organizationId, $supplierId)) {
            throw new RuntimeException(
                'This supplier already has an opening balance. To change it, reverse the existing opening balance in the ledger first, then set the new one.'
            );
        }

        return $this->insertTransaction(
            $organizationId,
            $supplierId,
            'OPENING_BALANCE',
            $asOfDate,
            null,
            $description ?: 'Opening balance',
            $amount,
            0,
            null,
            null,
            $user['id']
        );
    }

    /**
     * Reverse a posted transaction — creates a new opposing entry
     * and rolls back any purchase allocations.
     */
    public function reverseTransaction(
        int $organizationId,
        int $supplierId,
        int $transactionId,
        string $reason,
        array $user
    ): int {
        $this->lockSupplier($organizationId, $supplierId);

        $statement = $this->pdo->prepare("
            SELECT * FROM supplier_transactions
            WHERE id = :id AND organization_id = :organization_id AND supplier_id = :supplier_id
            FOR UPDATE
        ");
        $statement->execute([
            'id'              => $transactionId,
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ]);
        $original = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$original) {
            throw new RuntimeException('Transaction not found.');
        }

        if ($original['reference_type'] === 'supplier_transaction') {
            throw new RuntimeException('This entry is itself a reversal and cannot be reversed again.');
        }

        if (in_array($transactionId, $this->getReversedTransactionIds($organizationId, $supplierId), true)) {
            throw new RuntimeException('This transaction has already been reversed.');
        }

        if ($original['transaction_type'] === 'PURCHASE' && $original['reference_type'] === 'purchase' && $original['reference_id']) {
            $this->cancelPurchase($organizationId, (int) $original['reference_id'], $user);
        }

        // If reversing a payment, roll back the allocations in purchases
        if ($original['reference_type'] === 'supplier_payment' && $original['reference_id']) {
            $allocStmt = $this->pdo->prepare("
                SELECT purchase_id, amount
                FROM supplier_payment_allocations
                WHERE supplier_payment_id = :payment_id
            ");
            $allocStmt->execute(['payment_id' => $original['reference_id']]);

            foreach ($allocStmt->fetchAll(PDO::FETCH_ASSOC) as $alloc) {
                $this->adjustBillSettlement($organizationId, (int) $alloc['purchase_id'], 'amount_paid', -(float) $alloc['amount']);
            }
        }

        // Reversing a credit note reopens the bills it had been applied to.
        $creditAllocStmt = $this->pdo->prepare("
            SELECT purchase_id, amount
            FROM supplier_credit_allocations
            WHERE supplier_transaction_id = :transaction_id
        ");
        $creditAllocStmt->execute(['transaction_id' => $transactionId]);

        foreach ($creditAllocStmt->fetchAll(PDO::FETCH_ASSOC) as $alloc) {
            $this->adjustBillSettlement($organizationId, (int) $alloc['purchase_id'], 'amount_credited', -(float) $alloc['amount']);
        }

        // Swap debit/credit to reverse
        $reversalType = $original['transaction_type'] . '_REVERSAL';
        if (!in_array($reversalType, ['PAYMENT_REVERSAL', 'PURCHASE_REVERSAL'])) {
            $reversalType = $original['debit'] > 0 ? 'CREDIT_ADJUSTMENT' : 'DEBIT_ADJUSTMENT';
        }

        $desc = 'Reversal of ' . ($original['reference_no'] ?? 'transaction') . ': ' . $reason;

        return $this->insertTransaction(
            $organizationId,
            (int) $original['supplier_id'],
            $reversalType,
            date('Y-m-d'),
            $this->generateReferenceNo($organizationId, 'REV'),
            $desc,
            (float) $original['credit'],  // swap
            (float) $original['debit'],   // swap
            'supplier_transaction',
            $transactionId,
            $user['id']
        );
    }

    /**
     * Expandable-row details for a page of ledger entries: bill line items
     * for purchases, method/UTR and settled bills for payments. Batched so
     * a 25-row page costs a few queries, not one per row.
     */
    public function getEntryDetails(int $organizationId, int $supplierId, array $entries): array
    {
        $purchaseIds = [];
        $paymentIds  = [];
        $creditIds   = [];
        foreach ($entries as $entry) {
            if ((float) $entry['credit'] > 0 && $entry['reference_type'] === null) {
                $creditIds[] = (int) $entry['id'];
            }
            if (!$entry['reference_id']) {
                continue;
            }
            if ($entry['reference_type'] === 'purchase') {
                $purchaseIds[] = (int) $entry['reference_id'];
            } elseif ($entry['reference_type'] === 'supplier_payment') {
                $paymentIds[] = (int) $entry['reference_id'];
            }
        }

        $details = ['purchases' => [], 'purchase_items' => [], 'payments' => [], 'allocations' => [], 'credit_allocations' => []];

        if ($creditIds) {
            $in = implode(',', array_fill(0, count($creditIds), '?'));
            $statement = $this->pdo->prepare("
                SELECT sca.supplier_transaction_id, sca.purchase_id, sca.amount, pu.purchase_no
                FROM supplier_credit_allocations sca
                INNER JOIN purchases pu ON pu.id = sca.purchase_id
                WHERE pu.organization_id = ? AND pu.supplier_id = ? AND sca.supplier_transaction_id IN ($in)
                ORDER BY sca.id
            ");
            $statement->execute(array_merge([$organizationId, $supplierId], $creditIds));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $details['credit_allocations'][(int) $row['supplier_transaction_id']][] = $row;
            }
        }

        if ($purchaseIds) {
            $in = implode(',', array_fill(0, count($purchaseIds), '?'));

            $statement = $this->pdo->prepare("
                SELECT id, purchase_no, total, amount_paid, amount_credited, payment_status, status
                FROM purchases
                WHERE organization_id = ? AND supplier_id = ? AND id IN ($in)
            ");
            $statement->execute(array_merge([$organizationId, $supplierId], $purchaseIds));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $details['purchases'][(int) $row['id']] = $row;
            }

            $statement = $this->pdo->prepare("
                SELECT pi.purchase_id, pi.quantity, pi.unit_cost, pi.total, p.name AS part_name, p.sku, p.unit
                FROM purchase_items pi
                INNER JOIN purchases pu ON pu.id = pi.purchase_id
                LEFT JOIN parts p ON p.id = pi.part_id
                WHERE pu.organization_id = ? AND pu.supplier_id = ? AND pi.purchase_id IN ($in)
                ORDER BY pi.id
            ");
            $statement->execute(array_merge([$organizationId, $supplierId], $purchaseIds));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $details['purchase_items'][(int) $row['purchase_id']][] = $row;
            }
        }

        if ($paymentIds) {
            $in = implode(',', array_fill(0, count($paymentIds), '?'));

            $statement = $this->pdo->prepare("
                SELECT id, method, reference_no, payment_date
                FROM supplier_payments
                WHERE organization_id = ? AND supplier_id = ? AND id IN ($in)
            ");
            $statement->execute(array_merge([$organizationId, $supplierId], $paymentIds));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $details['payments'][(int) $row['id']] = $row;
            }

            $statement = $this->pdo->prepare("
                SELECT spa.supplier_payment_id, spa.purchase_id, spa.amount, pu.purchase_no
                FROM supplier_payment_allocations spa
                INNER JOIN purchases pu ON pu.id = spa.purchase_id
                WHERE pu.organization_id = ? AND pu.supplier_id = ? AND spa.supplier_payment_id IN ($in)
                ORDER BY spa.id
            ");
            $statement->execute(array_merge([$organizationId, $supplierId], $paymentIds));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $details['allocations'][(int) $row['supplier_payment_id']][] = $row;
            }
        }

        return $details;
    }

    /**
     * Balance owed from every transaction dated strictly before $date.
     */
    public function getBalanceBefore(int $organizationId, int $supplierId, string $date): float
    {
        $statement = $this->pdo->prepare("
            SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)
            FROM supplier_transactions
            WHERE organization_id  = :organization_id
              AND supplier_id      = :supplier_id
              AND transaction_date < :date
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId,
            'date'            => $date
        ]);

        return (float) $statement->fetchColumn();
    }

    /**
     * Each reversal paired with the entry it cancels, so the ledger can
     * show them as a linked pair instead of two unrelated rows.
     */
    public function getReversalLinks(int $organizationId, int $supplierId): array
    {
        $statement = $this->pdo->prepare("
            SELECT
                r.id               AS reversal_id,
                r.reference_no     AS reversal_ref,
                r.transaction_date AS reversal_date,
                o.id               AS original_id,
                o.reference_no     AS original_ref,
                o.transaction_date AS original_date
            FROM supplier_transactions r
            INNER JOIN supplier_transactions o
                ON o.id = r.reference_id
               AND o.organization_id = r.organization_id
            WHERE r.organization_id = :organization_id
              AND r.supplier_id     = :supplier_id
              AND r.reference_type  = 'supplier_transaction'
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Whether the supplier has an opening balance that hasn't been reversed.
     */
    public function hasActiveOpeningBalance(int $organizationId, int $supplierId): bool
    {
        $statement = $this->pdo->prepare("
            SELECT 1
            FROM supplier_transactions st
            WHERE st.organization_id  = :organization_id
              AND st.supplier_id      = :supplier_id
              AND st.transaction_type = 'OPENING_BALANCE'
              AND NOT EXISTS (
                  SELECT 1 FROM supplier_transactions r
                  WHERE r.organization_id = st.organization_id
                    AND r.reference_type  = 'supplier_transaction'
                    AND r.reference_id    = st.id
              )
            LIMIT 1
        ");
        $statement->execute(['organization_id' => $organizationId, 'supplier_id' => $supplierId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * IDs of this supplier's transactions that already have a reversal
     * entry pointing back at them.
     */
    public function getReversedTransactionIds(int $organizationId, int $supplierId): array
    {
        $statement = $this->pdo->prepare("
            SELECT reference_id
            FROM supplier_transactions
            WHERE organization_id = :organization_id
              AND supplier_id     = :supplier_id
              AND reference_type  = 'supplier_transaction'
              AND reference_id IS NOT NULL
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    // -----------------------------------------------------------------
    //  Payment plans (informational only, no financial effect)
    // -----------------------------------------------------------------

    public function getPaymentPlans(int $organizationId, int $supplierId): array
    {
        $statement = $this->pdo->prepare("
            SELECT spp.*, u.name AS created_by_name
            FROM supplier_payment_plans spp
            LEFT JOIN users u ON u.id = spp.created_by
            WHERE spp.organization_id = :organization_id
              AND spp.supplier_id     = :supplier_id
            ORDER BY spp.status = 'active' DESC, spp.start_date DESC
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createPaymentPlan(
        int $organizationId,
        int $supplierId,
        float $amountPerInstallment,
        string $frequency,
        ?string $paymentMethod,
        string $startDate,
        ?string $endDate,
        ?float $totalPlanned,
        ?string $notes,
        array $user
    ): int {
        $statement = $this->pdo->prepare("
            INSERT INTO supplier_payment_plans
                (organization_id, supplier_id, amount_per_installment, frequency, payment_method, start_date, end_date, total_planned, notes, created_by)
            VALUES
                (:organization_id, :supplier_id, :amount, :frequency, :method, :start_date, :end_date, :total_planned, :notes, :created_by)
            RETURNING id
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId,
            'amount'          => $amountPerInstallment,
            'frequency'       => $frequency,
            'method'          => $paymentMethod,
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'total_planned'   => $totalPlanned,
            'notes'           => $notes,
            'created_by'      => $user['id']
        ]);

        return (int) $statement->fetchColumn();
    }

    public function updatePaymentPlanStatus(
        int $organizationId,
        int $planId,
        string $newStatus
    ): void {
        $statement = $this->pdo->prepare("
            UPDATE supplier_payment_plans
            SET status = :status, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute([
            'status'          => $newStatus,
            'id'              => $planId,
            'organization_id' => $organizationId
        ]);
    }

    // -----------------------------------------------------------------
    //  Export — returns all ledger rows for statement generation
    // -----------------------------------------------------------------

    public function getStatementData(
        int $organizationId,
        int $supplierId,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $sql = "
            WITH full_ledger AS (
                SELECT
                    id,
                    transaction_type,
                    transaction_date,
                    reference_no,
                    description,
                    debit,
                    credit,
                    SUM(debit - credit) OVER (
                        ORDER BY transaction_date ASC, id ASC
                        ROWS UNBOUNDED PRECEDING
                    ) AS running_balance
                FROM supplier_transactions
                WHERE organization_id = :organization_id
                  AND supplier_id     = :supplier_id
            )
            SELECT
                transaction_type,
                transaction_date,
                reference_no,
                description,
                debit,
                credit,
                running_balance
            FROM full_ledger
            WHERE 1=1
        ";
        $params = [
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ];

        if ($dateFrom) {
            $sql .= " AND transaction_date >= :date_from";
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND transaction_date <= :date_to";
            $params['date_to'] = $dateTo;
        }

        $sql .= " ORDER BY transaction_date ASC, id ASC";

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * All purchases for a given supplier for the Bills/Invoices tab.
     */
    public function getAllPurchases(int $organizationId, int $supplierId): array
    {
        $statement = $this->pdo->prepare("
            SELECT id, purchase_no, subtotal, tax_amount, total, amount_paid, amount_credited, payment_status, status, created_at,
                   CASE WHEN status = 'cancelled' THEN 0 ELSE total - amount_paid - amount_credited END AS balance_due
            FROM purchases
            WHERE organization_id = :organization_id
              AND supplier_id     = :supplier_id
            ORDER BY created_at DESC
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Unpaid purchases for a given supplier — for the payment
     * allocation dropdown in the 'Record Payment' modal.
     */
    public function getUnpaidPurchases(int $organizationId, int $supplierId): array
    {
        $statement = $this->pdo->prepare("
            SELECT id, purchase_no, total, amount_paid, amount_credited, (total - amount_paid - amount_credited) AS balance_due, created_at
            FROM purchases
            WHERE organization_id = :organization_id
              AND supplier_id     = :supplier_id
              AND payment_status != 'paid'
              AND status != 'cancelled'
            ORDER BY created_at ASC, id ASC
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    // -----------------------------------------------------------------
    //  Private helpers
    // -----------------------------------------------------------------

    public const PAYMENT_METHODS = ['cash', 'upi', 'bank_transfer', 'card'];

    /**
     * Reversing a purchase voids the bill itself, not just its ledger line:
     * marks it cancelled (so it stops showing as due and stops receiving
     * payments or credits) and takes its parts back out of stock.
     */
    private function cancelPurchase(int $organizationId, int $purchaseId, array $user): void
    {
        $statement = $this->pdo->prepare("
            SELECT id, purchase_no, branch_id, status, amount_paid, amount_credited
            FROM purchases
            WHERE id = :id AND organization_id = :organization_id
            FOR UPDATE
        ");
        $statement->execute(['id' => $purchaseId, 'organization_id' => $organizationId]);
        $purchase = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$purchase || $purchase['status'] === 'cancelled') {
            return;
        }

        $settled = (float) $purchase['amount_paid'] + (float) $purchase['amount_credited'];
        if ($settled > 0.009) {
            throw new RuntimeException(
                'Bill ' . $purchase['purchase_no'] . ' already has ₹' . number_format($settled, 2)
                . ' paid or credited against it. Reverse those payments or credits first, then cancel the bill.'
            );
        }

        $statement = $this->pdo->prepare("
            SELECT pi.part_id, SUM(pi.quantity) AS quantity, p.name AS part_name,
                   COALESCE(inv.quantity, 0) AS in_stock
            FROM purchase_items pi
            INNER JOIN parts p ON p.id = pi.part_id
            LEFT JOIN inventory inv ON inv.part_id = pi.part_id AND inv.branch_id = :branch_id
            WHERE pi.purchase_id = :purchase_id
            GROUP BY pi.part_id, p.name, inv.quantity
        ");
        $statement->execute(['purchase_id' => $purchaseId, 'branch_id' => $purchase['branch_id']]);
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            if ((float) $item['in_stock'] + 0.0001 < (float) $item['quantity']) {
                throw new RuntimeException(
                    'Only ' . rtrim(rtrim(number_format((float) $item['in_stock'], 2), '0'), '.') . ' of '
                    . $item['part_name'] . ' is left in stock, but bill ' . $purchase['purchase_no'] . ' added '
                    . rtrim(rtrim(number_format((float) $item['quantity'], 2), '0'), '.')
                    . '. Some parts were already used, so the bill can\'t be cancelled — record a return / credit note for the unused parts instead.'
                );
            }
        }

        $removeStock = $this->pdo->prepare("
            UPDATE inventory SET quantity = quantity - :quantity, updated_at = CURRENT_TIMESTAMP
            WHERE part_id = :part_id AND branch_id = :branch_id
        ");
        $logMovement = $this->pdo->prepare("
            INSERT INTO inventory_movements (organization_id, branch_id, part_id, quantity, direction, reason, reference_type, reference_id, created_by)
            VALUES (:organization_id, :branch_id, :part_id, :quantity, 'out', 'adjustment', 'purchase_cancellation', :reference_id, :created_by)
        ");

        foreach ($items as $item) {
            $removeStock->execute([
                'quantity'  => $item['quantity'],
                'part_id'   => $item['part_id'],
                'branch_id' => $purchase['branch_id']
            ]);
            $logMovement->execute([
                'organization_id' => $organizationId,
                'branch_id'       => $purchase['branch_id'],
                'part_id'         => $item['part_id'],
                'quantity'        => $item['quantity'],
                'reference_id'    => $purchaseId,
                'created_by'      => $user['id']
            ]);
        }

        $statement = $this->pdo->prepare("
            UPDATE purchases SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute(['id' => $purchaseId, 'organization_id' => $organizationId]);
    }

    /**
     * Row-lock the supplier for the rest of the caller's transaction. Every
     * write that checks the balance first takes this lock, so two quick
     * submits (double-click, two tabs) run one after the other and the
     * second one sees the first one's effect instead of the same stale
     * balance.
     */
    private function lockSupplier(int $organizationId, int $supplierId): void
    {
        $statement = $this->pdo->prepare("
            SELECT id FROM suppliers
            WHERE id = :id AND organization_id = :organization_id
            FOR UPDATE
        ");
        $statement->execute(['id' => $supplierId, 'organization_id' => $organizationId]);

        if (!$statement->fetchColumn()) {
            throw new RuntimeException('Supplier not found.');
        }
    }

    /**
     * A real calendar date (YYYY-MM-DD) that is not in the future.
     */
    private function assertPastOrToday(string $date, string $label): void
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Enter a valid ' . $label . '.');
        }
        if ($date > date('Y-m-d')) {
            throw new RuntimeException(ucfirst($label) . ' cannot be in the future.');
        }
    }

    private function insertTransaction(
        int $organizationId,
        int $supplierId,
        string $type,
        string $date,
        ?string $referenceNo,
        string $description,
        float $debit,
        float $credit,
        ?string $referenceType,
        ?int $referenceId,
        ?int $userId
    ): int {
        $statement = $this->pdo->prepare("
            INSERT INTO supplier_transactions
                (organization_id, supplier_id, transaction_type, transaction_date,
                 reference_no, description, debit, credit,
                 reference_type, reference_id, created_by)
            VALUES
                (:organization_id, :supplier_id, :type, :date,
                 :reference_no, :description, :debit, :credit,
                 :reference_type, :reference_id, :created_by)
            RETURNING id
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'supplier_id'     => $supplierId,
            'type'            => $type,
            'date'            => $date,
            'reference_no'    => $referenceNo,
            'description'     => $description,
            'debit'           => $debit,
            'credit'          => $credit,
            'reference_type'  => $referenceType,
            'reference_id'    => $referenceId,
            'created_by'      => $userId
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Auto-increment reference numbers: PAY-0001, ADJ-0001, REV-0001.
     * Uses regex match to prevent syntax crashes on non-numeric suffixes.
     */
    private function generateReferenceNo(int $organizationId, string $prefix): string
    {
        $pattern = '^' . preg_quote($prefix, '/') . '-([0-9]+)$';
        // Use SUBSTR(col, pos) instead of SUBSTRING(col FROM pos) — PostgreSQL
        // treats a parameterized FROM value as a regex pattern rather than a
        // positional offset, which silently returns an empty string and causes
        // every generated reference to be PREFIX-0001.
        $skip = strlen($prefix) + 2;  // 'PAY-' = 4 chars → start at position 5
        $statement = $this->pdo->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTR(reference_no, :skip) AS INT)), 0) + 1
            FROM supplier_transactions
            WHERE organization_id = :organization_id
              AND reference_no ~ :pattern
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'pattern'         => $pattern,
            'skip'            => $skip
        ]);
        $next = (int) $statement->fetchColumn();

        return $prefix . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}
