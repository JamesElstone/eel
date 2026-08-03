CREATE TABLE IF NOT EXISTS ixbrl_accounts_artifacts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  generation_run_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  filing_approval_id BIGINT NOT NULL,
  filing_approval_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  authority VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  filing_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  profile_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  profile_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  profile_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  render_model_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  transformation_registry_uri VARCHAR(1000) NOT NULL,
  taxonomy_profile VARCHAR(100) NULL,
  taxonomy_package_id BIGINT NULL,
  taxonomy_package_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  generation_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'generated',
  output_path VARCHAR(1000) NOT NULL,
  output_filename VARCHAR(255) NOT NULL,
  output_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ixbrl_accounts_artifact_build (
    generation_run_id, authority, filing_kind, profile_fingerprint, render_model_sha256
  ),
  KEY idx_ixbrl_accounts_artifact_current (
    company_id, accounting_period_id, authority, filing_kind, profile_key, id
  ),
  KEY idx_ixbrl_accounts_artifact_approval (filing_approval_id, filing_approval_hash),
  KEY idx_ixbrl_accounts_artifact_output (output_sha256),
  CONSTRAINT fk_ixbrl_accounts_artifact_run FOREIGN KEY (generation_run_id)
    REFERENCES ixbrl_generation_runs (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ixbrl_accounts_artifact_company FOREIGN KEY (company_id)
    REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ixbrl_accounts_artifact_period FOREIGN KEY (accounting_period_id)
    REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ixbrl_accounts_artifact_approval FOREIGN KEY (filing_approval_id)
    REFERENCES ixbrl_accounts_filing_approvals (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_ixbrl_accounts_artifact_authority
    CHECK (authority IN ('HMRC', 'COMPANIES_HOUSE')),
  CONSTRAINT chk_ixbrl_accounts_artifact_filing_kind
    CHECK (filing_kind IN ('ordinary', 'original', 'revised'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ixbrl_validation_runs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  accounts_artifact_id BIGINT NULL,
  computation_run_id INT NULL,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  ct_period_id INT NULL,
  authority VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  profile_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  profile_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  profile_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  artifact_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  taxonomy_package_id BIGINT NULL,
  taxonomy_package_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  validator_name VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  validator_version VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  validator_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  options_json LONGTEXT NULL,
  options_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_conformance_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'not_run',
  source_conformance_results_json LONGTEXT NULL,
  core_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'not_run',
  core_results_json LONGTEXT NULL,
  authority_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'not_run',
  authority_results_json LONGTEXT NULL,
  arelle_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'not_run',
  arelle_results_json LONGTEXT NULL,
  arelle_log_path VARCHAR(1000) NULL,
  overall_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  validated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ixbrl_validation_accounts (accounts_artifact_id, overall_status, id),
  KEY idx_ixbrl_validation_computation (computation_run_id, overall_status, id),
  KEY idx_ixbrl_validation_context (company_id, accounting_period_id, authority, profile_key, id),
  KEY idx_ixbrl_validation_artifact_fingerprint (artifact_sha256, profile_fingerprint, overall_status),
  CONSTRAINT fk_ixbrl_validation_accounts_artifact FOREIGN KEY (accounts_artifact_id)
    REFERENCES ixbrl_accounts_artifacts (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_ixbrl_validation_computation_run FOREIGN KEY (computation_run_id)
    REFERENCES corporation_tax_computation_runs (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_ixbrl_validation_company FOREIGN KEY (company_id)
    REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ixbrl_validation_period FOREIGN KEY (accounting_period_id)
    REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ixbrl_validation_ct_period FOREIGN KEY (ct_period_id)
    REFERENCES corporation_tax_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_ixbrl_validation_authority
    CHECK (authority IN ('HMRC', 'COMPANIES_HOUSE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hmrc_ct_filing_approvals (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  accounts_filing_approval_id BIGINT NOT NULL,
  accounts_filing_approval_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  return_authorisation_id BIGINT NOT NULL,
  return_authorisation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  return_authorisation_json LONGTEXT NOT NULL,
  ct_scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ct_scope_json LONGTEXT NOT NULL,
  basis_version VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  basis_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  basis_json LONGTEXT NOT NULL,
  approved_by VARCHAR(100) NOT NULL,
  approval_note TEXT NULL,
  approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  legacy_combined_approval_id BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hmrc_ct_filing_approval_period (company_id, accounting_period_id, id),
  KEY idx_hmrc_ct_filing_approval_basis (basis_hash),
  KEY idx_hmrc_ct_filing_approval_accounts (accounts_filing_approval_id, accounts_filing_approval_hash),
  KEY idx_hmrc_ct_filing_approval_authorisation (return_authorisation_id, return_authorisation_hash),
  KEY idx_hmrc_ct_filing_approval_legacy (legacy_combined_approval_id),
  CONSTRAINT fk_hmrc_ct_filing_approval_company FOREIGN KEY (company_id)
    REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_ct_filing_approval_period FOREIGN KEY (accounting_period_id)
    REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_ct_filing_approval_accounts FOREIGN KEY (accounts_filing_approval_id)
    REFERENCES ixbrl_accounts_filing_approvals (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_ct_filing_approval_authorisation FOREIGN KEY (return_authorisation_id)
    REFERENCES ct600_return_authorisations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_ct_filing_approval_legacy FOREIGN KEY (legacy_combined_approval_id)
    REFERENCES ixbrl_accounts_filing_approvals (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ct_period_filing_bases
  ADD COLUMN IF NOT EXISTS hmrc_ct_filing_approval_id BIGINT NULL AFTER filing_approval_id,
  ADD KEY IF NOT EXISTS idx_ct_period_filing_basis_hmrc_approval (hmrc_ct_filing_approval_id, ct_period_id),
  ADD CONSTRAINT fk_ct_period_filing_basis_hmrc_approval FOREIGN KEY (hmrc_ct_filing_approval_id)
    REFERENCES hmrc_ct_filing_approvals (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE companies_house_accounts_submissions
  ADD COLUMN IF NOT EXISTS accounts_artifact_id BIGINT NULL AFTER ixbrl_generation_run_id,
  ADD COLUMN IF NOT EXISTS accounts_validation_run_id BIGINT NULL AFTER accounts_artifact_id,
  ADD KEY IF NOT EXISTS idx_ch_accounts_submission_artifact (accounts_artifact_id),
  ADD KEY IF NOT EXISTS idx_ch_accounts_submission_validation (accounts_validation_run_id),
  ADD CONSTRAINT fk_ch_accounts_submission_artifact FOREIGN KEY (accounts_artifact_id)
    REFERENCES ixbrl_accounts_artifacts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ch_accounts_submission_validation FOREIGN KEY (accounts_validation_run_id)
    REFERENCES ixbrl_validation_runs (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE ct600_generated_artifacts
  ADD COLUMN IF NOT EXISTS hmrc_ct_filing_approval_id BIGINT NULL AFTER filing_approval_id,
  ADD COLUMN IF NOT EXISTS hmrc_ct_filing_approval_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER hmrc_ct_filing_approval_id,
  ADD COLUMN IF NOT EXISTS accounts_artifact_id BIGINT NULL AFTER accounts_run_id,
  ADD COLUMN IF NOT EXISTS accounts_validation_run_id BIGINT NULL AFTER accounts_artifact_id,
  ADD COLUMN IF NOT EXISTS computation_validation_run_id BIGINT NULL AFTER computation_run_id,
  ADD KEY IF NOT EXISTS idx_ct600_generated_hmrc_approval (hmrc_ct_filing_approval_id),
  ADD KEY IF NOT EXISTS idx_ct600_generated_accounts_artifact (accounts_artifact_id),
  ADD KEY IF NOT EXISTS idx_ct600_generated_accounts_validation (accounts_validation_run_id),
  ADD KEY IF NOT EXISTS idx_ct600_generated_computation_validation (computation_validation_run_id),
  ADD CONSTRAINT fk_ct600_generated_hmrc_approval FOREIGN KEY (hmrc_ct_filing_approval_id)
    REFERENCES hmrc_ct_filing_approvals (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ct600_generated_accounts_artifact FOREIGN KEY (accounts_artifact_id)
    REFERENCES ixbrl_accounts_artifacts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ct600_generated_accounts_validation FOREIGN KEY (accounts_validation_run_id)
    REFERENCES ixbrl_validation_runs (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ct600_generated_computation_validation FOREIGN KEY (computation_validation_run_id)
    REFERENCES ixbrl_validation_runs (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE hmrc_ct600_submissions
  ADD COLUMN IF NOT EXISTS hmrc_ct_filing_approval_id BIGINT NULL AFTER ct_period_id,
  ADD COLUMN IF NOT EXISTS hmrc_ct_filing_approval_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER hmrc_ct_filing_approval_id,
  ADD COLUMN IF NOT EXISTS accounts_artifact_id BIGINT NULL AFTER accounts_run_id,
  ADD COLUMN IF NOT EXISTS accounts_validation_run_id BIGINT NULL AFTER accounts_artifact_id,
  ADD COLUMN IF NOT EXISTS computation_validation_run_id BIGINT NULL AFTER computation_run_id,
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_filing_approval (hmrc_ct_filing_approval_id),
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_accounts_artifact (accounts_artifact_id),
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_accounts_validation (accounts_validation_run_id),
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_computation_validation (computation_validation_run_id),
  ADD CONSTRAINT fk_hmrc_ct600_filing_approval FOREIGN KEY (hmrc_ct_filing_approval_id)
    REFERENCES hmrc_ct_filing_approvals (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_hmrc_ct600_accounts_artifact FOREIGN KEY (accounts_artifact_id)
    REFERENCES ixbrl_accounts_artifacts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_hmrc_ct600_accounts_validation FOREIGN KEY (accounts_validation_run_id)
    REFERENCES ixbrl_validation_runs (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_hmrc_ct600_computation_validation FOREIGN KEY (computation_validation_run_id)
    REFERENCES ixbrl_validation_runs (id) ON DELETE RESTRICT ON UPDATE CASCADE;
