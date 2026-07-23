-- Durable Companies House XML Gateway preflight, poll/ack and document
-- conversation state. Authentication codes remain only in private XML evidence.

CREATE TABLE IF NOT EXISTS companies_house_company_auth_preflights (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  submission_id bigint(20) NOT NULL,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  environment enum('TEST','LIVE') NOT NULL,
  output_presenter_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schema_snapshot_id bigint(20) NOT NULL,
  schema_manifest_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  transaction_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  outcome enum('sending','verified','rejected','transport_unknown','failed') NOT NULL,
  matched_company_number varchar(8) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  matched_company_name varchar(160) DEFAULT NULL,
  binding_hmac char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  binding_actor varchar(100) DEFAULT NULL,
  binding_expires_at datetime DEFAULT NULL,
  consumed_at datetime DEFAULT NULL,
  error_summary text DEFAULT NULL,
  archive_reference varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_path varchar(1000) DEFAULT NULL,
  request_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  response_path varchar(1000) DEFAULT NULL,
  response_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  checked_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_company_auth_preflight_reference (archive_reference),
  KEY idx_ch_company_auth_preflight_submission (submission_id, outcome, created_at),
  CONSTRAINT fk_ch_company_auth_preflight_submission
    FOREIGN KEY (submission_id) REFERENCES companies_house_accounts_submissions (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_company_auth_preflight_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_company_auth_preflight_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_company_auth_preflight_schema
    FOREIGN KEY (schema_snapshot_id) REFERENCES companies_house_schema_snapshots (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies_house_protocol_exchanges (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  submission_id bigint(20) DEFAULT NULL,
  preflight_id bigint(20) DEFAULT NULL,
  status_cycle_id bigint(20) DEFAULT NULL,
  operation enum('company_data','accounts','submission_status','status_ack','get_document') NOT NULL,
  environment enum('TEST','LIVE') NOT NULL,
  transaction_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  exchange_state enum('prepared','sent','received','succeeded','rejected','transport_unknown','failed') NOT NULL,
  request_path varchar(1000) DEFAULT NULL,
  request_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  response_path varchar(1000) DEFAULT NULL,
  response_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  response_status_code int(11) DEFAULT NULL,
  error_summary text DEFAULT NULL,
  sent_at datetime DEFAULT NULL,
  received_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_protocol_exchange_transaction (environment, transaction_id),
  KEY idx_ch_protocol_exchange_submission (submission_id, id),
  KEY idx_ch_protocol_exchange_preflight (preflight_id, id),
  CONSTRAINT fk_ch_protocol_exchange_submission
    FOREIGN KEY (submission_id) REFERENCES companies_house_accounts_submissions (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_protocol_exchange_preflight
    FOREIGN KEY (preflight_id) REFERENCES companies_house_company_auth_preflights (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies_house_accounts_status_cycles (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  submission_id bigint(20) NOT NULL,
  poll_transaction_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  raw_status varchar(64) DEFAULT NULL,
  normalized_status enum('pending','parked','accepted','rejected','internal_failure') DEFAULT NULL,
  result_json longtext DEFAULT NULL,
  acknowledgement_state enum('not_requested','required','sending','acknowledged','failed','transport_unknown') NOT NULL DEFAULT 'not_requested',
  acknowledgement_transaction_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  polled_at datetime DEFAULT NULL,
  acknowledged_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_ch_status_cycle_submission (submission_id, id),
  KEY idx_ch_status_cycle_ack (acknowledgement_state, updated_at),
  CONSTRAINT fk_ch_status_cycle_submission
    FOREIGN KEY (submission_id) REFERENCES companies_house_accounts_submissions (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE companies_house_protocol_exchanges
  ADD CONSTRAINT fk_ch_protocol_exchange_status_cycle
    FOREIGN KEY (status_cycle_id) REFERENCES companies_house_accounts_status_cycles (id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE companies_house_accounts_submissions
  ADD COLUMN IF NOT EXISTS preflight_id bigint(20) DEFAULT NULL AFTER presenter_fingerprint,
  ADD COLUMN IF NOT EXISTS pending_status_cycle_id bigint(20) DEFAULT NULL AFTER preflight_id,
  ADD COLUMN IF NOT EXISTS document_request_key varchar(255) DEFAULT NULL AFTER examiner_comments,
  ADD COLUMN IF NOT EXISTS returned_document_id varchar(255) DEFAULT NULL AFTER document_request_key,
  ADD COLUMN IF NOT EXISTS returned_document_path varchar(1000) DEFAULT NULL AFTER returned_document_id,
  ADD COLUMN IF NOT EXISTS returned_document_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER returned_document_path,
  ADD COLUMN IF NOT EXISTS document_retrieved_at datetime DEFAULT NULL AFTER returned_document_sha256,
  ADD KEY IF NOT EXISTS idx_ch_accounts_submission_preflight (preflight_id),
  ADD KEY IF NOT EXISTS idx_ch_accounts_submission_status_cycle (pending_status_cycle_id),
  ADD CONSTRAINT fk_ch_accounts_submission_preflight
    FOREIGN KEY (preflight_id) REFERENCES companies_house_company_auth_preflights (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ch_accounts_submission_status_cycle
    FOREIGN KEY (pending_status_cycle_id) REFERENCES companies_house_accounts_status_cycles (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE companies_house_submission_sequences
  ADD COLUMN IF NOT EXISTS status_in_flight_submission_id bigint(20) DEFAULT NULL AFTER in_flight_submission_id,
  ADD COLUMN IF NOT EXISTS status_in_flight_cycle_id bigint(20) DEFAULT NULL AFTER status_in_flight_submission_id,
  ADD KEY IF NOT EXISTS idx_ch_submission_sequence_status_submission (status_in_flight_submission_id),
  ADD KEY IF NOT EXISTS idx_ch_submission_sequence_status_cycle (status_in_flight_cycle_id),
  ADD CONSTRAINT fk_ch_submission_sequence_status_submission
    FOREIGN KEY (status_in_flight_submission_id) REFERENCES companies_house_accounts_submissions (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ch_submission_sequence_status_cycle
    FOREIGN KEY (status_in_flight_cycle_id) REFERENCES companies_house_accounts_status_cycles (id)
    ON DELETE SET NULL ON UPDATE CASCADE;
