INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'asset_disposal_clearing', 'Asset Disposal Clearing', 'asset', 149, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'asset_disposal_clearing'
);

INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
SELECT '1490', 'Asset Disposal Clearing', 'asset', nas.id, 'other', 1, 149
FROM nominal_account_subtypes nas
WHERE nas.code = 'asset_disposal_clearing'
  AND NOT EXISTS (
    SELECT 1
    FROM nominal_accounts
    WHERE code = '1490'
  );

CREATE TABLE IF NOT EXISTS asset_disposal_transaction_links (
  id bigint NOT NULL AUTO_INCREMENT,
  asset_id bigint NOT NULL,
  transaction_id bigint NOT NULL,
  linked_amount decimal(12,2) NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_asset_disposal_transaction_links_asset (asset_id),
  UNIQUE KEY uq_asset_disposal_transaction_links_transaction (transaction_id),
  CONSTRAINT fk_asset_disposal_transaction_links_asset FOREIGN KEY (asset_id) REFERENCES asset_register (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_asset_disposal_transaction_links_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_asset_disposal_transaction_links_amount CHECK (linked_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
