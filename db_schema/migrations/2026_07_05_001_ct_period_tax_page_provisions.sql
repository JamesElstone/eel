ALTER TABLE capital_allowance_pool_runs
  ADD COLUMN IF NOT EXISTS ct_period_id int(11) NULL AFTER accounting_period_id;

ALTER TABLE capital_allowance_asset_calculations
  ADD COLUMN IF NOT EXISTS ct_period_id int(11) NULL AFTER accounting_period_id;

ALTER TABLE capital_allowance_pool_runs
  DROP INDEX IF EXISTS uq_capital_allowance_pool_period;

ALTER TABLE capital_allowance_pool_runs
  ADD UNIQUE KEY IF NOT EXISTS uq_capital_allowance_pool_ct_period (company_id, ct_period_id, pool_type),
  ADD KEY IF NOT EXISTS idx_capital_allowance_pool_period (company_id, accounting_period_id),
  ADD KEY IF NOT EXISTS idx_capital_allowance_pool_ct_period (ct_period_id);

ALTER TABLE capital_allowance_asset_calculations
  ADD KEY IF NOT EXISTS idx_capital_allowance_asset_ct_period (ct_period_id);

ALTER TABLE capital_allowance_pool_runs
  ADD FOREIGN KEY IF NOT EXISTS fk_capital_allowance_pool_ct_period (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE capital_allowance_asset_calculations
  ADD FOREIGN KEY IF NOT EXISTS fk_capital_allowance_asset_ct_period (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;

INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
SELECT '8500', 'Corporation Tax Expense', 'expense', NULL, 'disallowable', 1, 850
WHERE NOT EXISTS (
  SELECT 1 FROM nominal_accounts WHERE code = '8500'
);
