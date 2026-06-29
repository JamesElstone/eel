ALTER TABLE ixbrl_generation_runs
  ADD COLUMN IF NOT EXISTS external_validator varchar(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_validation_status varchar(32) NOT NULL DEFAULT 'not_configured',
  ADD COLUMN IF NOT EXISTS external_validation_errors_json longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_validation_warnings_json longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_validation_log_path varchar(1000) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_validated_at datetime DEFAULT NULL;
