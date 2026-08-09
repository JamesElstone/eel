INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'filing_evidence_charitable_donations'
FROM role_card_permissions
WHERE card_key = 'filing_evidence_calculations';
