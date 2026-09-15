-- Whether an invoice's GST should split as CGST+SGST (intra-state) or
-- show as IGST (inter-state) depends on comparing the seller's state to
-- the buyer's state — total tax amount is unaffected either way, only
-- how it's split/labelled. Both sides of that comparison need a place
-- to live; neither existed before this.

ALTER TABLE organizations ADD COLUMN state VARCHAR(50);
ALTER TABLE customers ADD COLUMN state VARCHAR(50);
