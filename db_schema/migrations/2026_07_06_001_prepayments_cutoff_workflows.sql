ALTER TABLE nominal_accounts
  ADD COLUMN IF NOT EXISTS prepayment_candidate tinyint(1) NOT NULL DEFAULT 0 AFTER tax_treatment,
  ADD KEY IF NOT EXISTS idx_nominal_prepayment_candidate (prepayment_candidate, account_type, is_active);

UPDATE nominal_accounts
SET prepayment_candidate = 1
WHERE code IN ('6001', '6010', '6020')
   OR name IN ('Insurance', 'Vehicle Insurance', 'Software & Subscriptions');

CREATE TABLE IF NOT EXISTS prepayment_reviews (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  source_type enum('transaction','expense_claim_line') NOT NULL,
  source_id bigint(20) NOT NULL,
  status enum('pending','not_prepaid','prepaid') NOT NULL DEFAULT 'pending',
  service_start_date date DEFAULT NULL,
  service_end_date date DEFAULT NULL,
  notes text DEFAULT NULL,
  generated_journal_id bigint(20) DEFAULT NULL,
  reversal_journal_id bigint(20) DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  reviewed_by varchar(100) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uniq_prepayment_reviews_source (company_id, accounting_period_id, source_type, source_id),
  KEY idx_prepayment_reviews_period_status (company_id, accounting_period_id, status),
  KEY idx_prepayment_reviews_generated_journal (generated_journal_id),
  KEY idx_prepayment_reviews_reversal_journal (reversal_journal_id),
  CONSTRAINT fk_prepayment_reviews_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_reviews_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_reviews_generated_journal FOREIGN KEY (generated_journal_id) REFERENCES journals (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_reviews_reversal_journal FOREIGN KEY (reversal_journal_id) REFERENCES journals (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_prepayment_reviews_dates CHECK (service_start_date IS NULL OR service_end_date IS NULL OR service_start_date <= service_end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'prepayments_review'
FROM role_card_permissions
WHERE card_key IN ('nominals_accounts', 'year_end_checklist');

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'cut_off_journals'
FROM role_card_permissions
WHERE card_key = 'nominal_closing_balances';

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'year_end_transaction_tail'
FROM role_card_permissions
WHERE card_key = 'year_end_empty_month_confirmations';
