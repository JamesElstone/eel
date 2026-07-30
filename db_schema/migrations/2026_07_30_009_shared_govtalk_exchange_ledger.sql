-- Replace the Companies House-only protocol ledger with an authority-neutral
-- GovTalk exchange ledger. The source table remains authoritative until the
-- complete, uniquely archive-linked copy has succeeded.

DROP TABLE IF EXISTS govtalk_protocol_exchanges_new;

CREATE TABLE govtalk_protocol_exchanges_new (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  authority enum('companies_house','hmrc') NOT NULL,
  transmission_archive_id bigint(20) NOT NULL,
  submission_id bigint(20) DEFAULT NULL,
  preflight_id bigint(20) DEFAULT NULL,
  status_cycle_id bigint(20) DEFAULT NULL,
  hmrc_submission_id bigint(20) DEFAULT NULL,
  operation varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_message_class varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  request_qualifier varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  request_function varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  environment enum('TEST','TIL','LIVE') NOT NULL,
  endpoint varchar(1000) DEFAULT NULL,
  transaction_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  correlation_id varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  exchange_state enum(
    'prepared','sent','received','succeeded','rejected',
    'transport_unknown','evidence_incomplete','failed'
  ) NOT NULL,
  request_path varchar(1000) DEFAULT NULL,
  request_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  request_bytes bigint(20) unsigned DEFAULT NULL,
  response_path varchar(1000) DEFAULT NULL,
  response_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  response_bytes bigint(20) unsigned DEFAULT NULL,
  response_status_code int(11) DEFAULT NULL,
  response_headers_json longtext DEFAULT NULL,
  response_headers_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  govtalk_errors_json longtext DEFAULT NULL,
  outcome_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  outcome_summary text DEFAULT NULL,
  error_summary text DEFAULT NULL,
  sent_at datetime DEFAULT NULL,
  received_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_govtalk_exchange_transaction (authority, environment, transaction_id),
  KEY idx_govtalk_exchange_archive (transmission_archive_id, id),
  KEY idx_govtalk_exchange_submission (submission_id, id),
  KEY idx_govtalk_exchange_preflight (preflight_id, id),
  KEY idx_govtalk_exchange_hmrc_submission (hmrc_submission_id, id),
  KEY idx_govtalk_exchange_company_history (authority, environment, created_at),
  CONSTRAINT fk_govtalk_exchange_archive
    FOREIGN KEY (transmission_archive_id) REFERENCES transmission_archives (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_govtalk_exchange_submission
    FOREIGN KEY (submission_id) REFERENCES companies_house_accounts_submissions (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_govtalk_exchange_preflight
    FOREIGN KEY (preflight_id) REFERENCES companies_house_company_auth_preflights (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_govtalk_exchange_status_cycle
    FOREIGN KEY (status_cycle_id) REFERENCES companies_house_accounts_status_cycles (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_govtalk_exchange_hmrc_submission
    FOREIGN KEY (hmrc_submission_id) REFERENCES hmrc_ct600_submissions (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO govtalk_protocol_exchanges_new (
  id, authority, transmission_archive_id,
  submission_id, preflight_id, status_cycle_id, hmrc_submission_id,
  operation, request_message_class, request_qualifier, request_function,
  environment, endpoint, transaction_id, correlation_id, exchange_state,
  request_path, request_sha256, request_bytes,
  response_path, response_sha256, response_bytes,
  response_status_code, response_headers_json, response_headers_sha256,
  govtalk_errors_json, outcome_code, outcome_summary, error_summary,
  sent_at, received_at, created_at, updated_at
)
SELECT
  e.id,
  'companies_house',
  a.id,
  e.submission_id,
  e.preflight_id,
  e.status_cycle_id,
  NULL,
  e.operation,
  e.request_message_class,
  'request',
  CASE
    WHEN e.operation IN ('submission_status','status_ack','get_document') THEN 'submit'
    ELSE NULL
  END,
  e.environment,
  NULL,
  e.transaction_id,
  NULL,
  e.exchange_state,
  e.request_path,
  e.request_sha256,
  CASE
    WHEN e.request_path IS NOT NULL THEN OCTET_LENGTH(LOAD_FILE(e.request_path))
    ELSE NULL
  END,
  e.response_path,
  e.response_sha256,
  CASE
    WHEN e.response_path IS NOT NULL THEN OCTET_LENGTH(LOAD_FILE(e.response_path))
    ELSE NULL
  END,
  e.response_status_code,
  e.response_headers_json,
  e.response_headers_sha256,
  e.govtalk_errors_json,
  CASE e.exchange_state
    WHEN 'succeeded' THEN 'succeeded'
    WHEN 'rejected' THEN 'rejected'
    WHEN 'transport_unknown' THEN 'transport_unknown'
    WHEN 'evidence_incomplete' THEN 'evidence_incomplete'
    WHEN 'failed' THEN 'failed'
    ELSE NULL
  END,
  NULL,
  e.error_summary,
  e.sent_at,
  e.received_at,
  e.created_at,
  e.updated_at
FROM companies_house_protocol_exchanges e
LEFT JOIN companies_house_accounts_submissions s
  ON s.id = e.submission_id
LEFT JOIN companies_house_company_auth_preflights p
  ON p.id = e.preflight_id
LEFT JOIN transmission_archives a
  ON a.authority = 'companies_house'
 AND a.environment = e.environment
 AND a.company_id = COALESCE(s.company_id, p.company_id)
 AND a.submission_reference = CASE
   WHEN e.operation = 'company_data' AND p.archive_reference IS NOT NULL
     THEN p.archive_reference
   WHEN s.submission_number IS NOT NULL AND s.submission_number <> ''
     THEN s.submission_number
   ELSE p.archive_reference
 END
 AND (e.request_path IS NULL
   OR REPLACE(e.request_path, '\\', '/') LIKE CONCAT(REPLACE(a.archive_path, '\\', '/'), '/%'))
 AND (e.response_path IS NULL
   OR REPLACE(e.response_path, '\\', '/') LIKE CONCAT(REPLACE(a.archive_path, '\\', '/'), '/%'));

RENAME TABLE
  companies_house_protocol_exchanges TO companies_house_protocol_exchanges_legacy,
  govtalk_protocol_exchanges_new TO govtalk_protocol_exchanges;
