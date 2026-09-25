-- The three finance pages (finance.php, finance-pnl.php, finance-expenses.php)
-- now filter payroll_runs by paid_at instead of period_month (see migration
-- 054 and the per-employee pay cycle feature that made period_month a mid-
-- month date). This partial index keeps those range scans cheap regardless
-- of how much unpaid or historical payroll data accumulates.
CREATE INDEX idx_payroll_runs_paid_at ON payroll_runs (organization_id, paid_at)
    WHERE payment_status = 'paid';
