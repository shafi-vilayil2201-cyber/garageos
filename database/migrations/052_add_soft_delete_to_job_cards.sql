-- Soft delete for job cards — a mistakenly-created job card can be
-- removed from the active board without destroying its data. Deleted
-- rows stay in the table (still visible from the customer's own
-- profile, not a separate admin area) and can be brought back.
--
-- deleted_by is nullable/SET NULL for the same reason audit_logs.user_id
-- is: removing a staff account someday must never erase what they did
-- while employed, or break this column's foreign key.
ALTER TABLE job_cards ADD COLUMN deleted_at TIMESTAMP;
ALTER TABLE job_cards ADD COLUMN deleted_by BIGINT REFERENCES users(id) ON DELETE SET NULL;

-- Every hot list (kanban board, dashboard) filters on
-- organization_id + deleted_at IS NULL; a partial index keeps that
-- filter cheap without bloating the index with deleted rows.
CREATE INDEX idx_job_cards_active ON job_cards (organization_id) WHERE deleted_at IS NULL;

-- Restoring is Owner-only — same precedent as audit.view/settings.manage
-- (see migration 040): deleting a job card is a routine correction any
-- manager can do, but undoing that correction (bringing a record back
-- that someone else removed) is reserved for the Owner.
INSERT INTO permissions (name, code, description) VALUES
    ('Restore job cards', 'job_cards.restore', 'Restore a soft-deleted job card')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.code = 'OWNER'
  AND permissions.code = 'job_cards.restore'
ON CONFLICT DO NOTHING;
