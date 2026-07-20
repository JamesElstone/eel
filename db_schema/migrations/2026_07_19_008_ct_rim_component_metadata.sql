ALTER TABLE hmrc_ct_rim_components
  ADD COLUMN IF NOT EXISTS parent_path VARCHAR(1000) DEFAULT NULL AFTER component_path,
  ADD COLUMN IF NOT EXISTS sequence_order INT DEFAULT NULL AFTER is_required,
  ADD COLUMN IF NOT EXISTS is_leaf TINYINT(1) NOT NULL DEFAULT 1 AFTER sequence_order,
  ADD COLUMN IF NOT EXISTS source_file_id BIGINT DEFAULT NULL AFTER is_leaf,
  ADD INDEX IF NOT EXISTS idx_hmrc_ct_rim_component_parent (package_id, parent_path(190)),
  ADD INDEX IF NOT EXISTS idx_hmrc_ct_rim_component_leaf (package_id, is_leaf, is_required);

-- One frozen source fact can legitimately populate several distinct CT600
-- boxes (for example the net liability and payable reconciliation fields).
ALTER TABLE ct600_rim_mappings
  DROP INDEX IF EXISTS uq_ct600_rim_mapping_source,
  ADD INDEX IF NOT EXISTS idx_ct600_rim_mapping_source (profile_id, canonical_key);

-- HMRC publishes the live lifecycle in the publication body/change history;
-- the attachment titles themselves do not consistently contain the word live.
UPDATE hmrc_ct_rim_packages
SET hmrc_status = 'live',
    live_from = COALESCE(live_from, '2026-04-07 00:00:00')
WHERE UPPER(form_version) = 'V3'
  AND UPPER(artifact_version) = 'V1.994'
  AND LOWER(hmrc_status) IN ('published', 'live');

UPDATE hmrc_ct_rim_packages
SET hmrc_status = 'live',
    live_from = COALESCE(live_from, '2015-07-22 00:00:00')
WHERE UPPER(form_version) = 'V2'
  AND UPPER(artifact_version) = 'V3.99'
  AND LOWER(hmrc_status) IN ('published', 'live');

-- The accepted CT computational taxonomy follows HMRC's v1.0.0 package
-- identity. The files remain fail-closed/not_downloaded until an official
-- expanded package with a matching taxonomyPackage.xml is catalogued.
UPDATE hmrc_ct_computation_packages placeholder
LEFT JOIN hmrc_ct_computation_packages configured
  ON configured.taxonomy_version = placeholder.taxonomy_version
 AND UPPER(configured.artifact_version) = 'V1.0.0'
SET placeholder.artifact_version = 'V1.0.0'
WHERE placeholder.taxonomy_version = '2025'
  AND LOWER(placeholder.artifact_version) = 'unconfigured'
  AND configured.id IS NULL;

-- Only canonical values with an unambiguous CT600 field stay in the RIM scope.
UPDATE ct_filing_canonical_sources
SET target_scope = 'ct600_rim'
WHERE canonical_key = 'identity.company_number'
  AND target_scope = 'both';

UPDATE ct_filing_canonical_sources
SET target_scope = 'computation_ixbrl'
WHERE canonical_key IN (
    'computation.summary.accounting_profit',
    'computation.summary.disallowable_add_backs',
    'computation.summary.capital_add_backs',
    'computation.summary.depreciation_add_back',
    'computation.summary.capital_allowances',
    'computation.summary.taxable_before_losses',
    'computation.summary.losses_brought_forward',
    'computation.summary.losses_used'
  )
  AND target_scope = 'both';

INSERT IGNORE INTO ct_filing_canonical_sources
  (target_scope, canonical_key, source_label, value_type, source_section, is_required)
VALUES
  ('both', 'filing_identity.utr', 'Corporation Tax unique taxpayer reference', 'text', 'identity', 1),
  ('ct600_rim', 'accounts_facts.turnover', 'Accounts turnover', 'numeric', 'identity', 1),
  ('ct600_rim', 'computation.summary.loss_created_in_period', 'Trading loss arising in this period', 'numeric', 'losses', 1),
  ('ct600_rim', 'filing_decisions.trading_profit_before_losses', 'Trading profit before brought-forward losses', 'numeric', 'losses', 0),
  ('ct600_rim', 'filing_decisions.trading_losses_brought_forward_used', 'Trading losses brought forward used against same-trade profit', 'numeric', 'losses', 0),
  ('ct600_rim', 'filing_decisions.net_trading_profits', 'Net trading profits', 'numeric', 'losses', 0),
  ('ct600_rim', 'filing_decisions.profits_before_other_deductions', 'Profits before other deductions and reliefs', 'numeric', 'tax_liability', 0),
  ('ct600_rim', 'filing_decisions.profits_before_donations_group_relief', 'Profits before donations and group relief', 'numeric', 'tax_liability', 0),
  ('ct600_rim', 'filing_decisions.aia_claimed_in_trade', 'Annual Investment Allowance claimed in the trade', 'numeric', 'capital_allowances', 0),
  ('ct600_rim', 'filing_decisions.main_pool_capital_allowances', 'Main-pool capital allowances', 'numeric', 'capital_allowances', 0),
  ('ct600_rim', 'filing_decisions.main_pool_balancing_charges', 'Main-pool balancing charges', 'numeric', 'capital_allowances', 0),
  ('ct600_rim', 'filing_decisions.special_rate_pool_capital_allowances', 'Special-rate-pool capital allowances', 'numeric', 'capital_allowances', 0),
  ('ct600_rim', 'filing_decisions.special_rate_pool_balancing_charges', 'Special-rate-pool balancing charges', 'numeric', 'capital_allowances', 0),
  ('ct600_rim', 'filing_decisions.qualifying_expenditure_other_machinery_plant', 'Other machinery and plant qualifying expenditure', 'numeric', 'capital_allowances', 0),
  ('ct600_rim', 'filing_decisions.associated_company_count', 'Associated companies in this period', 'integer', 'tax_liability', 0),
  ('ct600_rim', 'filing_decisions.loss_relief_treatment', 'Explicit trading-loss relief treatment', 'text', 'losses', 0);
