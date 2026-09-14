-- Preserves today's de facto default (parts.php/services.php's "Add" forms
-- hardcode the GST dropdown to 18%) as a real, editable setting instead of
-- markup duplicated across two files.
ALTER TABLE organizations ADD COLUMN default_tax_rate NUMERIC(5, 2) NOT NULL DEFAULT 18.00;
