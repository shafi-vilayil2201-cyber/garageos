-- Which of the fixed accessory checklist items (see app/Domain/Accessory.php)
-- were physically present in the vehicle at intake — for accountability, so
-- there's a record of what was there before work started. Sparse: one row
-- per item that was actually ticked, not one row per possible item.

CREATE TABLE job_card_accessories (
    id BIGSERIAL PRIMARY KEY,
    job_card_id BIGINT NOT NULL,
    accessory_key VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_job_card_accessories_job_card
        FOREIGN KEY (job_card_id)
        REFERENCES job_cards(id)
        ON DELETE CASCADE,

    CONSTRAINT uq_job_card_accessories_job_card_key
        UNIQUE (job_card_id, accessory_key)
);

CREATE INDEX idx_job_card_accessories_job_card_id ON job_card_accessories (job_card_id);
