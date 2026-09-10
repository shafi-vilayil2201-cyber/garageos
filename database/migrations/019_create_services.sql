CREATE TABLE services (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    service_category_id BIGINT,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(50) NOT NULL,
    standard_price NUMERIC(12, 2) NOT NULL DEFAULT 0,
    tax_rate NUMERIC(5, 2) NOT NULL DEFAULT 0,
    estimated_minutes INT NOT NULL DEFAULT 30,
    vehicle_type VARCHAR(20) NOT NULL DEFAULT 'all',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_services_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_services_category
        FOREIGN KEY (service_category_id)
        REFERENCES service_categories(id)
        ON DELETE SET NULL,

    CONSTRAINT uq_services_organization_code
        UNIQUE (organization_id, code),

    CONSTRAINT chk_services_vehicle_type
        CHECK (vehicle_type IN ('all', 'car', 'bike', 'commercial'))
);
