-- Allow custom services without a catalog reference
ALTER TABLE job_card_items ALTER COLUMN service_id DROP NOT NULL;

-- Store the display name and price at time of entry (for both preset and custom)
ALTER TABLE job_card_items ADD COLUMN custom_name VARCHAR(200);
ALTER TABLE job_card_items ADD COLUMN custom_price NUMERIC(12, 2);

-- Ensure at least one of service_id or custom_name is provided
ALTER TABLE job_card_items ADD CONSTRAINT chk_service_or_custom
    CHECK (service_id IS NOT NULL OR custom_name IS NOT NULL);
