ALTER TABLE ixbrl_generation_runs
  ADD COLUMN IF NOT EXISTS external_validator_version VARCHAR(100) DEFAULT NULL AFTER external_validator;
