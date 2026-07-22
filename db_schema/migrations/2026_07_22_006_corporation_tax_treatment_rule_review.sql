INSERT INTO corporation_tax_treatment_rules (
  rule_code,
  rule_version,
  priority,
  nominal_account_id,
  nominal_code,
  account_type,
  name_contains,
  tax_treatment,
  source_url,
  source_checked_at,
  rationale,
  review_status,
  is_active
)
VALUES (
  'professional_fees_need_review',
  'hmrc-bim35500-2026-07-22',
  71,
  NULL,
  NULL,
  'expense',
  'Professional',
  'other',
  'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim35500',
  '2026-07-22',
  'Professional fees may be revenue or capital depending on the underlying matter and require review before relying on ordinary revenue treatment.',
  'needs_review',
  1
)
ON DUPLICATE KEY UPDATE
  priority = VALUES(priority),
  nominal_account_id = VALUES(nominal_account_id),
  nominal_code = VALUES(nominal_code),
  account_type = VALUES(account_type),
  name_contains = VALUES(name_contains),
  tax_treatment = VALUES(tax_treatment),
  source_url = VALUES(source_url),
  source_checked_at = VALUES(source_checked_at),
  rationale = VALUES(rationale),
  review_status = VALUES(review_status),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;
