-- labour_charge already means "rate" in every existing row (quantity was
-- implicitly always 1) — this adds the missing multiplier so staff can
-- enter a rate once and how many times it applies (e.g. two sides),
-- instead of pre-multiplying by hand. Defaulting to 1 is a no-op for
-- every row that already exists: rate * 1 = the exact total it is today.

ALTER TABLE job_card_parts
    ADD COLUMN labour_quantity NUMERIC(10, 2) NOT NULL DEFAULT 1;
