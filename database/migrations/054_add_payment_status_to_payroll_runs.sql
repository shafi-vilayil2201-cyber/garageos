-- Generating a payroll run and actually paying it out are two
-- different moments — the same distinction purchases.payment_status
-- already draws (migration 037), for the same reason: money hasn't
-- actually left the business yet just because a number was computed.
-- finance.php / finance-pnl.php / finance-expenses.php all read
-- payroll_runs directly as the business's payroll cost (deliberately
-- never duplicated into the expenses table — see 037's comment on
-- chk_expenses_category); those queries now only count runs with
-- payment_status = 'paid', so a generated-but-unpaid run doesn't
-- inflate Finance/P&L until it's actually marked paid.
ALTER TABLE payroll_runs
    ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
    ADD COLUMN paid_at TIMESTAMP,
    ADD COLUMN paid_by BIGINT REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE payroll_runs ADD CONSTRAINT chk_payroll_runs_payment_status CHECK (payment_status IN ('unpaid', 'paid'));
