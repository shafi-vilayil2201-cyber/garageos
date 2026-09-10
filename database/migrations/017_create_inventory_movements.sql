CREATE TABLE inventory_movements (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    branch_id BIGINT NOT NULL,
    part_id BIGINT NOT NULL,
    quantity NUMERIC(12, 3) NOT NULL,
    direction VARCHAR(10) NOT NULL,
    reason VARCHAR(30) NOT NULL,
    reference_type VARCHAR(30),
    reference_id BIGINT,
    created_by BIGINT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_inventory_movements_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_inventory_movements_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_inventory_movements_part
        FOREIGN KEY (part_id)
        REFERENCES parts(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_inventory_movements_user
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_inventory_movements_direction
        CHECK (direction IN ('in', 'out')),

    CONSTRAINT chk_inventory_movements_reason
        CHECK (reason IN ('purchase', 'job_card', 'adjustment', 'opening_stock', 'damage'))
);
