-- Ensure an already migrated shared GovTalk ledger can allocate new exchange
-- identities after preserving the Companies House legacy IDs.

ALTER TABLE govtalk_protocol_exchanges
  MODIFY id bigint(20) NOT NULL AUTO_INCREMENT;
