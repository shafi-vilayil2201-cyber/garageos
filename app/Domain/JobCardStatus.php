<?php

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
// needs id, status, vehicle_id and customer_id (a `jc.*` row already has
// all four).
function apply_job_card_status(PDO $pdo, array $jobCard, string $newStatus, int $organizationId): void
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
