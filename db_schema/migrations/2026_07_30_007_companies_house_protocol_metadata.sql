-- Index the private Companies House protocol metadata needed for audit history
-- while retaining the exact archived XML as authoritative evidence.
ALTER TABLE companies_house_protocol_exchanges
  ADD COLUMN IF NOT EXISTS request_message_class VARCHAR(64)
    CHARACTER SET ascii COLLATE ascii_bin NULL AFTER operation,
  ADD COLUMN IF NOT EXISTS response_headers_json LONGTEXT NULL
    AFTER response_status_code,
  ADD COLUMN IF NOT EXISTS response_headers_sha256 CHAR(64)
    CHARACTER SET ascii COLLATE ascii_bin NULL AFTER response_headers_json,
  ADD COLUMN IF NOT EXISTS govtalk_errors_json LONGTEXT NULL
    AFTER response_headers_sha256;

UPDATE companies_house_protocol_exchanges
SET request_message_class = CASE operation
  WHEN 'company_data' THEN 'CompanyDataRequest'
  WHEN 'accounts' THEN 'Accounts'
  WHEN 'submission_status' THEN 'GetSubmissionStatus'
  WHEN 'status_ack' THEN 'StatusAck'
  WHEN 'get_document' THEN 'GetDocument'
  ELSE request_message_class
END
WHERE request_message_class IS NULL OR request_message_class = '';

ALTER TABLE companies_house_company_auth_preflights
  MODIFY outcome enum(
    'sending',
    'verified',
    'presenter_authorisation_failed',
    'rejected',
    'transport_unknown',
    'failed'
  ) NOT NULL;
