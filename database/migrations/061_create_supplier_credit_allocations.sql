-- Credit notes (discounts / returns / refunds) now reduce specific bills,
-- the same way payments do through supplier_payment_allocations. Before
-- this, a credit lowered the supplier's ledger balance but no bill, so the
-- Bills tab kept showing money due that the ledger said was not owed.
--
-- A bill's balance due is now: total - amount_paid - amount_credited.

ALTER TABLE purchases
    ADD COLUMN amount_credited NUMERIC(12,2) NOT NULL DEFAULT 0;

CREATE TABLE supplier_credit_allocations (
    id                      BIGSERIAL PRIMARY KEY,
    supplier_transaction_id BIGINT        NOT NULL,
    purchase_id             BIGINT        NOT NULL,
    amount                  NUMERIC(12,2) NOT NULL,
    created_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_sca_transaction
        FOREIGN KEY (supplier_transaction_id)
        REFERENCES supplier_transactions(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_sca_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES purchases(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_sca_amount CHECK (amount > 0)
);

CREATE INDEX idx_sca_transaction ON supplier_credit_allocations (supplier_transaction_id);
CREATE INDEX idx_sca_purchase    ON supplier_credit_allocations (purchase_id);

-- Backfill: apply every existing, still-active credit note to that
-- supplier's bills oldest-first. Credits and bill balances are each laid
-- end to end as running ranges per supplier; a credit covers a bill by
-- however much their ranges overlap. Any credit beyond all bill balances
-- (e.g. against an opening balance) stays unallocated, as before.
WITH credits AS (
    SELECT
        st.id,
        st.organization_id,
        st.supplier_id,
        SUM(st.credit) OVER w - st.credit AS range_start,
        SUM(st.credit) OVER w             AS range_end
    FROM supplier_transactions st
    WHERE st.transaction_type IN ('CREDIT_ADJUSTMENT', 'PURCHASE_RETURN', 'REFUND')
      AND st.credit > 0
      AND st.reference_type IS DISTINCT FROM 'supplier_transaction'
      AND NOT EXISTS (
          SELECT 1 FROM supplier_transactions r
          WHERE r.reference_type = 'supplier_transaction'
            AND r.reference_id   = st.id
      )
    WINDOW w AS (PARTITION BY st.organization_id, st.supplier_id ORDER BY st.transaction_date, st.id)
),
bills AS (
    SELECT
        p.id,
        p.organization_id,
        p.supplier_id,
        SUM(p.total - p.amount_paid) OVER w - (p.total - p.amount_paid) AS range_start,
        SUM(p.total - p.amount_paid) OVER w                             AS range_end
    FROM purchases p
    WHERE p.total - p.amount_paid > 0.009
    WINDOW w AS (PARTITION BY p.organization_id, p.supplier_id ORDER BY p.created_at, p.id)
)
INSERT INTO supplier_credit_allocations (supplier_transaction_id, purchase_id, amount)
SELECT
    c.id,
    b.id,
    LEAST(c.range_end, b.range_end) - GREATEST(c.range_start, b.range_start)
FROM credits c
INNER JOIN bills b
    ON b.organization_id = c.organization_id
   AND b.supplier_id     = c.supplier_id
WHERE LEAST(c.range_end, b.range_end) - GREATEST(c.range_start, b.range_start) > 0.009;

UPDATE purchases p
SET amount_credited = sca.total_credited,
    payment_status = CASE
        WHEN p.amount_paid + sca.total_credited >= p.total - 0.01 THEN 'paid'
        ELSE 'partial'
    END,
    updated_at = CURRENT_TIMESTAMP
FROM (
    SELECT purchase_id, SUM(amount) AS total_credited
    FROM supplier_credit_allocations
    GROUP BY purchase_id
) sca
WHERE sca.purchase_id = p.id;
