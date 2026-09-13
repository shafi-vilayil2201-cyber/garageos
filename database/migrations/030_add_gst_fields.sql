-- GST invoicing support: HSN code for goods (parts), SAC code for services,
-- the customer's own GSTIN (for B2B invoices claiming input tax credit),
-- and a snapshot of whichever code applied on each invoice line — line
-- items already snapshot tax_rate the same way, for the same reason: an
-- invoice must never change retroactively just because the catalog did.

ALTER TABLE parts ADD COLUMN hsn_code VARCHAR(20);
ALTER TABLE services ADD COLUMN sac_code VARCHAR(20);
ALTER TABLE customers ADD COLUMN gstin VARCHAR(15);
ALTER TABLE invoice_items ADD COLUMN hsn_sac_code VARCHAR(20);
