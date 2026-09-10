CREATE TABLE purchase_items (
    id BIGSERIAL PRIMARY KEY,
    purchase_id BIGINT NOT NULL,
    part_id BIGINT NOT NULL,
    quantity NUMERIC(12, 3) NOT NULL,
    unit_cost NUMERIC(12, 2) NOT NULL,
    tax_rate NUMERIC(5, 2) NOT NULL DEFAULT 0,
    total NUMERIC(12, 2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_purchase_items_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES purchases(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_purchase_items_part
        FOREIGN KEY (part_id)
        REFERENCES parts(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_purchase_items_quantity
        CHECK (quantity > 0)
);
