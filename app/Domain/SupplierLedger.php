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
        $statement = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN transaction_type = 'PURCHASE'           THEN debit END), 0) AS total_purchases,
                COALESCE(SUM(CASE WHEN transaction_type = 'PAYMENT'            THEN credit END), 0) AS total_payments,
                COALESCE(SUM(CASE WHEN transaction_type IN ('CREDIT_ADJUSTMENT', 'PURCHASE_RETURN', 'REFUND') THEN credit END), 0) AS total_credits,
                COALESCE(SUM(CASE WHEN transaction_type IN ('DEBIT_ADJUSTMENT') THEN debit END), 0) AS total_debits,
                COALESCE(SUM(CASE WHEN transaction_type = 'OPENING_BALANCE'    THEN debit END), 0) AS total_opening,
                COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS outstanding_balance
            FROM supplier_transactions
            WHERE organization_id = :organization_id
              AND supplier_id     = :supplier_id
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
                    id,
                    transaction_type,
                    transaction_date,
                    reference_no,
                    description,
                    debit,
                    credit,
                    reference_type,
                    reference_id,
                    SUM(debit - credit) OVER (
                        ORDER BY transaction_date ASC, id ASC
                        ROWS UNBOUNDED PRECEDING
                    ) AS running_balance,
                    created_at
                FROM supplier_transactions
                WHERE organization_id = :organization_id
                  AND supplier_id     = :supplier_id
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
        $statement = $this->pdo->prepare("
            SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)
            FROM supplier_transactions
            WHERE organization_id = :organization_id
        ");
        $statement->execute(['organization_id' => $organizationId]);

        return max(0, (float) $statement->fetchColumn());
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

        // Generate reference number
        $payRefNo = $this->generateReferenceNo($organizationId, 'PAY');

        // Resolve allocations: if empty, auto-allocate to unpaid purchases in FIFO order
        $resolvedAllocations = [];
        $unallocatedAmount = $amount;

        if (!empty($allocations)) {
            foreach ($allocations as $alloc) {
                $pId = (int) ($alloc['purchase_id'] ?? 0);
                $pAmt = (float) ($alloc['amount'] ?? 0);
                if ($pId > 0 && $pAmt > 0) {
                    $resolvedAllocations[] = ['purchase_id' => $pId, 'amount' => $pAmt];
                    $unallocatedAmount -= $pAmt;
                }
            }
        } else {
            // Auto FIFO allocation
            $unpaid = $this->getUnpaidPurchases($organizationId, $supplierId);
            foreach ($unpaid as $bill) {
                if ($unallocatedAmount <= 0.009) {
                    break;
                }
                $due = (float) $bill['balance_due'];
                $allocAmt = min($unallocatedAmount, $due);
                if ($allocAmt > 0) {
                    $resolvedAllocations[] = [
                        'purchase_id' => (int) $bill['id'],
                        'amount'      => $allocAmt
                    ];
                    $unallocatedAmount -= $allocAmt;
                }
            }
        }

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

            $updatePurchase = $this->pdo->prepare("
                UPDATE purchases
                SET amount_paid = LEAST(total, amount_paid + :amount),
                    payment_status = CASE
                        WHEN amount_paid + :amount2 >= total - 0.01 THEN 'paid'
                        ELSE 'partial'
                    END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND organization_id = :organization_id
            ");

            foreach ($resolvedAllocations as $alloc) {
                $allocStatement->execute([
                    'payment_id'  => $paymentId,
                    'purchase_id' => $alloc['purchase_id'],
                    'amount'      => $alloc['amount']
                ]);

                $updatePurchase->execute([
                    'amount'          => $alloc['amount'],
                    'amount2'         => $alloc['amount'],
                    'id'              => $alloc['purchase_id'],
                    'organization_id' => $organizationId
                ]);
            }
        }

        return $paymentId;
    }

    /**
     * Record a credit adjustment (reduces what the garage owes).
     */
    public function recordCreditAdjustment(
        int $organizationId,
        int $supplierId,
        float $amount,
        string $reason,
        array $user
    ): int {
        $refNo = $this->generateReferenceNo($organizationId, 'ADJ');

        return $this->insertTransaction(
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
        int $transactionId,
        string $reason,
        array $user
    ): int {
        $statement = $this->pdo->prepare("
            SELECT * FROM supplier_transactions
            WHERE id = :id AND organization_id = :organization_id
        ");
        $statement->execute([
            'id'              => $transactionId,
            'organization_id' => $organizationId
        ]);
        $original = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$original) {
            throw new RuntimeException('Transaction not found.');
        }

        // If reversing a payment, roll back the allocations in purchases
        if ($original['reference_type'] === 'supplier_payment' && $original['reference_id']) {
            $allocStmt = $this->pdo->prepare("
                SELECT purchase_id, amount
                FROM supplier_payment_allocations
                WHERE supplier_payment_id = :payment_id
            ");
            $allocStmt->execute(['payment_id' => $original['reference_id']]);
            $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

            $rollbackPurchase = $this->pdo->prepare("
                UPDATE purchases
                SET amount_paid = GREATEST(0, amount_paid - :amount),
                    payment_status = CASE
                        WHEN GREATEST(0, amount_paid - :amount2) <= 0.009 THEN 'unpaid'
                        WHEN GREATEST(0, amount_paid - :amount3) >= total - 0.01 THEN 'paid'
                        ELSE 'partial'
                    END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND organization_id = :organization_id
            ");

            foreach ($allocations as $alloc) {
                $rollbackPurchase->execute([
                    'amount'          => $alloc['amount'],
                    'amount2'         => $alloc['amount'],
                    'amount3'         => $alloc['amount'],
                    'id'              => $alloc['purchase_id'],
                    'organization_id' => $organizationId
                ]);
            }
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
     * Unpaid purchases for a given supplier — for the payment
     * allocation dropdown in the 'Record Payment' modal.
     */
    public function getUnpaidPurchases(int $organizationId, int $supplierId): array
    {
        $statement = $this->pdo->prepare("
            SELECT id, purchase_no, total, amount_paid, (total - amount_paid) AS balance_due, created_at
            FROM purchases
            WHERE organization_id = :organization_id
              AND supplier_id     = :supplier_id
              AND payment_status != 'paid'
            ORDER BY created_at ASC
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
        $statement = $this->pdo->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING(reference_no FROM :skip) AS INT)), 0) + 1
            FROM supplier_transactions
            WHERE organization_id = :organization_id
              AND reference_no ~ :pattern
        ");
        $statement->execute([
            'organization_id' => $organizationId,
            'pattern'         => $pattern,
            'skip'            => strlen($prefix) + 2  // 'PAY-' = 4+1 chars to skip
        ]);
        $next = (int) $statement->fetchColumn();

        return $prefix . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}
