INSERT IGNORE INTO role_card_permissions (
  role_id,
  card_key
)
SELECT
  role_id,
  'expense_claim_create'
FROM role_card_permissions
WHERE card_key = 'expenses_state';
