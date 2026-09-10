CREATE TABLE invoices (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    branch_id BIGINT NOT NULL,
    job_card_id BIGINT NOT NULL,
    customer_id BIGINT NOT NULL,
    invoice_no VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
    subtotal NUMERIC(12, 2) NOT NULL DEFAULT 0,
    discount NUMERIC(12, 2) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(12, 2) NOT NULL DEFAULT 0,
    total NUMERIC(12, 2) NOT NULL DEFAULT 0,
    amount_paid NUMERIC(12, 2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_invoices_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_invoices_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_invoices_job_card
        FOREIGN KEY (job_card_id)
        REFERENCES job_cards(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_invoices_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT,

    CONSTRAINT uq_invoices_branch_invoice_no
        UNIQUE (branch_id, invoice_no),

    CONSTRAINT uq_invoices_job_card
        UNIQUE (job_card_id),

    CONSTRAINT chk_invoices_status
        CHECK (status IN ('unpaid', 'partial', 'paid', 'void'))
);
