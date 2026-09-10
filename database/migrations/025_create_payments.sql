CREATE TABLE payments (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    branch_id BIGINT NOT NULL,
    invoice_id BIGINT NOT NULL,
    method VARCHAR(20) NOT NULL,
    amount NUMERIC(12, 2) NOT NULL,
    reference_no VARCHAR(100),
    received_by BIGINT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_payments_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_payments_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_payments_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_payments_user
        FOREIGN KEY (received_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_payments_method
        CHECK (method IN ('cash', 'card', 'upi', 'bank_transfer')),

    CONSTRAINT chk_payments_amount
        CHECK (amount > 0)
);
