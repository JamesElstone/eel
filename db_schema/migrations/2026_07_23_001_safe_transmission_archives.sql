-- Presenter-scoped Companies House envelope numbers and private immutable
-- transmission archives for Companies House and HMRC.

ALTER TABLE companies_house_accounts_submissions
  ADD COLUMN IF NOT EXISTS presenter_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER submission_number,
  DROP INDEX IF EXISTS uq_ch_accounts_submission_number,
  ADD UNIQUE KEY IF NOT EXISTS uq_ch_accounts_presenter_submission (
    environment,
    presenter_fingerprint,
    submission_number
  );

CREATE TABLE IF NOT EXISTS companies_house_submission_sequences (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  environment enum('TEST','LIVE') NOT NULL,
  presenter_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  next_value int(11) NOT NULL DEFAULT 1,
  last_issued_value int(11) DEFAULT NULL,
  in_flight_submission_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_submission_sequence_presenter (environment, presenter_fingerprint),
  KEY idx_ch_submission_sequence_in_flight (in_flight_submission_id),
  CONSTRAINT chk_ch_submission_sequence_next CHECK (next_value BETWEEN 1 AND 1000000),
  CONSTRAINT chk_ch_submission_sequence_last CHECK (
    last_issued_value IS NULL OR last_issued_value BETWEEN 1 AND 999999
  ),
  CONSTRAINT fk_ch_submission_sequence_in_flight
    FOREIGN KEY (in_flight_submission_id) REFERENCES companies_house_accounts_submissions (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transmission_archives (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  authority enum('companies_house','hmrc') NOT NULL,
  environment varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) DEFAULT NULL,
  submission_reference varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  lifecycle varchar(64) NOT NULL,
  archive_path varchar(1000) NOT NULL,
  manifest_path varchar(1000) DEFAULT NULL,
  manifest_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_transmission_archive_reference (
    authority,
    environment,
    company_id,
    submission_reference
  ),
  KEY idx_transmission_archive_period (
    company_id,
    accounting_period_id,
    authority,
    environment
  ),
  CONSTRAINT fk_transmission_archive_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_transmission_archive_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'companies_house_transmission'
FROM role_card_permissions
WHERE card_key = 'year_end_companies_house_comparison';
