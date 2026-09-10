CREATE TABLE inventory (
    id BIGSERIAL PRIMARY KEY,
    part_id BIGINT NOT NULL,
    branch_id BIGINT NOT NULL,
    quantity NUMERIC(12, 3) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_inventory_part
        FOREIGN KEY (part_id)
        REFERENCES parts(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_inventory_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id)
        ON DELETE RESTRICT,

    CONSTRAINT uq_inventory_part_branch
        UNIQUE (part_id, branch_id),

    CONSTRAINT chk_inventory_quantity
        CHECK (quantity >= 0)
);
