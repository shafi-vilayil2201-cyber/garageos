-- An advance was only ever cleared by a payroll run deducting it. If the
-- employee left (inactive) or was taken off salary before that run, the
-- advance stayed "outstanding" forever with nothing able to recover it.
-- These columns let a manager close it outside payroll: repaid directly
-- (e.g. in cash) or written off.

ALTER TABLE salary_advances
    ADD COLUMN recovered_at    TIMESTAMP,
    ADD COLUMN recovery_method VARCHAR(20),
    ADD COLUMN recovery_note   VARCHAR(300),
    ADD COLUMN recovered_by    BIGINT,
    ADD CONSTRAINT chk_salary_advances_recovery_method
        CHECK (recovery_method IS NULL OR recovery_method IN ('repaid', 'written_off'));
