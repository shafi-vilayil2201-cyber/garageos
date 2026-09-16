-- Shop-wide service interval used to project each vehicle's next
-- service-due date from its odometer history (see app/Domain/ServiceDue.php).
-- Configurable in Settings, same pattern as default_tax_rate.
ALTER TABLE organizations ADD COLUMN service_interval_km INTEGER NOT NULL DEFAULT 5000;
ALTER TABLE organizations ADD COLUMN service_interval_months INTEGER NOT NULL DEFAULT 6;

ALTER TABLE organizations ADD CONSTRAINT chk_organizations_service_interval_km CHECK (service_interval_km > 0);
ALTER TABLE organizations ADD CONSTRAINT chk_organizations_service_interval_months CHECK (service_interval_months > 0);
