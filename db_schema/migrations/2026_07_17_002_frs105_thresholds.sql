-- Sourced FRS 105 micro-entity thresholds. The Rates / Thresholds refresh can
-- replace the current period rows from GOV.UK; historical rows remain stable.
INSERT INTO tax_rate_rules (
    tax_domain, regime, rule_key, rule_label, period_start, period_end,
    value_type, rate_value, amount_value, fraction_value, source_url,
    source_updated_at, source_checked_at, rule_version, is_active, notes
) VALUES
    ('company_size', 'frs105_micro_entity', 'turnover', 'FRS 105 micro-entity turnover threshold', '1900-01-01', '2025-04-05', 'amount', NULL, 632000.00, NULL, 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies', NULL, '2026-07-17', 'govuk-frs105-pre-2025-turnover', 1, 'Historical micro-entity turnover threshold used for periods beginning before 6 April 2025.'),
    ('company_size', 'frs105_micro_entity', 'balance_sheet_total', 'FRS 105 micro-entity balance-sheet threshold', '1900-01-01', '2025-04-05', 'amount', NULL, 316000.00, NULL, 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies', NULL, '2026-07-17', 'govuk-frs105-pre-2025-balance-sheet', 1, 'Historical micro-entity balance-sheet threshold used for periods beginning before 6 April 2025.'),
    ('company_size', 'frs105_micro_entity', 'employees', 'FRS 105 micro-entity employee threshold', '1900-01-01', '2025-04-05', 'amount', NULL, 10.00, NULL, 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies', NULL, '2026-07-17', 'govuk-frs105-pre-2025-employees', 1, 'Historical micro-entity employee threshold used for periods beginning before 6 April 2025.'),
    ('company_size', 'frs105_micro_entity', 'turnover', 'FRS 105 micro-entity turnover threshold', '2025-04-06', '9999-12-31', 'amount', NULL, 1000000.00, NULL, 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies', NULL, '2026-07-17', 'govuk-frs105-2025-turnover', 1, 'Current GOV.UK micro-entity turnover threshold.'),
    ('company_size', 'frs105_micro_entity', 'balance_sheet_total', 'FRS 105 micro-entity balance-sheet threshold', '2025-04-06', '9999-12-31', 'amount', NULL, 500000.00, NULL, 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies', NULL, '2026-07-17', 'govuk-frs105-2025-balance-sheet', 1, 'Current GOV.UK micro-entity balance-sheet threshold.'),
    ('company_size', 'frs105_micro_entity', 'employees', 'FRS 105 micro-entity employee threshold', '2025-04-06', '9999-12-31', 'amount', NULL, 10.00, NULL, 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies', NULL, '2026-07-17', 'govuk-frs105-2025-employees', 1, 'Current GOV.UK micro-entity employee threshold.')
ON DUPLICATE KEY UPDATE
    rule_label = VALUES(rule_label),
    value_type = VALUES(value_type),
    amount_value = VALUES(amount_value),
    source_url = VALUES(source_url),
    source_checked_at = VALUES(source_checked_at),
    is_active = VALUES(is_active),
    notes = VALUES(notes),
    updated_at = CURRENT_TIMESTAMP;
