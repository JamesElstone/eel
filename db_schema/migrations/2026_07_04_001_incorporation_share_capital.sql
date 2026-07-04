CREATE TABLE IF NOT EXISTS company_incorporation_share_classes (
  id int NOT NULL AUTO_INCREMENT,
  company_id int NOT NULL,
  share_class varchar(100) NOT NULL DEFAULT 'Ordinary',
  currency varchar(10) NOT NULL DEFAULT 'GBP',
  quantity int NOT NULL,
  nominal_value_per_share decimal(18,6) NOT NULL DEFAULT 0.000000,
  paid_value_per_share decimal(18,6) NOT NULL DEFAULT 0.000000,
  unpaid_value_per_share decimal(18,6) NOT NULL DEFAULT 0.000000,
  source_note text DEFAULT NULL,
  document_reference varchar(255) DEFAULT NULL,
  status enum('unresolved','paid','part_paid','unpaid') NOT NULL DEFAULT 'unresolved',
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_incorporation_shares_company (company_id),
  CONSTRAINT fk_incorporation_shares_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_incorporation_shares_quantity_positive CHECK (quantity > 0),
  CONSTRAINT chk_incorporation_shares_values_nonnegative CHECK (
    nominal_value_per_share >= 0
    AND paid_value_per_share >= 0
    AND unpaid_value_per_share >= 0
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_incorporation_share_payment_matches (
  id int NOT NULL AUTO_INCREMENT,
  company_id int NOT NULL,
  share_class_id int NOT NULL,
  transaction_id bigint NOT NULL,
  matched_amount decimal(12,2) NOT NULL,
  match_status enum('current','cleared') NOT NULL DEFAULT 'current',
  matched_at datetime NOT NULL DEFAULT current_timestamp(),
  matched_by varchar(100) NOT NULL DEFAULT 'web_app',
  cleared_at datetime DEFAULT NULL,
  cleared_by varchar(100) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_share_payment_company (company_id),
  KEY idx_share_payment_share_class (share_class_id),
  KEY idx_share_payment_transaction (transaction_id),
  KEY idx_share_payment_status (share_class_id, match_status),
  CONSTRAINT fk_share_payment_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_share_payment_share_class FOREIGN KEY (share_class_id) REFERENCES company_incorporation_share_classes (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_share_payment_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_share_payment_amount_positive CHECK (matched_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'incorporation_status'
FROM role_card_permissions
WHERE card_key = 'companies_company_settings';

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'incorporation_share_capital'
FROM role_card_permissions
WHERE card_key = 'companies_company_settings';

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'incorporation_payment_matching'
FROM role_card_permissions
WHERE card_key = 'companies_company_settings';
