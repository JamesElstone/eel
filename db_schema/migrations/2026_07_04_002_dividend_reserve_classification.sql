CREATE TABLE IF NOT EXISTS dividend_reserve_classification_rules (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  nominal_account_id int(11) NOT NULL,
  treatment varchar(40) NOT NULL,
  note text DEFAULT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_dividend_reserve_rule_company_nominal (company_id, nominal_account_id),
  KEY idx_dividend_reserve_rule_nominal (nominal_account_id),
  CONSTRAINT fk_dividend_reserve_rule_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dividend_reserve_rule_nominal FOREIGN KEY (nominal_account_id) REFERENCES nominal_accounts (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dividend_reserve_review_snapshots (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  source_hash char(64) NOT NULL,
  ledger_profit_loss decimal(12,2) NOT NULL DEFAULT 0.00,
  realised_profit_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  realised_loss_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  unrealised_gain_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  unrealised_loss_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  non_distributable_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  capital_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  tax_charge_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  dividend_distribution_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  unknown_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  distributable_current_profit decimal(12,2) NOT NULL DEFAULT 0.00,
  reviewed_at datetime NOT NULL DEFAULT current_timestamp(),
  reviewed_by varchar(100) NOT NULL DEFAULT 'web_app',
  summary_json longtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_dividend_reserve_snapshot_company_period (company_id, accounting_period_id),
  KEY idx_dividend_reserve_snapshot_hash (company_id, accounting_period_id, source_hash),
  CONSTRAINT fk_dividend_reserve_snapshot_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dividend_reserve_snapshot_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (
  role_id,
  card_key
)
SELECT
  role_id,
  'dividend_reserve_review'
FROM role_card_permissions
WHERE card_key = 'dividend_capacity';
