CREATE TABLE job_card_items (
    id BIGSERIAL PRIMARY KEY,
    job_card_id BIGINT NOT NULL,
    service_id BIGINT NOT NULL,
    technician_id BIGINT,
    price NUMERIC(12, 2) NOT NULL,
    discount NUMERIC(12, 2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_job_card_items_job_card
        FOREIGN KEY (job_card_id)
        REFERENCES job_cards(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_job_card_items_service
        FOREIGN KEY (service_id)
        REFERENCES services(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_job_card_items_technician
        FOREIGN KEY (technician_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_job_card_items_status
        CHECK (status IN ('pending', 'done'))
);
