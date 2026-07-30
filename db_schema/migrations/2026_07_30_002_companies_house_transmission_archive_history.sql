-- Make Companies House protocol evidence bundle-scoped and distinguish
-- response evidence failures from ordinary transport failures.
ALTER TABLE companies_house_protocol_exchanges
  MODIFY exchange_state enum(
    'prepared',
    'sent',
    'received',
    'succeeded',
    'rejected',
    'transport_unknown',
    'evidence_incomplete',
    'failed'
  ) NOT NULL;

ALTER TABLE companies_house_company_auth_preflights
  DROP INDEX IF EXISTS uq_ch_company_auth_preflight_reference,
  ADD INDEX IF NOT EXISTS idx_ch_company_auth_preflight_archive_reference (archive_reference);

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'companies_house_transmission_history'
FROM role_card_permissions
WHERE card_key = 'companies_house_transmit';
