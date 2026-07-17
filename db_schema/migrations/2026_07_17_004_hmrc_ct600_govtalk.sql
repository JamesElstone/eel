-- Downstream HMRC Corporation Tax Online XML/GovTalk submission state.
-- TEST and TIL are validation-only; only LIVE final acceptance is statutory.

-- A UTR is an identifier, not a number. Preserve any leading zeroes.
UPDATE company_settings
SET type = 'char'
WHERE setting = 'utr';

ALTER TABLE hmrc_ct600_submissions
  MODIFY COLUMN mode enum('TEST','TIL','LIVE') NOT NULL,
  ADD COLUMN IF NOT EXISTS environment enum('TEST','TIL','LIVE') NOT NULL DEFAULT 'TEST' AFTER mode,
  ADD COLUMN IF NOT EXISTS protocol_state enum(
    'prepared','validation_failed','ready','submitting','awaiting_poll',
    'final_received','delete_pending','closed','transport_uncertain','invalidated'
  ) NOT NULL DEFAULT 'prepared' AFTER status,
  ADD COLUMN IF NOT EXISTS business_outcome enum(
    'none','sandbox_passed','til_validated','live_accepted','rejected','error'
  ) NOT NULL DEFAULT 'none' AFTER protocol_state,
  ADD COLUMN IF NOT EXISTS idempotency_key char(64) DEFAULT NULL AFTER package_hash,
  ADD COLUMN IF NOT EXISTS transaction_id varchar(64) DEFAULT NULL AFTER idempotency_key,
  ADD COLUMN IF NOT EXISTS response_endpoint varchar(1000) DEFAULT NULL AFTER hmrc_correlation_id,
  ADD COLUMN IF NOT EXISTS poll_interval_seconds int(11) DEFAULT NULL AFTER response_endpoint,
  ADD COLUMN IF NOT EXISTS next_poll_at datetime DEFAULT NULL AFTER poll_interval_seconds,
  ADD COLUMN IF NOT EXISTS poll_attempts int(11) NOT NULL DEFAULT 0 AFTER next_poll_at,
  ADD COLUMN IF NOT EXISTS irmark varchar(64) DEFAULT NULL AFTER poll_attempts,
  ADD COLUMN IF NOT EXISTS schema_version varchar(50) DEFAULT NULL AFTER irmark,
  ADD COLUMN IF NOT EXISTS body_sha256 char(64) DEFAULT NULL AFTER schema_version,
  ADD COLUMN IF NOT EXISTS ct600_sha256 char(64) DEFAULT NULL AFTER body_sha256,
  ADD COLUMN IF NOT EXISTS accounts_run_id bigint(20) DEFAULT NULL AFTER accounts_ixbrl_path,
  ADD COLUMN IF NOT EXISTS accounts_sha256 char(64) DEFAULT NULL AFTER accounts_run_id,
  ADD COLUMN IF NOT EXISTS computation_run_id int(11) DEFAULT NULL AFTER computations_ixbrl_path,
  ADD COLUMN IF NOT EXISTS computations_sha256 char(64) DEFAULT NULL AFTER computation_run_id,
  ADD COLUMN IF NOT EXISTS year_end_locked_at datetime DEFAULT NULL AFTER computations_sha256,
  ADD COLUMN IF NOT EXISTS manifest_path varchar(1000) DEFAULT NULL AFTER request_body_path,
  ADD COLUMN IF NOT EXISTS response_sha256 char(64) DEFAULT NULL AFTER response_body_path,
  ADD COLUMN IF NOT EXISTS declarant_name varchar(255) DEFAULT NULL AFTER validation_json,
  ADD COLUMN IF NOT EXISTS declarant_status varchar(255) DEFAULT NULL AFTER declarant_name,
  ADD COLUMN IF NOT EXISTS declaration_confirmed tinyint(1) NOT NULL DEFAULT 0 AFTER declarant_status,
  ADD COLUMN IF NOT EXISTS supplementary_scope_confirmed tinyint(1) NOT NULL DEFAULT 0 AFTER declaration_confirmed,
  ADD COLUMN IF NOT EXISTS original_unfiled_confirmed tinyint(1) NOT NULL DEFAULT 0 AFTER supplementary_scope_confirmed,
  ADD COLUMN IF NOT EXISTS declaration_approved_at datetime DEFAULT NULL AFTER original_unfiled_confirmed,
  ADD COLUMN IF NOT EXISTS declaration_approved_by varchar(255) DEFAULT NULL AFTER declaration_approved_at,
  ADD COLUMN IF NOT EXISTS approved_package_hash char(64) DEFAULT NULL AFTER declaration_approved_by,
  ADD COLUMN IF NOT EXISTS prepared_by varchar(255) DEFAULT NULL AFTER approved_package_hash,
  ADD COLUMN IF NOT EXISTS submitted_by_user_id bigint(20) DEFAULT NULL AFTER submitted_by,
  ADD COLUMN IF NOT EXISTS final_response_at datetime DEFAULT NULL AFTER submitted_at,
  ADD COLUMN IF NOT EXISTS cleanup_completed_at datetime DEFAULT NULL AFTER final_response_at,
  ADD COLUMN IF NOT EXISTS cleanup_response_path varchar(1000) DEFAULT NULL AFTER cleanup_completed_at,
  ADD COLUMN IF NOT EXISTS cleanup_response_sha256 char(64) DEFAULT NULL AFTER cleanup_response_path,
  ADD COLUMN IF NOT EXISTS cleanup_error text DEFAULT NULL AFTER cleanup_response_path,
  ADD COLUMN IF NOT EXISTS recovery_attempts int(11) NOT NULL DEFAULT 0 AFTER cleanup_error,
  ADD COLUMN IF NOT EXISTS last_recovery_at datetime DEFAULT NULL AFTER recovery_attempts,
  ADD COLUMN IF NOT EXISTS invalidated_at datetime DEFAULT NULL AFTER last_recovery_at,
  ADD COLUMN IF NOT EXISTS invalidation_reason text DEFAULT NULL AFTER invalidated_at,
  ADD UNIQUE KEY IF NOT EXISTS uq_hmrc_ct600_idempotency (idempotency_key),
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_environment_outcome (environment, business_outcome),
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_poll_due (protocol_state, next_poll_at);

