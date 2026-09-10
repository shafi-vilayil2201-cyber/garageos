CREATE TABLE branches (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL,
    phone VARCHAR(50),
    whatsapp_number VARCHAR(50),
    address TEXT,
    opens_at TIME NOT NULL DEFAULT '09:00',
    closes_at TIME NOT NULL DEFAULT '19:00',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_branches_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT uq_branches_organization_code
        UNIQUE (organization_id, code)
);
