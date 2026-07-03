INSERT IGNORE INTO role_card_permissions (
  role_id,
  card_key
)
SELECT
  role_id,
  'company_minutes'
FROM role_card_permissions
WHERE card_key IN ('dividend_history', 'dividend_vouchers');
