-- Suppliers need a GSTIN on file too, same as customers (migration 030) —
-- claiming input tax credit on a purchase requires the supplier's GST
-- number, not just the buyer's.

ALTER TABLE suppliers ADD COLUMN gstin VARCHAR(15);
