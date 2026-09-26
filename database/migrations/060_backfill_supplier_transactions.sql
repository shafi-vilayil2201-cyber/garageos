-- Backfill the new supplier_transactions ledger from existing purchase
-- and payment data so every organisation's historical balance is
-- correct from day one.

-- 1. Purchases → debit entries
INSERT INTO supplier_transactions
    (organization_id, supplier_id, transaction_type, transaction_date,
     reference_no, description, debit, credit,
     reference_type, reference_id, created_at)
SELECT
    p.organization_id,
    p.supplier_id,
    'PURCHASE',
    p.created_at::date,
    p.purchase_no,
    'Purchase ' || p.purchase_no,
    p.total,
    0,
    'purchase',
    p.id,
    p.created_at
FROM purchases p;

-- 2. Supplier payments → credit entries
INSERT INTO supplier_transactions
    (organization_id, supplier_id, transaction_type, transaction_date,
     reference_no, description, debit, credit,
     reference_type, reference_id, created_at)
SELECT
    sp.organization_id,
    sp.supplier_id,
    'PAYMENT',
    sp.payment_date,
    sp.reference_no,
    COALESCE(
        'Payment via ' || sp.method || COALESCE(' — ' || sp.reference_no, ''),
        'Payment'
    ),
    0,
    sp.amount,
    'supplier_payment',
    sp.id,
    sp.created_at
FROM supplier_payments sp;

-- 3. Backfill payment allocations from existing 1:1 purchase–payment links
INSERT INTO supplier_payment_allocations
    (supplier_payment_id, purchase_id, amount)
SELECT sp.id, sp.purchase_id, sp.amount
FROM supplier_payments sp
WHERE sp.purchase_id IS NOT NULL;
