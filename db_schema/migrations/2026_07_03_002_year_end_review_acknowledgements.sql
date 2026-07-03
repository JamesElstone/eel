CREATE TABLE IF NOT EXISTS year_end_review_acknowledgements (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  check_code varchar(100) NOT NULL,
  acknowledged_at datetime NOT NULL,
  acknowledged_by varchar(100) NOT NULL,
  note text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uniq_year_end_review_ack_company_period_check (company_id, accounting_period_id, check_code),
  KEY idx_year_end_review_ack_company_period (company_id, accounting_period_id),
  KEY idx_year_end_review_ack_check_code (check_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
