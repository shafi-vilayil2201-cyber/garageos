ALTER TABLE users
    ADD COLUMN designation VARCHAR(100),
    ADD COLUMN salary_type VARCHAR(20) CHECK (salary_type IN ('monthly', 'daily_wage')),
    ADD COLUMN salary_amount NUMERIC(10, 2),
    ADD COLUMN joined_at DATE;
