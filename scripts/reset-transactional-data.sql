-- Clears all transactional/demo records while preserving the
-- organization, logins, roles/permissions, and the parts/services/
-- suppliers catalog. Deletes in an order that satisfies every FK
-- RESTRICT constraint in the schema (checked directly against
-- information_schema before writing this). job_no/invoice_no are
-- computed as MAX(existing)+1 (see public/job-card-new.php and
-- public/job-card.php), not a separate sequence, so the next job
-- card/invoice created after this automatically starts at 0001 again
-- with no extra step needed.
--
-- Run once, interactively, after taking a fresh backup:
--   psql ... -f reset-transactional-data.sql
--
-- Wrapped in a transaction: if anything fails partway, nothing is
-- committed. Review the row counts it prints before trusting them, and
-- explicitly COMMIT or ROLLBACK is left to you at the bottom.

BEGIN;

CREATE TEMP TABLE _reset_counts (table_name TEXT, rows_deleted BIGINT);

DO $$
DECLARE
    n BIGINT;
BEGIN
    DELETE FROM payments; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('payments', n);

    DELETE FROM supplier_payments; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('supplier_payments', n);

    DELETE FROM invoice_items; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('invoice_items', n);

    DELETE FROM invoices; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('invoices', n);

    DELETE FROM purchase_items; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('purchase_items', n);

    DELETE FROM purchases; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('purchases', n);

    DELETE FROM job_card_items; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('job_card_items', n);

    DELETE FROM job_card_parts; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('job_card_parts', n);

    DELETE FROM appointments; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('appointments', n);

    DELETE FROM reminders; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('reminders', n);

    DELETE FROM job_cards; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('job_cards', n);

    DELETE FROM vehicles; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('vehicles', n);

    DELETE FROM customers; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('customers', n);

    DELETE FROM attendance; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('attendance', n);

    DELETE FROM payroll_runs; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('payroll_runs', n);

    DELETE FROM inventory_movements; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('inventory_movements', n);

    DELETE FROM inventory; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('inventory', n);

    DELETE FROM expenses; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('expenses', n);

    DELETE FROM audit_logs; GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO _reset_counts VALUES ('audit_logs', n);
END $$;

SELECT * FROM _reset_counts ORDER BY table_name;

-- Review the counts above. If they look right:
COMMIT;
-- If anything looks wrong, run ROLLBACK; instead of COMMIT; above.
