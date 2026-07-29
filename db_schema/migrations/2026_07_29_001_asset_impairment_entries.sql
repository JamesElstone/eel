CREATE TABLE IF NOT EXISTS asset_impairment_entries (
  id BIGINT NOT NULL AUTO_INCREMENT,
  asset_id BIGINT NOT NULL,
  accounting_period_id INT NOT NULL,
  impairment_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  journal_id BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_asset_impairment_asset_date (asset_id, impairment_date),
  KEY idx_asset_impairment_accounting_period (accounting_period_id),
  KEY idx_asset_impairment_journal (journal_id),
  CONSTRAINT fk_asset_impairment_asset FOREIGN KEY (asset_id) REFERENCES asset_register (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_asset_impairment_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_asset_impairment_journal FOREIGN KEY (journal_id) REFERENCES journals (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_asset_impairment_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
