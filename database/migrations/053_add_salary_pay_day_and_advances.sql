-- Per-employee salary cycle: everyone was paid on the same calendar-
-- month boundary regardless of when they joined. salary_pay_day lets
-- each employee have their own cycle (e.g. 10th-to-9th) instead.
-- Capped at 1-28 to sidestep month-length edge cases (a "31st" cycle
-- has no anchor in February). NULL keeps the existing calendar-month
-- behaviour for every employee already on payroll.
ALTER TABLE users ADD COLUMN salary_pay_day SMALLINT CHECK (salary_pay_day BETWEEN 1 AND 28);

-- A manager-recorded salary advance — no request/approval workflow,
-- matching how attendance and everything else here is manager-entered.
-- Deducted in full from the employee's very next payroll run (never
-- split across periods); settled_in_payroll_run_id is set once that
-- run is generated, so an advance is "outstanding" for exactly as long
-- as this column is NULL.
CREATE TABLE salary_advances (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    amount NUMERIC(10, 2) NOT NULL CHECK (amount > 0),
    paid_at DATE NOT NULL,
    notes VARCHAR(300),
    settled_in_payroll_run_id BIGINT,
    created_by BIGINT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_salary_advances_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,

    CONSTRAINT fk_salary_advances_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    CONSTRAINT fk_salary_advances_payroll_run
        FOREIGN KEY (settled_in_payroll_run_id) REFERENCES payroll_runs(id) ON DELETE SET NULL,

    CONSTRAINT fk_salary_advances_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_salary_advances_org_user ON salary_advances (organization_id, user_id);

-- Only queried when settled_in_payroll_run_id IS NULL (the "does this
-- employee have an outstanding advance" check payroll generation runs
-- every time) — a partial index keeps that cheap regardless of how
-- much settled history piles up.
CREATE INDEX idx_salary_advances_outstanding ON salary_advances (user_id) WHERE settled_in_payroll_run_id IS NULL;

-- Kept separate from the existing deduction_amount (attendance-based)
-- so a payslip can show "docked for 2 absent days" and "advance
-- recovered" as two distinct lines instead of one merged number.
ALTER TABLE payroll_runs ADD COLUMN advance_deducted NUMERIC(10, 2) NOT NULL DEFAULT 0;
