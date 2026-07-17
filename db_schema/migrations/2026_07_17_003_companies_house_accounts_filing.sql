CREATE TABLE IF NOT EXISTS companies_house_accounts_eligibility (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  original_document_id BIGINT NULL,
  original_transaction_id VARCHAR(128) NOT NULL,
  original_document_external_id VARCHAR(255) NOT NULL,
  original_filing_channel VARCHAR(50) NOT NULL,
  decision ENUM('pending', 'eligible', 'ineligible') NOT NULL DEFAULT 'pending',
  evidence_text LONGTEXT NOT NULL,
  evidence_reference VARCHAR(255) NULL,
  evidence_received_at DATETIME NULL,
  decided_by VARCHAR(100) NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_accounts_eligibility_source (
    company_id,
    accounting_period_id,
    original_transaction_id
  ),
  KEY idx_ch_accounts_eligibility_period (company_id, accounting_period_id, decision),
  KEY idx_ch_accounts_eligibility_document (original_document_id),
  CONSTRAINT chk_ch_accounts_eligibility_decision
    CHECK (
      (decision = 'pending' AND decided_by IS NULL AND decided_at IS NULL)
      OR
      (decision IN ('eligible', 'ineligible') AND decided_by IS NOT NULL AND decided_at IS NOT NULL)
    ),
  CONSTRAINT fk_ch_accounts_eligibility_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_accounts_eligibility_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_accounts_eligibility_document
    FOREIGN KEY (original_document_id) REFERENCES companies_house_documents (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies_house_accounts_submissions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  eligibility_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  original_document_id BIGINT NULL,
  original_transaction_id VARCHAR(128) NOT NULL,
  original_document_external_id VARCHAR(255) NOT NULL,
  ixbrl_generation_run_id BIGINT NULL,
  environment ENUM('TEST', 'LIVE') NOT NULL,
  filing_type ENUM('revised') NOT NULL DEFAULT 'revised',
  lifecycle ENUM(
    'prepared',
    'submitting',
    'transport_unknown',
    'pending',
    'parked',
    'accepted',
    'rejected',
    'internal_failure',
    'failed'
  ) NOT NULL DEFAULT 'prepared',
  raw_gateway_status VARCHAR(64) NULL,
  submission_number VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NULL,
  gateway_submission_reference VARCHAR(255) NULL,
  revised_artifact_path VARCHAR(1000) NOT NULL,
  revised_artifact_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  basis_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision_declarations_json LONGTEXT NOT NULL,
  gateway_status_summary TEXT NULL,
  rejection_code VARCHAR(100) NULL,
  rejection_description TEXT NULL,
  examiner_comments TEXT NULL,
  prepared_by VARCHAR(100) NOT NULL,
  submitted_by VARCHAR(100) NULL,
  prepared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME NULL,
  last_polled_at DATETIME NULL,
  status_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME NULL,
  rejected_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_accounts_submission_idempotency (environment, idempotency_key),
  UNIQUE KEY uq_ch_accounts_submission_number (environment, submission_number),
  KEY idx_ch_accounts_submission_period (
    company_id,
    accounting_period_id,
    environment,
    lifecycle
  ),
  KEY idx_ch_accounts_submission_eligibility (eligibility_id),
  KEY idx_ch_accounts_submission_document (original_document_id),
  KEY idx_ch_accounts_submission_ixbrl_run (ixbrl_generation_run_id),
  KEY idx_ch_accounts_submission_gateway_status (environment, lifecycle, raw_gateway_status),
  CONSTRAINT chk_ch_accounts_submission_number
    CHECK (submission_number IS NULL OR CHAR_LENGTH(submission_number) = 6),
  CONSTRAINT fk_ch_accounts_submission_eligibility
    FOREIGN KEY (eligibility_id) REFERENCES companies_house_accounts_eligibility (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_accounts_submission_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_accounts_submission_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_accounts_submission_document
    FOREIGN KEY (original_document_id) REFERENCES companies_house_documents (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ch_accounts_submission_ixbrl_run
    FOREIGN KEY (ixbrl_generation_run_id) REFERENCES ixbrl_generation_runs (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies_house_accounts_submission_events (
  id BIGINT NOT NULL AUTO_INCREMENT,
  submission_id BIGINT NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  event_level ENUM('debug', 'info', 'warning', 'error', 'success') NOT NULL DEFAULT 'info',
  lifecycle ENUM(
    'prepared',
    'submitting',
    'transport_unknown',
    'pending',
    'parked',
    'accepted',
    'rejected',
    'internal_failure',
    'failed'
  ) NULL,
  raw_gateway_status VARCHAR(64) NULL,
  event_message TEXT NOT NULL,
  gateway_code VARCHAR(100) NULL,
  gateway_description TEXT NULL,
  examiner_comments TEXT NULL,
  redacted_context_json LONGTEXT NULL,
  actor VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ch_accounts_submission_events_submission (submission_id, created_at),
  KEY idx_ch_accounts_submission_events_status (raw_gateway_status, created_at),
  CONSTRAINT fk_ch_accounts_submission_events_submission
    FOREIGN KEY (submission_id) REFERENCES companies_house_accounts_submissions (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
