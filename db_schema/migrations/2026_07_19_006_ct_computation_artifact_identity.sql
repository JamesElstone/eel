ALTER TABLE corporation_tax_computation_runs
  ADD COLUMN IF NOT EXISTS computation_taxonomy_package_hash CHAR(64) DEFAULT NULL AFTER computation_taxonomy_package_id,
  ADD COLUMN IF NOT EXISTS external_validator_version VARCHAR(100) DEFAULT NULL AFTER external_validator;

DELETE FROM role_card_permissions WHERE card_key = 'tax_ct_period_return';
