INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'api_keys_editor'
FROM role_card_permissions
WHERE card_key = 'api_mode';
