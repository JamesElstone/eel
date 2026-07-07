CREATE TABLE IF NOT EXISTS transaction_inter_ac_marker (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  transaction_id bigint(20) NOT NULL,
  matched_transaction_id bigint(20) NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  created_by varchar(100) NOT NULL DEFAULT 'web_app',
  PRIMARY KEY (id),
  UNIQUE KEY uq_transaction_inter_ac_source (transaction_id),
  UNIQUE KEY uq_transaction_inter_ac_matched (matched_transaction_id),
  KEY idx_transaction_inter_ac_company_period (company_id, accounting_period_id),
  KEY idx_transaction_inter_ac_matched (matched_transaction_id),
  CONSTRAINT fk_transaction_inter_ac_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_transaction_inter_ac_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_transaction_inter_ac_source FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_transaction_inter_ac_matched FOREIGN KEY (matched_transaction_id) REFERENCES transactions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
