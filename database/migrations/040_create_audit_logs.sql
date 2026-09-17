-- Records who did what across the app. There's no central place any
-- write goes through (every page does its own inline SQL — see
-- app/Domain/Audit.php), so this table is populated by an explicit
-- call at each significant write site rather than a generic trigger.
--
-- Deliberately just a human-readable description per event, not a
-- field-level before/after diff — that would need snapshotting logic
-- at every write site instead of one line, for a level of detail this
-- app doesn't need yet.
CREATE TABLE audit_logs (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    user_id BIGINT,
    action VARCHAR(20) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT,
    description VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_logs_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    -- SET NULL rather than CASCADE: removing a staff account someday
    -- must never erase what they did while employed.
    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_audit_logs_action
        CHECK (action IN ('create', 'update', 'delete'))
);

CREATE INDEX idx_audit_logs_org_created ON audit_logs (organization_id, created_at DESC);
CREATE INDEX idx_audit_logs_user ON audit_logs (user_id);

-- Owner-only, same precedent as settings.manage/users.manage — both
-- already exclusively OWNER today.
INSERT INTO permissions (name, code, description) VALUES
    ('View audit logs', 'audit.view', 'View audit logs')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.code = 'OWNER'
  AND permissions.code = 'audit.view'
ON CONFLICT DO NOTHING;
