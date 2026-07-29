ALTER TABLE ixbrl_fact_mappings
  MODIFY COLUMN calculation_type enum(
    'nominal_subtype_sum','nominal_account_sum','manual','derived',
    'company_field','period_field','disclosure_field','disclosure_statement',
    'absence_statement','application_value','fixed_marker',
    'director_loan_statement','director_loan_numeric',
    'companies_house_revision_explanation'
  ) NOT NULL;

INSERT INTO ixbrl_fact_mappings (
  fact_key, taxonomy_concept, namespace_uri, local_name, label, value_type,
  calculation_type, source_key, sign_multiplier, period_type, unit_ref,
  decimals_value, context_profile, dimensions_json, comparative_enabled,
  is_required, sort_order, is_active
) VALUES (
  'companies_house_revision_explanation',
  'bus:StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006',
  'http://xbrl.frc.org.uk/cd/2026-01-01/business',
  'StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006',
  'Companies House revision explanation',
  'text', 'companies_house_revision_explanation', NULL, 1, 'duration', NULL,
  NULL, 'duration', NULL, 0, 0, 65, 1
)
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
