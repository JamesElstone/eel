INSERT IGNORE INTO role_card_permissions (
  role_id,
  card_key
)
SELECT
  role_id,
  'not_an_asset'
FROM role_card_permissions
WHERE card_key = 'asset_register';
