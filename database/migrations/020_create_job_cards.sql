CREATE TABLE job_cards (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    branch_id BIGINT NOT NULL,
    job_no VARCHAR(20) NOT NULL,
    customer_id BIGINT NOT NULL,
    vehicle_id BIGINT NOT NULL,
    advisor_id BIGINT,
    primary_technician_id BIGINT,
    status VARCHAR(20) NOT NULL DEFAULT 'received',
    customer_complaint TEXT,
    odometer_in INT,
    odometer_out INT,
    promised_at TIMESTAMP,
    closed_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_job_cards_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_job_cards_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_job_cards_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_job_cards_vehicle
        FOREIGN KEY (vehicle_id)
        REFERENCES vehicles(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_job_cards_advisor
        FOREIGN KEY (advisor_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_job_cards_technician
        FOREIGN KEY (primary_technician_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT uq_job_cards_branch_job_no
        UNIQUE (branch_id, job_no),

    CONSTRAINT chk_job_cards_status
        CHECK (status IN ('received', 'in_progress', 'quality_check', 'ready', 'delivered', 'on_hold', 'cancelled'))
);
