ALTER TABLE hmrc_obligations
  ADD COLUMN IF NOT EXISTS legacy_unlinked_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount_paid;

UPDATE hmrc_obligations
SET legacy_unlinked_amount = amount_paid
WHERE amount_paid > 0;

CREATE TABLE IF NOT EXISTS hmrc_obligation_evidence_links (
  id BIGINT NOT NULL AUTO_INCREMENT,
  hmrc_obligation_id INT NOT NULL,
  transaction_id BIGINT NULL,
  expense_claim_line_id BIGINT NULL,
  allocated_amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hmrc_evidence_obligation_transaction (hmrc_obligation_id, transaction_id),
  UNIQUE KEY uq_hmrc_evidence_obligation_expense (hmrc_obligation_id, expense_claim_line_id),
  KEY idx_hmrc_evidence_transaction (transaction_id),
  KEY idx_hmrc_evidence_expense (expense_claim_line_id),
  CONSTRAINT chk_hmrc_evidence_one_source CHECK (
    (transaction_id IS NOT NULL AND expense_claim_line_id IS NULL)
    OR (transaction_id IS NULL AND expense_claim_line_id IS NOT NULL)
  ),
  CONSTRAINT chk_hmrc_evidence_positive_amount CHECK (allocated_amount > 0),
  CONSTRAINT fk_hmrc_evidence_obligation FOREIGN KEY (hmrc_obligation_id) REFERENCES hmrc_obligations (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_evidence_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_evidence_expense FOREIGN KEY (expense_claim_line_id) REFERENCES expense_claim_lines (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO company_settings (company_id, setting, type, value)
SELECT c.id, mapping.setting, 'int', CAST(na.id AS CHAR)
FROM companies c
INNER JOIN (
  SELECT 'dividends_payable_nominal_id' AS setting, '2150' AS code UNION ALL
  SELECT 'default_expense_charge_nominal_id', '6000' UNION ALL
  SELECT 'tools_equipment_asset_cost_nominal_id', '1300' UNION ALL
  SELECT 'tools_equipment_accum_dep_nominal_id', '1330' UNION ALL
  SELECT 'plant_machinery_asset_cost_nominal_id', '1310' UNION ALL
  SELECT 'plant_machinery_accum_dep_nominal_id', '1340' UNION ALL
  SELECT 'motor_vehicle_asset_cost_nominal_id', '1320' UNION ALL
  SELECT 'motor_vehicle_accum_dep_nominal_id', '1350' UNION ALL
  SELECT 'van_asset_cost_nominal_id', '1322' UNION ALL
  SELECT 'van_accum_dep_nominal_id', '1350' UNION ALL
  SELECT 'car_asset_cost_nominal_id', '1321' UNION ALL
  SELECT 'car_accum_dep_nominal_id', '1350'
) mapping
INNER JOIN nominal_accounts na ON na.code = mapping.code AND na.is_active = 1;
