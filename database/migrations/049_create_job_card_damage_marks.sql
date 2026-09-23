-- Structured vehicle-condition records. The flattened image remains useful
-- for printing, while these rows preserve the exact named part and damage
-- type selected by the advisor.
CREATE TABLE job_card_damage_marks (
    id BIGSERIAL PRIMARY KEY,
    job_card_id BIGINT NOT NULL,
    part_key VARCHAR(60) NOT NULL,
    damage_type VARCHAR(1) NOT NULL,
    x NUMERIC(6,2) NOT NULL,
    y NUMERIC(6,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_job_card_damage_marks_job_card
        FOREIGN KEY (job_card_id)
        REFERENCES job_cards(id)
        ON DELETE CASCADE,

    CONSTRAINT uq_job_card_damage_marks_part_type
        UNIQUE (job_card_id, part_key, damage_type),

    CONSTRAINT chk_job_card_damage_marks_type
        CHECK (damage_type IN ('C', 'D', 'S')),

    CONSTRAINT chk_job_card_damage_marks_x
        CHECK (x >= 0 AND x <= 560),

    CONSTRAINT chk_job_card_damage_marks_y
        CHECK (y >= 0 AND y <= 879)
);

CREATE INDEX idx_job_card_damage_marks_job_card_id ON job_card_damage_marks(job_card_id);
