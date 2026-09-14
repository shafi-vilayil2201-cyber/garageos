-- Adds the 3 permission codes the new attendance/payroll pages need, and
-- retrofits every already-onboarded organization's roles so existing
-- installs get HR access without needing to be re-onboarded. Idempotent:
-- safe to run on a database that already has some of these rows (matches
-- onboard-client.php's own ON CONFLICT pattern for future organizations).

INSERT INTO permissions (name, code, description) VALUES
    ('Manage attendance', 'attendance.manage', 'Manage attendance'),
    ('Manage payroll', 'payroll.manage', 'Manage payroll'),
    ('View own attendance', 'attendance.view_own', 'View own attendance')
ON CONFLICT (code) DO NOTHING;

-- Every existing role, in every organization, gets attendance.view_own —
-- this is what makes each user's own attendance/salary card appear on
-- their dashboard with no per-organization setup.
INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE permissions.code = 'attendance.view_own'
ON CONFLICT DO NOTHING;

-- Only Owner/Manager roles get to mark attendance and run payroll,
-- matching the existing pattern where those two roles get full access
-- except organization settings and user management.
INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.code IN ('OWNER', 'MANAGER')
  AND permissions.code IN ('attendance.manage', 'payroll.manage')
ON CONFLICT DO NOTHING;
