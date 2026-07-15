INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'tax_prepayment_treatment'
FROM role_card_permissions
WHERE card_key IN ('tax_taxable_profit_bridge', 'prepayments_review');
