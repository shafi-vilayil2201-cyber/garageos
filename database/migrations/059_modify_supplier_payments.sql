-- Allow supplier payments that are not tied to a single purchase
-- (general balance payments), and add supplier_id + payment_date
-- directly on the payment row for ledger/report convenience.

ALTER TABLE supplier_payments ALTER COLUMN purchase_id DROP NOT NULL;

ALTER TABLE supplier_payments ADD COLUMN supplier_id  BIGINT;
ALTER TABLE supplier_payments ADD COLUMN payment_date DATE;

-- Backfill from the purchase the payment was already linked to.
UPDATE supplier_payments sp
SET supplier_id  = p.supplier_id,
    payment_date = sp.created_at::date
FROM purchases p
WHERE sp.purchase_id = p.id;

ALTER TABLE supplier_payments ALTER COLUMN supplier_id  SET NOT NULL;
ALTER TABLE supplier_payments ALTER COLUMN payment_date SET NOT NULL;

ALTER TABLE supplier_payments
    ADD CONSTRAINT fk_supplier_payments_supplier
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT;

CREATE INDEX idx_supplier_payments_supplier
    ON supplier_payments (organization_id, supplier_id);
