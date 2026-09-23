-- Vehicle condition at intake keeps a flattened marked image for print and
-- evidence (see app/Domain/DamageImage.php). Migration 049 stores the exact
-- named part and damage type separately; this column holds the image URL.

ALTER TABLE job_cards ADD COLUMN damage_image_url VARCHAR(255);
