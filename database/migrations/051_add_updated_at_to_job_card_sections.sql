-- Tracks corrections separately from the original capture: *_recorded_at
-- is set once, the first time a section is saved, and never touched
-- again; *_updated_at is set every time it's saved again after that
-- (see save_accessories() in Accessory.php and save_vehicle_damage() in
-- VehicleDamage.php). Both timestamps are shown together wherever the
-- section is displayed, so it stays visible when — not just that — a
-- technician corrected a mistaken entry after the fact.
ALTER TABLE job_cards ADD COLUMN accessories_updated_at TIMESTAMP;
ALTER TABLE job_cards ADD COLUMN damage_updated_at TIMESTAMP;
