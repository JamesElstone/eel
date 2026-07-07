CREATE TABLE IF NOT EXISTS transaction_splits (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  transaction_id bigint(20) NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_transaction_splits_transaction (transaction_id),
  CONSTRAINT fk_transaction_splits_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_split_lines (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  split_id bigint(20) NOT NULL,
  line_number int(11) NOT NULL,
  description varchar(255) DEFAULT NULL,
  amount decimal(12,2) DEFAULT NULL,
  nominal_account_id int(11) DEFAULT NULL,
  is_deferred tinyint(1) NOT NULL DEFAULT 0,
  notes text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_transaction_split_lines_number (split_id, line_number),
  KEY idx_transaction_split_lines_nominal (nominal_account_id),
  CONSTRAINT fk_transaction_split_lines_split FOREIGN KEY (split_id) REFERENCES transaction_splits (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_transaction_split_lines_nominal FOREIGN KEY (nominal_account_id) REFERENCES nominal_accounts (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_transaction_split_lines_amount CHECK (amount IS NULL OR amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE asset_register
  ADD COLUMN IF NOT EXISTS linked_transaction_split_line_id bigint(20) DEFAULT NULL AFTER linked_expense_claim_line_id,
  ADD INDEX IF NOT EXISTS idx_asset_register_transaction_split_line (linked_transaction_split_line_id),
  ADD FOREIGN KEY IF NOT EXISTS fk_asset_register_transaction_split_line (linked_transaction_split_line_id) REFERENCES transaction_split_lines (id) ON DELETE SET NULL ON UPDATE CASCADE;
