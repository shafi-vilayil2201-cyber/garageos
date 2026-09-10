CREATE TABLE parts (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    part_category_id BIGINT,
    name VARCHAR(200) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    barcode VARCHAR(100),
    cost_price NUMERIC(12, 2) NOT NULL DEFAULT 0,
    selling_price NUMERIC(12, 2) NOT NULL DEFAULT 0,
    tax_rate NUMERIC(5, 2) NOT NULL DEFAULT 0,
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    reorder_level NUMERIC(10, 2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_parts_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_parts_category
        FOREIGN KEY (part_category_id)
        REFERENCES part_categories(id)
        ON DELETE SET NULL,

    CONSTRAINT uq_parts_organization_sku
        UNIQUE (organization_id, sku)
);
