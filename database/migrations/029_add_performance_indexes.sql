-- Indexes on the columns every list/dashboard query actually filters or
-- joins on. Primary keys and UNIQUE constraints already index themselves;
-- this covers the foreign keys and status filters that don't.

CREATE INDEX idx_users_organization ON users (organization_id);

CREATE INDEX idx_customers_organization ON customers (organization_id);

CREATE INDEX idx_vehicles_organization ON vehicles (organization_id);
CREATE INDEX idx_vehicles_customer ON vehicles (customer_id);

CREATE INDEX idx_job_cards_organization_status ON job_cards (organization_id, status);
CREATE INDEX idx_job_cards_vehicle ON job_cards (vehicle_id);
CREATE INDEX idx_job_cards_customer ON job_cards (customer_id);

CREATE INDEX idx_job_card_items_job_card ON job_card_items (job_card_id);
CREATE INDEX idx_job_card_parts_job_card ON job_card_parts (job_card_id);

CREATE INDEX idx_invoices_organization_created ON invoices (organization_id, created_at);
CREATE INDEX idx_invoices_customer ON invoices (customer_id);

CREATE INDEX idx_payments_invoice ON payments (invoice_id);
CREATE INDEX idx_payments_organization_created ON payments (organization_id, created_at);

CREATE INDEX idx_inventory_branch ON inventory (branch_id);

CREATE INDEX idx_inventory_movements_organization_branch ON inventory_movements (organization_id, branch_id);
CREATE INDEX idx_inventory_movements_part ON inventory_movements (part_id);

CREATE INDEX idx_purchases_organization ON purchases (organization_id);
CREATE INDEX idx_purchase_items_purchase ON purchase_items (purchase_id);

CREATE INDEX idx_appointments_organization_status_scheduled
    ON appointments (organization_id, status, scheduled_at);

CREATE INDEX idx_reminders_organization_status_due
    ON reminders (organization_id, status, due_date);

CREATE INDEX idx_sessions_expires_at ON sessions (expires_at);

-- role_permissions and user_roles don't need separate indexes here:
-- their composite primary keys already lead with role_id / user_id,
-- which Postgres can use directly for single-column lookups.
