<?php

// Manager-recorded, no request/approval workflow — matching how
// attendance and everything else HR-related in this app is entered
// directly by whoever manages it, not submitted by the employee.

function record_salary_advance(PDO $pdo, int $organizationId, int $userId, float $amount, string $paidAt, ?string $notes, int $createdBy): void
{
    $statement = $pdo->prepare("
        INSERT INTO salary_advances (organization_id, user_id, amount, paid_at, notes, created_by)
        VALUES (:organization_id, :user_id, :amount, :paid_at, :notes, :created_by)
    ");
    $statement->execute([
        'organization_id' => $organizationId,
        'user_id' => $userId,
        'amount' => $amount,
        'paid_at' => $paidAt,
        'notes' => $notes,
        'created_by' => $createdBy
    ]);
}

// The total still owed against this employee's next payroll run —
// every advance not yet deducted by a run and not closed outside payroll
// (repaid / written off), summed. Summing rather than assuming exactly
// one keeps this correct even if two were recorded before payroll
// caught up.
function outstanding_advance_for_user(PDO $pdo, int $organizationId, int $userId): float
{
    $statement = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM salary_advances
        WHERE organization_id = :organization_id AND user_id = :user_id
          AND settled_in_payroll_run_id IS NULL AND recovered_at IS NULL
    ");
    $statement->execute(['organization_id' => $organizationId, 'user_id' => $userId]);

    return (float) $statement->fetchColumn();
}

// Marks every currently-outstanding advance for this employee as
// settled by this payroll run — called right after that run is
// inserted, inside the same transaction.
function settle_outstanding_advances(PDO $pdo, int $organizationId, int $userId, int $payrollRunId): void
{
    $statement = $pdo->prepare("
        UPDATE salary_advances
        SET settled_in_payroll_run_id = :payroll_run_id
        WHERE organization_id = :organization_id AND user_id = :user_id
          AND settled_in_payroll_run_id IS NULL AND recovered_at IS NULL
    ");
    $statement->execute([
        'payroll_run_id' => $payrollRunId,
        'organization_id' => $organizationId,
        'user_id' => $userId
    ]);
}

// Reverses settle_outstanding_advances() for one run — puts whatever
// it previously settled back into the "outstanding" pool. Called right
// before recalculating an existing unpaid run, so a second advance
// recorded after the first recalculation doesn't silently replace the
// first one's deduction: every advance ever tied to this run becomes
// outstanding again, then gets swept back up together in one sum by
// the settle call that follows the recalculation.
function unsettle_advances_for_run(PDO $pdo, int $payrollRunId): void
{
    $statement = $pdo->prepare("UPDATE salary_advances SET settled_in_payroll_run_id = NULL WHERE settled_in_payroll_run_id = :payroll_run_id");
    $statement->execute(['payroll_run_id' => $payrollRunId]);
}

// Closes every outstanding advance for an employee outside payroll —
// repaid directly or written off. Returns the total closed (0 if there
// was nothing outstanding).
function close_outstanding_advances(PDO $pdo, int $organizationId, int $userId, string $method, ?string $note, int $closedBy): float
{
    if (!in_array($method, ['repaid', 'written_off'], true)) {
        throw new InvalidArgumentException('Unknown recovery method.');
    }

    $statement = $pdo->prepare("
        UPDATE salary_advances
        SET recovered_at = CURRENT_TIMESTAMP,
            recovery_method = :method,
            recovery_note = :note,
            recovered_by = :closed_by
        WHERE organization_id = :organization_id AND user_id = :user_id
          AND settled_in_payroll_run_id IS NULL AND recovered_at IS NULL
        RETURNING amount
    ");
    $statement->execute([
        'method' => $method,
        'note' => $note,
        'closed_by' => $closedBy,
        'organization_id' => $organizationId,
        'user_id' => $userId
    ]);

    return (float) array_sum($statement->fetchAll(PDO::FETCH_COLUMN));
}

// Employees whose outstanding advance no payroll run will ever recover,
// because they're inactive or no longer on salary. Payroll only lists
// active salaried staff, so without this these amounts would never show
// up anywhere.
function stranded_salary_advances(PDO $pdo, int $organizationId): array
{
    $statement = $pdo->prepare("
        SELECT u.id, u.name, u.status, u.salary_type, SUM(sa.amount) AS outstanding
        FROM salary_advances sa
        INNER JOIN users u ON u.id = sa.user_id AND u.organization_id = sa.organization_id
        WHERE sa.organization_id = :organization_id
          AND sa.settled_in_payroll_run_id IS NULL AND sa.recovered_at IS NULL
          AND (u.status <> 'active' OR u.salary_type IS NULL)
        GROUP BY u.id, u.name, u.status, u.salary_type
        ORDER BY u.name
    ");
    $statement->execute(['organization_id' => $organizationId]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
