ALTER TABLE job_card_parts
    ADD COLUMN technician_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN labour_charge NUMERIC(12, 2) NOT NULL DEFAULT 0;

-- A part's labour charge becomes its own invoice line (see job-card.php's
-- generate_invoice) so it can carry its own GST line and description
-- distinct from the part itself.
ALTER TABLE invoice_items
    DROP CONSTRAINT chk_invoice_items_item_type,
    ADD CONSTRAINT chk_invoice_items_item_type
        CHECK (item_type IN ('service', 'part', 'labour'));
