CREATE TABLE vehicles (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    customer_id BIGINT NOT NULL,
    registration_no VARCHAR(20) NOT NULL,
    make VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    year INT,
    color VARCHAR(30),
    vin VARCHAR(32),
    fuel_type VARCHAR(20) NOT NULL DEFAULT 'petrol',
    odometer_km INT NOT NULL DEFAULT 0,
    insurance_expiry DATE,
    puc_expiry DATE,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_vehicles_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_vehicles_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT,

    CONSTRAINT uq_vehicles_organization_registration
        UNIQUE (organization_id, registration_no),

    CONSTRAINT chk_vehicles_fuel_type
        CHECK (fuel_type IN ('petrol', 'diesel', 'ev', 'hybrid', 'cng'))
);
