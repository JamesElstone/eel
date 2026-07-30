CREATE TABLE IF NOT EXISTS ct600_return_authorisations (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  declarant_status VARCHAR(64) NOT NULL,
  original_unfiled_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  authority_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  declaration_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  saved_by VARCHAR(100) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_ct600_return_authorisation (company_id, accounting_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ixbrl_accounts_filing_approvals
  ADD COLUMN IF NOT EXISTS declarant_name VARCHAR(100) NULL AFTER approval_note,
  ADD COLUMN IF NOT EXISTS declarant_status VARCHAR(64) NULL AFTER declarant_name,
  ADD COLUMN IF NOT EXISTS original_unfiled_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER declarant_status,
  ADD COLUMN IF NOT EXISTS authority_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER original_unfiled_confirmed,
  ADD COLUMN IF NOT EXISTS declaration_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER authority_confirmed;

CREATE TABLE IF NOT EXISTS ct600_generated_artifacts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL, accounting_period_id INT NOT NULL, ct_period_id INT NOT NULL,
  filing_approval_id BIGINT NOT NULL, filing_approval_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  output_path VARCHAR(1000) NOT NULL, output_filename VARCHAR(255) NOT NULL,
  output_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  validation_status VARCHAR(32) NOT NULL DEFAULT 'passed', generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_ct600_generated_artifact_hash (output_sha256),
  KEY idx_ct600_generated_artifact_current (company_id, accounting_period_id, ct_period_id, filing_approval_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
