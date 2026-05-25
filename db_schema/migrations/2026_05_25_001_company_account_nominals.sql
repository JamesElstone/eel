ALTER TABLE company_accounts
  ADD COLUMN nominal_account_id int(11) DEFAULT NULL AFTER account_identifier;

ALTER TABLE company_accounts
  ADD KEY idx_company_accounts_nominal (nominal_account_id);

ALTER TABLE company_accounts
  ADD CONSTRAINT fk_company_accounts_nominal
  FOREIGN KEY (nominal_account_id)
  REFERENCES nominal_accounts (id)
  ON DELETE SET NULL
  ON UPDATE CASCADE;

INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'trade_creditor', 'Trade Creditor', 'liability', 45, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'trade_creditor'
);
