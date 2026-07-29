-- Rename the Companies House transmit card permission while preserving every
-- role that could access the former card key.
INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'companies_house_transmit'
FROM role_card_permissions
WHERE card_key = 'companies_house_transmission';

DELETE FROM role_card_permissions
WHERE card_key = 'companies_house_transmission';
