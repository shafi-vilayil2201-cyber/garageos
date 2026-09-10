CREATE TABLE job_card_parts (
    id BIGSERIAL PRIMARY KEY,
    job_card_id BIGINT NOT NULL,
    part_id BIGINT NOT NULL,
    quantity NUMERIC(10, 2) NOT NULL,
    unit_price NUMERIC(12, 2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_job_card_parts_job_card
        FOREIGN KEY (job_card_id)
        REFERENCES job_cards(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_job_card_parts_part
        FOREIGN KEY (part_id)
        REFERENCES parts(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_job_card_parts_quantity
        CHECK (quantity > 0)
);
