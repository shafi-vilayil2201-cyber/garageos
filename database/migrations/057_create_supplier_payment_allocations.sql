-- Links a single supplier payment to one or more purchases, allowing
-- partial payments across multiple purchases or a single payment split
-- across invoices.  A payment without any allocation rows is treated as
-- a general balance payment.

CREATE TABLE supplier_payment_allocations (
    id                  BIGSERIAL PRIMARY KEY,
    supplier_payment_id BIGINT        NOT NULL,
    purchase_id         BIGINT        NOT NULL,
    amount              NUMERIC(12,2) NOT NULL,
    created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_spa_payment
        FOREIGN KEY (supplier_payment_id)
        REFERENCES supplier_payments(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_spa_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES purchases(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_spa_amount CHECK (amount > 0)
);

CREATE INDEX idx_spa_payment  ON supplier_payment_allocations (supplier_payment_id);
CREATE INDEX idx_spa_purchase ON supplier_payment_allocations (purchase_id);
