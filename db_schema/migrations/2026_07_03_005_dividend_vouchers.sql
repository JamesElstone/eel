CREATE TABLE IF NOT EXISTS dividend_vouchers (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  journal_id bigint(20) NOT NULL,
  transaction_id int(11) DEFAULT NULL,
  reversal_journal_id bigint(20) DEFAULT NULL,
  company_name varchar(255) NOT NULL,
  shareholder_name varchar(255) NOT NULL,
  director_name varchar(255) NOT NULL,
  declaration_date date NOT NULL,
  payment_date date NOT NULL,
  amount decimal(12,2) NOT NULL,
  description varchar(255) NOT NULL,
  voucher_text text NOT NULL,
  minutes_text text NOT NULL,
  issued_at datetime NOT NULL DEFAULT current_timestamp(),
  issued_by varchar(100) NOT NULL DEFAULT 'web_app',
  voided_at datetime DEFAULT NULL,
  voided_by varchar(100) DEFAULT NULL,
  void_reason text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_dividend_vouchers_journal (journal_id),
  KEY idx_dividend_vouchers_company_period (company_id, accounting_period_id),
  KEY idx_dividend_vouchers_transaction (transaction_id),
  KEY idx_dividend_vouchers_reversal_journal (reversal_journal_id),
  CONSTRAINT fk_dividend_vouchers_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dividend_vouchers_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dividend_vouchers_journal FOREIGN KEY (journal_id) REFERENCES journals (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_dividend_vouchers_reversal_journal FOREIGN KEY (reversal_journal_id) REFERENCES journals (id) ON DELETE SET NULL ON UPDATE CASCADE
);

INSERT IGNORE INTO role_card_permissions (
  role_id,
  card_key
)
SELECT
  role_id,
  'dividend_vouchers'
FROM role_card_permissions
WHERE card_key = 'dividend_capacity';
