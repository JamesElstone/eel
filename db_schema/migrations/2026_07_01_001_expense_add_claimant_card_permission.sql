INSERT IGNORE INTO role_card_permissions (
  role_id,
  card_key
)
SELECT
  role_id,
  'expense_add_claimant'
FROM role_card_permissions
WHERE card_key = 'expense_claimants';
