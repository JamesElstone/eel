DROP TRIGGER IF EXISTS trg_ixbrl_validation_single_target_insert;
DROP TRIGGER IF EXISTS trg_ixbrl_validation_single_target_update;

ALTER TABLE ixbrl_validation_runs
  DROP CONSTRAINT IF EXISTS chk_ixbrl_validation_target_guard,
  DROP CONSTRAINT IF EXISTS chk_ixbrl_validation_single_target,
  DROP FOREIGN KEY fk_ixbrl_validation_accounts_artifact,
  DROP FOREIGN KEY fk_ixbrl_validation_computation_run,
  DROP COLUMN IF EXISTS target_guard,
  ADD CONSTRAINT fk_ixbrl_validation_accounts_artifact FOREIGN KEY (accounts_artifact_id)
    REFERENCES ixbrl_accounts_artifacts (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  ADD CONSTRAINT fk_ixbrl_validation_computation_run FOREIGN KEY (computation_run_id)
    REFERENCES corporation_tax_computation_runs (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  ADD CONSTRAINT chk_ixbrl_validation_single_target CHECK (
    (accounts_artifact_id IS NOT NULL AND computation_run_id IS NULL)
    OR (accounts_artifact_id IS NULL AND computation_run_id IS NOT NULL)
  );
