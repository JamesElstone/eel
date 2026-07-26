ALTER TABLE ixbrl_fact_mappings
  MODIFY COLUMN calculation_type enum(
    'nominal_subtype_sum','nominal_account_sum','manual','derived',
    'company_field','period_field','disclosure_field','disclosure_statement',
    'absence_statement','application_value','fixed_marker',
    'director_loan_statement','director_loan_numeric'
  ) NOT NULL;

UPDATE ixbrl_fact_mappings
SET context_profile = 'instant_end'
WHERE fact_key IN ('period_start', 'accounts_approval_date');

UPDATE ixbrl_fact_mappings
SET taxonomy_concept = 'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
    local_name = 'PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal'
WHERE fact_key = 'prepayments_accrued_income';

UPDATE ixbrl_fact_mappings
SET calculation_type = 'director_loan_statement'
WHERE fact_key = 'no_director_advances_or_credits';

INSERT INTO ixbrl_fact_mappings (
  fact_key, taxonomy_concept, namespace_uri, local_name, label, value_type,
  calculation_type, source_key, sign_multiplier, period_type, unit_ref,
  decimals_value, context_profile, dimensions_json, comparative_enabled,
  is_required, sort_order, is_active
) VALUES
('accounts_type','bus:AccountsType','http://xbrl.frc.org.uk/cd/2026-01-01/business','AccountsType','Accounts type','text','fixed_marker','accounts_type',1,'duration',NULL,NULL,'duration_accounts_type','{"bus:AccountsTypeDimension":"bus:FullAccounts"}',0,1,82,1),
('called_up_share_capital_not_paid','core:CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset','http://xbrl.frc.org.uk/fr/2026-01-01/core','CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset','Called-up share capital not paid','numeric','derived','called_up_share_capital_not_paid',1,'instant','GBP','2','instant_end',NULL,1,1,195,1),
('provisions_for_liabilities','core:ProvisionsForLiabilitiesBalanceSheetSubtotal','http://xbrl.frc.org.uk/fr/2026-01-01/core','ProvisionsForLiabilitiesBalanceSheetSubtotal','Provisions for liabilities','numeric','derived','provisions_for_liabilities',1,'instant','GBP','2','instant_end',NULL,1,1,252,1),
('accruals_deferred_income','core:AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal','http://xbrl.frc.org.uk/fr/2026-01-01/core','AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal','Accruals and deferred income','numeric','derived','accruals_deferred_income',1,'instant','GBP','2','instant_end',NULL,1,1,254,1),
('director_advances_made','direp:AdvancesCreditsMadeInPeriodDirectors','http://xbrl.frc.org.uk/reports/2026-01-01/direp','AdvancesCreditsMadeInPeriodDirectors','Advances or credits made to directors during the period','numeric','director_loan_numeric','total_advances',1,'duration','GBP','2','duration',NULL,1,1,366,1),
('director_cash_repayments','direp:AdvancesCreditsRepaidInPeriodDirectors','http://xbrl.frc.org.uk/reports/2026-01-01/direp','AdvancesCreditsRepaidInPeriodDirectors','Cash repayments of advances or credits by directors during the period','numeric','director_loan_numeric','total_cash_repayments',1,'duration','GBP','2','duration',NULL,1,1,367,1),
('director_closing_advance','direp:AdvancesCreditsDirectors','http://xbrl.frc.org.uk/reports/2026-01-01/direp','AdvancesCreditsDirectors','Closing advances or credits to directors','numeric','director_loan_numeric','closing_company_to_director_balance',1,'instant','GBP','2','instant_end',NULL,1,1,368,1)
ON DUPLICATE KEY UPDATE
  taxonomy_concept = VALUES(taxonomy_concept),
  namespace_uri = VALUES(namespace_uri),
  local_name = VALUES(local_name),
  label = VALUES(label),
  value_type = VALUES(value_type),
  calculation_type = VALUES(calculation_type),
  source_key = VALUES(source_key),
  sign_multiplier = VALUES(sign_multiplier),
  period_type = VALUES(period_type),
  unit_ref = VALUES(unit_ref),
  decimals_value = VALUES(decimals_value),
  context_profile = VALUES(context_profile),
  dimensions_json = VALUES(dimensions_json),
  comparative_enabled = VALUES(comparative_enabled),
  is_required = VALUES(is_required),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);
