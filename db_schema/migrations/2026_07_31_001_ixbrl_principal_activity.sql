ALTER TABLE ixbrl_accounts_disclosures
  ADD COLUMN IF NOT EXISTS principal_activity_sic_code VARCHAR(10) NULL
    AFTER average_number_employees,
  ADD COLUMN IF NOT EXISTS principal_activity_statement VARCHAR(512) NULL
    AFTER principal_activity_sic_code;

INSERT INTO ixbrl_fact_mappings (
  fact_key, taxonomy_concept, namespace_uri, local_name, label, value_type,
  calculation_type, source_key, sign_multiplier, period_type, unit_ref,
  decimals_value, context_profile, dimensions_json, comparative_enabled,
  is_required, sort_order, is_active
) VALUES (
  'principal_activity_description',
  'bus:DescriptionPrincipalActivities',
  'http://xbrl.frc.org.uk/cd/2026-01-01/business',
  'DescriptionPrincipalActivities',
  'Principal activity',
  'text', 'disclosure_field', 'principal_activity_statement', 1, 'duration', NULL,
  NULL, 'duration', NULL, 0, 1, 305, 1
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
