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
// every advance with no settled_in_payroll_run_id yet, summed (in the
// ordinary case there's at most one outstanding at a time, since the
// next run always clears whatever's there, but summing rather than
// assuming exactly one keeps this correct even if two were recorded
// before payroll caught up).
function outstanding_advance_for_user(PDO $pdo, int $organizationId, int $userId): float
{
    $statement = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM salary_advances
        WHERE organization_id = :organization_id AND user_id = :user_id AND settled_in_payroll_run_id IS NULL
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
        WHERE organization_id = :organization_id AND user_id = :user_id AND settled_in_payroll_run_id IS NULL
    ");
    $statement->execute([
        'payroll_run_id' => $payrollRunId,
        'organization_id' => $organizationId,
        'user_id' => $userId
    ]);
}
