INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
SELECT '1321', 'Motor Vehicles - Cars', 'asset', nas.id, 'capital', 1, 1321
FROM nominal_account_subtypes nas
WHERE nas.code = 'fixed_asset'
  AND NOT EXISTS (SELECT 1 FROM nominal_accounts WHERE code = '1321');

INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
SELECT '1322', 'Motor Vehicles - Vans', 'asset', nas.id, 'capital', 1, 1322
FROM nominal_account_subtypes nas
WHERE nas.code = 'fixed_asset'
  AND NOT EXISTS (SELECT 1 FROM nominal_accounts WHERE code = '1322');

UPDATE expense_claim_lines l
INNER JOIN expense_claim_line_assets la ON la.expense_claim_line_id = l.id
INNER JOIN nominal_accounts na ON na.code = CASE
    WHEN la.category = 'car' THEN '1321'
    WHEN la.category = 'van' THEN '1322'
    ELSE ''
  END
SET l.nominal_account_id = na.id
WHERE la.category IN ('car', 'van');

UPDATE asset_register ar
INNER JOIN nominal_accounts na ON na.code = CASE
    WHEN ar.category = 'car' THEN '1321'
    WHEN ar.category = 'van' THEN '1322'
    ELSE ''
  END
SET ar.nominal_account_id = na.id
WHERE ar.category IN ('car', 'van')
  AND ar.nominal_account_id IN (SELECT id FROM nominal_accounts WHERE code = '1320');

CREATE TABLE IF NOT EXISTS asset_vehicle_details (
  asset_id bigint(20) NOT NULL,
  company_id int(11) NOT NULL,
  vehicle_type varchar(32) NOT NULL DEFAULT 'unreviewed',
  registration_mark varchar(32) DEFAULT NULL,
  make_model varchar(255) DEFAULT NULL,
  colour varchar(64) DEFAULT NULL,
  engine_capacity_cc int(11) DEFAULT NULL,
  first_registered_date date DEFAULT NULL,
  acquisition_condition varchar(32) DEFAULT NULL,
  is_zero_emission tinyint(1) NOT NULL DEFAULT 0,
  co2_emissions_g_km int(11) DEFAULT NULL,
  payload_kg decimal(10,2) DEFAULT NULL,
  contract_date date DEFAULT NULL,
  tax_review_status varchar(32) NOT NULL DEFAULT 'unreviewed',
  reviewed_at datetime DEFAULT NULL,
  reviewed_by varchar(128) DEFAULT NULL,
  notes varchar(512) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (asset_id),
  KEY idx_asset_vehicle_company_type (company_id, vehicle_type),
  KEY idx_asset_vehicle_registration (company_id, registration_mark),
  CONSTRAINT fk_asset_vehicle_asset FOREIGN KEY (asset_id) REFERENCES asset_register (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_asset_vehicle_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS capital_allowance_pool_runs (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  pool_type varchar(32) NOT NULL,
  opening_wdv decimal(12,2) NOT NULL DEFAULT 0.00,
  additions decimal(12,2) NOT NULL DEFAULT 0.00,
  aia_claimed decimal(12,2) NOT NULL DEFAULT 0.00,
  fya_claimed decimal(12,2) NOT NULL DEFAULT 0.00,
  disposal_value decimal(12,2) NOT NULL DEFAULT 0.00,
  wda_claimed decimal(12,2) NOT NULL DEFAULT 0.00,
  balancing_charge decimal(12,2) NOT NULL DEFAULT 0.00,
  balancing_allowance decimal(12,2) NOT NULL DEFAULT 0.00,
  closing_wdv decimal(12,2) NOT NULL DEFAULT 0.00,
  warnings_json longtext DEFAULT NULL,
  run_hash char(64) NOT NULL,
  computed_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_capital_allowance_pool_period (company_id, accounting_period_id, pool_type),
  KEY idx_capital_allowance_pool_period (company_id, accounting_period_id),
  CONSTRAINT fk_capital_allowance_pool_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_capital_allowance_pool_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS capital_allowance_asset_calculations (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  asset_id bigint(20) NOT NULL,
  pool_type varchar(32) NOT NULL,
  allowance_type varchar(32) NOT NULL,
  addition_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  allowance_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  disposal_value decimal(12,2) NOT NULL DEFAULT 0.00,
  warning varchar(512) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_capital_allowance_asset_period (company_id, accounting_period_id),
  KEY idx_capital_allowance_asset_asset (asset_id),
  CONSTRAINT fk_capital_allowance_asset_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_capital_allowance_asset_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_capital_allowance_asset_asset FOREIGN KEY (asset_id) REFERENCES asset_register (id) ON DELETE CASCADE ON UPDATE CASCADE
);

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'vehicle_register'
FROM role_card_permissions
WHERE card_key = 'asset_register';
