CREATE TABLE IF NOT EXISTS expense_claim_line_assets (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  expense_claim_line_id bigint(20) NOT NULL,
  category varchar(64) NOT NULL DEFAULT 'tools_equipment',
  description varchar(255) DEFAULT NULL,
  useful_life_years int(11) NOT NULL DEFAULT 3,
  depreciation_method varchar(32) NOT NULL DEFAULT 'straight_line',
  residual_value decimal(12,2) NOT NULL DEFAULT 0.00,
  generated_asset_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_claim_line_assets_line (expense_claim_line_id),
  KEY idx_expense_claim_line_assets_asset (generated_asset_id),
  CONSTRAINT fk_expense_claim_line_assets_line FOREIGN KEY (expense_claim_line_id) REFERENCES expense_claim_lines (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_expense_claim_line_assets_asset FOREIGN KEY (generated_asset_id) REFERENCES asset_register (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_expense_claim_line_assets_life CHECK (useful_life_years > 0),
  CONSTRAINT chk_expense_claim_line_assets_residual CHECK (residual_value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE asset_register
  ADD COLUMN IF NOT EXISTS linked_expense_claim_line_id bigint(20) DEFAULT NULL AFTER linked_transaction_id,
  ADD INDEX IF NOT EXISTS idx_asset_register_expense_claim_line (linked_expense_claim_line_id),
  ADD FOREIGN KEY IF NOT EXISTS fk_asset_register_expense_claim_line (linked_expense_claim_line_id) REFERENCES expense_claim_lines (id) ON DELETE SET NULL ON UPDATE CASCADE;
