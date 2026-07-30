-- Rename the GovTalk history card permission while preserving every role
-- that could access the former Companies House-specific card key.
INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'govtalk_transmission_history'
FROM role_card_permissions
WHERE card_key = 'companies_house_transmission_history';

DELETE FROM role_card_permissions
WHERE card_key = 'companies_house_transmission_history';
