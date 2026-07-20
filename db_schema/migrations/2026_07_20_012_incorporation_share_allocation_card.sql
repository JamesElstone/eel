INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'incorporation_share_allocation'
FROM role_card_permissions
WHERE card_key IN ('incorporation_ownership_parties', 'incorporation_share_capital');
