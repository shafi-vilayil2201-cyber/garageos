-- Wipes ALL business data so a workshop can start fresh after testing:
-- job cards, invoices, payments, customers, vehicles, appointments,
-- reminders, purchases, the supplier ledger, suppliers, parts, stock,
-- services, attendance, payroll, salary advances, expenses and the
-- audit log.
--
-- Keeps: organizations, branches, users, roles, permissions, user and
-- role assignments, active sessions, and the migrations table.
-- Running numbers (JC-, INV-, PUR-, SUP-, PAY-, ADJ-, REV-) are computed
-- as MAX(existing)+1, so they restart at 0001 automatically.
--
-- Refuses to run if the database holds more than one organization, so it
-- can't wipe another workshop's data by accident.
--
-- Run only after taking a fresh backup:
--   sudo -u postgres psql -d garageos -v ON_ERROR_STOP=1 -f scripts/reset-all-test-data.sql
--
-- Runs in one transaction: if any statement fails, nothing is deleted.

\set ON_ERROR_STOP on

BEGIN;

DO $$
DECLARE
    org_count INT;
BEGIN
    SELECT COUNT(*) INTO org_count FROM organizations;
    IF org_count <> 1 THEN
        RAISE EXCEPTION 'Expected exactly 1 organization, found %. Aborting without changes.', org_count;
    END IF;
END $$;

CREATE TEMP TABLE _reset_counts (step INT, table_name TEXT, rows_deleted BIGINT);

DO $$
DECLARE
    n BIGINT;
    step INT := 0;
    t TEXT;
    -- Order matters: every table is deleted before anything it
    -- references with ON DELETE RESTRICT. Child rows of invoices,
    -- purchases and job cards go with their parents (ON DELETE CASCADE).
    tables TEXT[] := ARRAY[
        'supplier_credit_allocations',
        'supplier_payment_allocations',
        'supplier_payments',
        'supplier_transactions',
        'supplier_payment_plans',
        'payments',
        'invoice_items',
        'invoices',
        'purchase_items',
        'purchases',
        'appointments',
        'reminders',
        'job_card_items',
        'job_card_parts',
        'job_card_accessories',
        'job_card_damage_marks',
        'job_cards',
        'vehicles',
        'customers',
        'salary_advances',
        'payroll_runs',
        'attendance',
        'inventory_movements',
        'inventory',
        'expenses',
        'audit_logs',
        'suppliers',
        'parts',
        'part_categories',
        'services',
        'service_categories'
    ];
BEGIN
    FOREACH t IN ARRAY tables LOOP
        step := step + 1;
        EXECUTE format('DELETE FROM %I', t);
        GET DIAGNOSTICS n = ROW_COUNT;
        INSERT INTO _reset_counts VALUES (step, t, n);
    END LOOP;
END $$;

\echo
\echo '== Rows deleted =='
SELECT table_name, rows_deleted FROM _reset_counts ORDER BY step;

\echo '== Kept =='
SELECT 'organizations' AS kept, COUNT(*) FROM organizations
UNION ALL SELECT 'branches', COUNT(*) FROM branches
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'roles', COUNT(*) FROM roles
UNION ALL SELECT 'user_roles', COUNT(*) FROM user_roles
UNION ALL SELECT 'permissions', COUNT(*) FROM permissions;

COMMIT;

\echo 'Reset complete.'
