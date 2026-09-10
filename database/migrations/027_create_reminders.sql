CREATE TABLE reminders (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    customer_id BIGINT NOT NULL,
    vehicle_id BIGINT NOT NULL,
    due_type VARCHAR(30) NOT NULL,
    due_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    channel VARCHAR(20),
    sent_at TIMESTAMP,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_reminders_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_reminders_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_reminders_vehicle
        FOREIGN KEY (vehicle_id)
        REFERENCES vehicles(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_reminders_due_type
        CHECK (due_type IN ('service_due', 'insurance_expiry', 'puc_expiry', 'follow_up')),

    CONSTRAINT chk_reminders_status
        CHECK (status IN ('pending', 'sent', 'dismissed')),

    CONSTRAINT chk_reminders_channel
        CHECK (channel IS NULL OR channel IN ('sms', 'whatsapp', 'email', 'call')),

    CONSTRAINT uq_reminders_vehicle_due_type_due_date
        UNIQUE (vehicle_id, due_type, due_date)
);
