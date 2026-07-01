ALTER TABLE asset_register
  ADD COLUMN IF NOT EXISTS manual_addition_reason varchar(64) DEFAULT NULL AFTER linked_expense_claim_line_id,
  ADD COLUMN IF NOT EXISTS manual_offset_nominal_id int(11) DEFAULT NULL AFTER manual_addition_reason,
  ADD INDEX IF NOT EXISTS idx_asset_register_manual_reconcile (company_id, manual_addition_reason, linked_transaction_id),
  ADD INDEX IF NOT EXISTS idx_asset_register_manual_offset_nominal (manual_offset_nominal_id),
  ADD FOREIGN KEY IF NOT EXISTS fk_asset_register_manual_offset_nominal (manual_offset_nominal_id) REFERENCES nominal_accounts (id) ON DELETE SET NULL ON UPDATE CASCADE;
