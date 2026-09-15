-- Finance section: a manual expenses ledger for overhead not tied to a
-- purchase order, and payment tracking for purchases (accounts payable),
-- mirroring how invoices already separate order state from payment
-- state. Deliberately does NOT touch purchases.status — that column is
-- fulfillment/stock state (e.g. 'received'), unrelated to whether the
-- supplier has been paid.

CREATE TABLE expenses (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    branch_id BIGINT NOT NULL,
    category VARCHAR(20) NOT NULL,
    description VARCHAR(255),
    amount NUMERIC(12, 2) NOT NULL,
    expense_date DATE NOT NULL,
    created_by BIGINT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_expenses_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_expenses_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_expenses_user
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- No 'salary' category on purpose — salary already lives in
    -- payroll_runs; a manual 'salary' expense row would silently
    -- double-count payroll cost in the Finance overview rollup.
    CONSTRAINT chk_expenses_category
        CHECK (category IN ('rent', 'electricity', 'maintenance', 'misc', 'other')),

    CONSTRAINT chk_expenses_amount
        CHECK (amount > 0)
);

CREATE INDEX idx_expenses_organization_date ON expenses (organization_id, expense_date);

-- Payment tracking for purchases (accounts payable), same shape as
-- invoices' status + amount_paid pair. No amount_paid <= total check —
-- invoices doesn't have one either; it's enforced app-side.
ALTER TABLE purchases ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid';
ALTER TABLE purchases ADD COLUMN amount_paid NUMERIC(12, 2) NOT NULL DEFAULT 0;

ALTER TABLE purchases ADD CONSTRAINT chk_purchases_payment_status
    CHECK (payment_status IN ('unpaid', 'partial', 'paid'));

CREATE TABLE supplier_payments (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    branch_id BIGINT NOT NULL,
    purchase_id BIGINT NOT NULL,
    method VARCHAR(20) NOT NULL,
    amount NUMERIC(12, 2) NOT NULL,
    reference_no VARCHAR(100),
    paid_by BIGINT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_supplier_payments_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_supplier_payments_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_supplier_payments_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES purchases(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_supplier_payments_user
        FOREIGN KEY (paid_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_supplier_payments_method
        CHECK (method IN ('cash', 'card', 'upi', 'bank_transfer')),

    CONSTRAINT chk_supplier_payments_amount
        CHECK (amount > 0)
);

CREATE INDEX idx_supplier_payments_purchase ON supplier_payments (purchase_id);

-- finance.view / finance.manage gate only the 4 new finance*.php pages.
-- Payroll and Purchases keep their own existing permission codes
-- unchanged — moving their sidebar entry doesn't change who can open them.
INSERT INTO permissions (name, code, description) VALUES
    ('View finance', 'finance.view', 'View finance'),
    ('Manage finance', 'finance.manage', 'Manage finance')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.code IN ('OWNER', 'MANAGER', 'ACCOUNTANT')
  AND permissions.code IN ('finance.view', 'finance.manage')
ON CONFLICT DO NOTHING;