UPDATE hmrc_ct600_submissions
SET environment = mode
WHERE environment IS NULL OR environment <> mode;

ALTER TABLE corporation_tax_computation_runs
  ADD COLUMN IF NOT EXISTS generated_filename varchar(255) DEFAULT NULL AFTER generated_path,
  ADD COLUMN IF NOT EXISTS taxonomy_profile varchar(100) DEFAULT NULL AFTER generated_filename,
  ADD COLUMN IF NOT EXISTS validation_status varchar(32) NOT NULL DEFAULT 'not_validated' AFTER taxonomy_profile,
  ADD COLUMN IF NOT EXISTS validation_errors_json longtext DEFAULT NULL AFTER validation_status,
  ADD COLUMN IF NOT EXISTS external_validator varchar(50) DEFAULT NULL AFTER validation_errors_json,
  ADD COLUMN IF NOT EXISTS external_validation_status varchar(32) NOT NULL DEFAULT 'not_configured' AFTER external_validator,
  ADD COLUMN IF NOT EXISTS external_validation_errors_json longtext DEFAULT NULL AFTER external_validation_status,
  ADD COLUMN IF NOT EXISTS external_validation_warnings_json longtext DEFAULT NULL AFTER external_validation_errors_json,
  ADD COLUMN IF NOT EXISTS external_validation_log_path varchar(1000) DEFAULT NULL AFTER external_validation_warnings_json,
  ADD COLUMN IF NOT EXISTS external_validated_at datetime DEFAULT NULL AFTER external_validation_log_path,
  ADD COLUMN IF NOT EXISTS output_sha256 char(64) DEFAULT NULL AFTER external_validated_at,
  ADD COLUMN IF NOT EXISTS external_validated_sha256 char(64) DEFAULT NULL AFTER output_sha256;

CREATE TABLE IF NOT EXISTS hmrc_obligation_submission_links (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  hmrc_obligation_id int(11) NOT NULL,
  submission_id bigint(20) NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_hmrc_obligation_submission (hmrc_obligation_id, submission_id),
  KEY idx_hmrc_obligation_submission_submission (submission_id),
  CONSTRAINT fk_hmrc_obligation_submission_obligation
    FOREIGN KEY (hmrc_obligation_id) REFERENCES hmrc_obligations (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_obligation_submission_submission
    FOREIGN KEY (submission_id) REFERENCES hmrc_ct600_submissions (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
