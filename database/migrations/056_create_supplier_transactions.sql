-- Central supplier ledger: every row changes the amount the garage owes
-- that supplier.  Balance at any point = SUM(debit) - SUM(credit) for
-- all rows up to that point, scoped by organization + supplier.
--
-- Debit  = increases what the garage owes  (purchase, debit adjustment, opening balance)
-- Credit = decreases what the garage owes  (payment, credit adjustment, return, refund)

CREATE TABLE supplier_transactions (
    id               BIGSERIAL PRIMARY KEY,
    organization_id  BIGINT        NOT NULL,
    supplier_id      BIGINT        NOT NULL,
    transaction_type VARCHAR(30)   NOT NULL,
    transaction_date DATE          NOT NULL,
    reference_no     VARCHAR(50),
    description      VARCHAR(500)  NOT NULL,
    debit            NUMERIC(12,2) NOT NULL DEFAULT 0,
    credit           NUMERIC(12,2) NOT NULL DEFAULT 0,
    reference_type   VARCHAR(30),
    reference_id     BIGINT,
    created_by       BIGINT,
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_st_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_st_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES suppliers(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_st_user
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_st_type CHECK (transaction_type IN (
        'OPENING_BALANCE',
        'PURCHASE',
        'PAYMENT',
        'PURCHASE_RETURN',
        'CREDIT_ADJUSTMENT',
        'DEBIT_ADJUSTMENT',
        'REFUND',
        'PAYMENT_REVERSAL',
        'PURCHASE_REVERSAL'
    )),

    -- At least one side must be non-zero, and neither side can be negative.
    CONSTRAINT chk_st_amounts CHECK (
        debit >= 0 AND credit >= 0 AND (debit + credit) > 0
    )
);

CREATE INDEX idx_st_org_supplier_date ON supplier_transactions (organization_id, supplier_id, transaction_date, id);
CREATE INDEX idx_st_reference         ON supplier_transactions (reference_type, reference_id);
