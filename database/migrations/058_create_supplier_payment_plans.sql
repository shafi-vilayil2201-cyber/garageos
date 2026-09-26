-- Scheduled payment arrangements with a supplier — informational only.
-- A plan row does NOT reduce the outstanding balance; an actual payment
-- must be recorded separately.  The UI uses this to show "next payment
-- date" and "remaining planned amount" alongside the real ledger.

CREATE TABLE supplier_payment_plans (
    id                     BIGSERIAL PRIMARY KEY,
    organization_id        BIGINT        NOT NULL,
    supplier_id            BIGINT        NOT NULL,
    amount_per_installment NUMERIC(12,2) NOT NULL,
    frequency              VARCHAR(20)   NOT NULL DEFAULT 'monthly',
    payment_method         VARCHAR(20),
    start_date             DATE          NOT NULL,
    end_date               DATE,
    total_planned          NUMERIC(12,2),
    notes                  VARCHAR(500),
    status                 VARCHAR(20)   NOT NULL DEFAULT 'active',
    created_by             BIGINT,
    created_at             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_spp_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_spp_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES suppliers(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_spp_user
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_spp_frequency CHECK (frequency IN ('weekly', 'monthly', 'quarterly')),
    CONSTRAINT chk_spp_status    CHECK (status IN ('active', 'paused', 'completed', 'cancelled')),
    CONSTRAINT chk_spp_method    CHECK (payment_method IS NULL OR payment_method IN ('cash', 'card', 'upi', 'bank_transfer')),
    CONSTRAINT chk_spp_amount    CHECK (amount_per_installment > 0)
);

CREATE INDEX idx_spp_org_supplier ON supplier_payment_plans (organization_id, supplier_id);
