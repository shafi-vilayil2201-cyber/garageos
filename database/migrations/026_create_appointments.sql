CREATE TABLE appointments (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    branch_id BIGINT NOT NULL,
    customer_id BIGINT NOT NULL,
    vehicle_id BIGINT NOT NULL,
    service_id BIGINT,
    scheduled_at TIMESTAMP NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'phone',
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
    notes TEXT,
    job_card_id BIGINT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_appointments_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_appointments_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_appointments_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_appointments_vehicle
        FOREIGN KEY (vehicle_id)
        REFERENCES vehicles(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_appointments_service
        FOREIGN KEY (service_id)
        REFERENCES services(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_appointments_job_card
        FOREIGN KEY (job_card_id)
        REFERENCES job_cards(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_appointments_source
        CHECK (source IN ('landing_page', 'phone', 'walk_in')),

    CONSTRAINT chk_appointments_status
        CHECK (status IN ('scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'))
);
