CREATE TABLE payroll_runs (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    period_month DATE NOT NULL,
    days_in_period INT NOT NULL,
    days_present NUMERIC(4, 1) NOT NULL DEFAULT 0,
    days_absent NUMERIC(4, 1) NOT NULL DEFAULT 0,
    days_half_day NUMERIC(4, 1) NOT NULL DEFAULT 0,
    gross_salary NUMERIC(10, 2) NOT NULL DEFAULT 0,
    deduction_amount NUMERIC(10, 2) NOT NULL DEFAULT 0,
    net_salary NUMERIC(10, 2) NOT NULL DEFAULT 0,
    generated_by BIGINT,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_payroll_runs_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_payroll_runs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_payroll_runs_generated_by
        FOREIGN KEY (generated_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT uq_payroll_runs_user_period
        UNIQUE (user_id, period_month)
);

CREATE INDEX idx_payroll_runs_org_period ON payroll_runs (organization_id, period_month);
