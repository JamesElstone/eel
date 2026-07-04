INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, tax_card.card_key
FROM role_card_permissions existing_permission
INNER JOIN (
  SELECT 'tax_corporation_tax_summary' AS card_key
  UNION ALL SELECT 'tax_taxable_profit_bridge'
  UNION ALL SELECT 'tax_disallowable_add_backs'
  UNION ALL SELECT 'tax_depreciation_add_back'
  UNION ALL SELECT 'tax_capital_allowances_summary'
  UNION ALL SELECT 'tax_aia_allocation'
  UNION ALL SELECT 'tax_main_rate_pool'
  UNION ALL SELECT 'tax_special_rate_pool'
  UNION ALL SELECT 'tax_car_co2_treatment'
  UNION ALL SELECT 'tax_disposals_balancing'
  UNION ALL SELECT 'tax_losses'
  UNION ALL SELECT 'tax_rate_bands'
  UNION ALL SELECT 'tax_warnings'
) tax_card
WHERE existing_permission.card_key IN ('year_end_tax_readiness', 'tax_rates');
