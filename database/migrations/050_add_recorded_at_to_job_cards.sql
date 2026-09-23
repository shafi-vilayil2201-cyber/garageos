-- Timestamps for when the accessories checklist and vehicle-condition
-- diagram were actually captured — set the moment either section is
-- first saved, whether that's at intake or added later from the job
-- card (see public/job-card.php's "Add accessories"/"Add vehicle
-- condition" flow). Displayed next to each section as evidence of
-- when it was recorded, so a customer can't be told a condition was
-- noted at drop-off when the record shows it was actually added days
-- later.
ALTER TABLE job_cards ADD COLUMN accessories_recorded_at TIMESTAMP;
ALTER TABLE job_cards ADD COLUMN damage_recorded_at TIMESTAMP;
