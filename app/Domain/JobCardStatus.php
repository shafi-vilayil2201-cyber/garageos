<?php

require_once __DIR__ . '/Audit.php';

// A job card moves forward through its lifecycle and never back — once a
// vehicle is marked Delivered, it cannot return to Received or In Progress.
// This is enforced here once, and both the status dropdown on the job
// card page and the kanban board's drag-and-drop call into it, so neither
// path can be used to bypass the rule.

const JOB_CARD_STATUS_RANK = [
    'received' => 1,
    'in_progress' => 2,
    'on_hold' => 2,
    'quality_check' => 3,
    'ready' => 4,
    'delivered' => 5,
];

function job_card_status_rank(string $status): int
{
    return JOB_CARD_STATUS_RANK[$status] ?? 0;
}

// Same-rank moves (in_progress <-> on_hold) are lateral, not backward, and
// allowed. Cancelling is allowed from any active job card. Delivered and
// cancelled are both terminal — nothing moves out of them.
function job_card_can_transition(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }

    if (in_array($from, ['delivered', 'cancelled'], true)) {
        return false;
    }

    if ($to === 'cancelled') {
        return true;
    }

    return job_card_status_rank($to) >= job_card_status_rank($from);
}

// Performs the status change itself, plus the one side effect that comes
// with it: delivering a vehicle resolves any pending "come back for
// service" reminder and starts the countdown to the next one. $jobCard
// needs id, job_no, status, vehicle_id and customer_id (a `jc.*` row already
// has all but job_no, which every caller's SELECT includes too).
function apply_job_card_status(PDO $pdo, array $jobCard, string $newStatus, int $organizationId, array $user): void
{
    $becomingDelivered = $newStatus === 'delivered' && $jobCard['status'] !== 'delivered';

    $statement = $pdo->prepare("
        UPDATE job_cards
        SET status = :status,
            closed_at = " . ($newStatus === 'delivered' ? 'CURRENT_TIMESTAMP' : 'closed_at') . ",
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $statement->execute(['status' => $newStatus, 'id' => $jobCard['id']]);

    log_audit_event(
        $pdo, $user, 'update', 'job_card', $jobCard['id'],
        "Changed job card {$jobCard['job_no']} status to " . str_replace('_', ' ', $newStatus)
    );

    if (!$becomingDelivered) {
        return;
    }

    $statement = $pdo->prepare("
        UPDATE reminders
        SET status = 'dismissed'
        WHERE vehicle_id = :vehicle_id AND due_type = 'service_due' AND status = 'pending'
    ");
    $statement->execute(['vehicle_id' => $jobCard['vehicle_id']]);

    $statement = $pdo->prepare("
        INSERT INTO reminders (organization_id, customer_id, vehicle_id, due_type, due_date)
        VALUES (:organization_id, :customer_id, :vehicle_id, 'service_due', CURRENT_DATE + INTERVAL '90 days')
        ON CONFLICT (vehicle_id, due_type, due_date) DO NOTHING
    ");
    $statement->execute([
        'organization_id' => $organizationId,
        'customer_id' => $jobCard['customer_id'],
        'vehicle_id' => $jobCard['vehicle_id']
    ]);
}

// Soft delete — hides the job card from the kanban board, dashboard
// counts and reminders without destroying it; it stays visible (and
// restorable) from the customer's own profile, not a separate admin
// area. Blocked once an invoice exists, matching the app's existing
// rule that nothing about an invoiced job card can change after
// billing (see $canRemoveLines in job-card.php) — returns an error
// string instead of throwing, so the caller can show it inline the
// same way every other business-rule rejection in this app works.
function soft_delete_job_card(PDO $pdo, array $jobCard, array $user, bool $hasInvoice): ?string
{
    if ($hasInvoice) {
        return 'This job card has an invoice and cannot be deleted — cancel or void the invoice first.';
    }

    $statement = $pdo->prepare("
        UPDATE job_cards
        SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $statement->execute(['deleted_by' => $user['id'], 'id' => $jobCard['id']]);

    log_audit_event(
        $pdo, $user, 'delete', 'job_card', $jobCard['id'],
        "Deleted job card {$jobCard['job_no']}"
    );

    return null;
}

// Owner-only at the call site (job_cards.restore permission) — see the
// migration for why restoring is more restricted than deleting.
function restore_job_card(PDO $pdo, array $jobCard, array $user): void
{
    $statement = $pdo->prepare("
        UPDATE job_cards
        SET deleted_at = NULL, deleted_by = NULL, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $statement->execute(['id' => $jobCard['id']]);

    log_audit_event(
        $pdo, $user, 'update', 'job_card', $jobCard['id'],
        "Restored job card {$jobCard['job_no']}"
    );
}
