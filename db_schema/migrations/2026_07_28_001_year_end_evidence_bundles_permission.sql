INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'year_end_evidence_bundles'
FROM role_card_permissions
WHERE card_key IN ('year_end_state', 'year_end_audit_log');
