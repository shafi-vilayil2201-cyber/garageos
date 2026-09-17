<?php

// Records one human-readable line per significant write, since there's
// no central place writes go through in this app (every page does its
// own inline SQL) — see database/migrations/040_create_audit_logs.sql.
// Call this once per business action, not once per SQL statement: e.g.
// generating an invoice from a job card writes to two tables but is
// one call here, describing the one thing that actually happened.
function log_audit_event(
    PDO $pdo,
    array $user,
    string $action,
    string $entityType,
    ?int $entityId,
    string $description
): void {
    $statement = $pdo->prepare("
        INSERT INTO audit_logs (organization_id, user_id, action, entity_type, entity_id, description)
        VALUES (:organization_id, :user_id, :action, :entity_type, :entity_id, :description)
    ");
    $statement->execute([
        'organization_id' => $user['organization_id'],
        'user_id' => $user['id'],
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'description' => $description
    ]);
}
