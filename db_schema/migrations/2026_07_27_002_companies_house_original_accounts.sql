ALTER TABLE companies_house_accounts_submissions
  ADD COLUMN IF NOT EXISTS artifact_path VARCHAR(1000) NULL AFTER gateway_submission_reference,
  ADD COLUMN IF NOT EXISTS artifact_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER artifact_path,
  ADD COLUMN IF NOT EXISTS filing_metadata_json LONGTEXT NULL AFTER idempotency_key;

UPDATE companies_house_accounts_submissions
SET artifact_path = COALESCE(artifact_path, revised_artifact_path),
    artifact_sha256 = COALESCE(artifact_sha256, revised_artifact_sha256),
    filing_metadata_json = COALESCE(filing_metadata_json, revision_declarations_json);

ALTER TABLE companies_house_accounts_submissions
  MODIFY COLUMN eligibility_id BIGINT NULL,
  MODIFY COLUMN original_transaction_id VARCHAR(128) NULL,
  MODIFY COLUMN original_document_external_id VARCHAR(255) NULL,
  MODIFY COLUMN filing_type ENUM('original', 'revised') NOT NULL DEFAULT 'revised',
  MODIFY COLUMN revised_artifact_path VARCHAR(1000) NULL,
  MODIFY COLUMN revised_artifact_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN filing_metadata_json LONGTEXT NULL,
  MODIFY COLUMN revision_declarations_json LONGTEXT NULL;
