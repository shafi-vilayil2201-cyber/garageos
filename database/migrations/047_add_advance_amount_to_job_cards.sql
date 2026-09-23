-- An optional advance/token amount collected at intake, before any real
-- pricing exists on the job card (parts/services are added later). Purely
-- informational at this stage — not wired into invoicing or payments.

ALTER TABLE job_cards
    ADD COLUMN advance_amount NUMERIC(12, 2);
