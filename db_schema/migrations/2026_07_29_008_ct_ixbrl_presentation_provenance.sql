ALTER TABLE corporation_tax_computation_runs
  ADD COLUMN IF NOT EXISTS ixbrl_tagging_version VARCHAR(100) NULL AFTER ixbrl_mapping_hash,
  ADD COLUMN IF NOT EXISTS ixbrl_presentation_version VARCHAR(100) NULL AFTER ixbrl_tagging_version;
