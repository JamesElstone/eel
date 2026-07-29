ALTER TABLE asset_register
  ADD COLUMN IF NOT EXISTS available_for_use_date DATE NULL AFTER purchase_date,
  ADD COLUMN IF NOT EXISTS available_for_use_evidence VARCHAR(255) NULL AFTER available_for_use_date,
  ADD COLUMN IF NOT EXISTS parent_asset_id BIGINT NULL AFTER available_for_use_evidence,
  ADD COLUMN IF NOT EXISTS component_role VARCHAR(48) NOT NULL DEFAULT 'standalone' AFTER parent_asset_id,
  ADD COLUMN IF NOT EXISTS supplier_description VARCHAR(255) NULL AFTER component_role,
  ADD KEY IF NOT EXISTS idx_asset_register_parent_asset (parent_asset_id),
  ADD CONSTRAINT fk_asset_register_parent_asset FOREIGN KEY IF NOT EXISTS (parent_asset_id) REFERENCES asset_register (id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Preserve the former purchase-date convention for legacy standalone assets.
-- A later evidence correction can replace this explicit legacy assumption.
UPDATE asset_register
SET available_for_use_date = purchase_date,
    available_for_use_evidence = 'Legacy register assumption: available for use on purchase date.'
WHERE available_for_use_date IS NULL
  AND parent_asset_id IS NULL;

CREATE TABLE IF NOT EXISTS asset_depreciation_adjustments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  asset_id BIGINT NOT NULL,
  accounting_period_id INT NOT NULL,
  adjustment_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  journal_id BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_asset_depreciation_adjustment_asset_date (asset_id, adjustment_date),
  KEY idx_asset_depreciation_adjustment_period (accounting_period_id),
  KEY idx_asset_depreciation_adjustment_journal (journal_id),
  CONSTRAINT fk_asset_depreciation_adjustment_asset FOREIGN KEY (asset_id) REFERENCES asset_register (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_asset_depreciation_adjustment_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_asset_depreciation_adjustment_journal FOREIGN KEY (journal_id) REFERENCES journals (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_asset_depreciation_adjustment_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
