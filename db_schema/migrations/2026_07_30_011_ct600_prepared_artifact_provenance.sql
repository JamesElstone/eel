ALTER TABLE ct600_generated_artifacts
  ADD COLUMN IF NOT EXISTS source_manifest_json LONGTEXT NULL AFTER source_manifest_sha256,
  ADD COLUMN IF NOT EXISTS ct_filing_basis_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER source_manifest_json,
  ADD COLUMN IF NOT EXISTS accounts_run_id BIGINT NULL AFTER ct_filing_basis_hash,
  ADD COLUMN IF NOT EXISTS accounts_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER accounts_run_id,
  ADD COLUMN IF NOT EXISTS computation_run_id BIGINT NULL AFTER accounts_sha256,
  ADD COLUMN IF NOT EXISTS computations_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER computation_run_id,
  ADD COLUMN IF NOT EXISTS rim_package_id BIGINT NULL AFTER computations_sha256,
  ADD COLUMN IF NOT EXISTS rim_form_version VARCHAR(64) NULL AFTER rim_package_id,
  ADD COLUMN IF NOT EXISTS rim_artifact_version VARCHAR(64) NULL AFTER rim_form_version,
  ADD COLUMN IF NOT EXISTS rim_package_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER rim_artifact_version,
  ADD COLUMN IF NOT EXISTS mapping_profile_id BIGINT NULL AFTER rim_package_sha256,
  ADD COLUMN IF NOT EXISTS mapping_revision_no INT NULL AFTER mapping_profile_id,
  ADD COLUMN IF NOT EXISTS mapping_content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER mapping_revision_no,
  ADD COLUMN IF NOT EXISTS serializer_version VARCHAR(64) NULL AFTER mapping_content_hash,
  ADD COLUMN IF NOT EXISTS package_version VARCHAR(64) NULL AFTER serializer_version,
  ADD COLUMN IF NOT EXISTS irmark VARCHAR(255) NULL AFTER package_version,
  ADD COLUMN IF NOT EXISTS validation_json LONGTEXT NULL AFTER irmark;

ALTER TABLE ct600_generated_artifacts
  DROP INDEX IF EXISTS uq_ct600_generated_artifact_hash,
  ADD KEY IF NOT EXISTS idx_ct600_generated_artifact_source (
    company_id, accounting_period_id, ct_period_id, filing_approval_id,
    accounts_run_id, computation_run_id
  ),
  ADD UNIQUE KEY IF NOT EXISTS uq_ct600_generated_artifact_source_manifest (
    company_id, accounting_period_id, ct_period_id, source_manifest_sha256
  ),
  ADD KEY IF NOT EXISTS idx_ct600_generated_artifact_hash (output_sha256);
