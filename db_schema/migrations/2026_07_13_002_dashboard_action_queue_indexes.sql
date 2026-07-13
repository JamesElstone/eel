ALTER TABLE transactions
  ADD INDEX IF NOT EXISTS idx_transactions_company_period_category_status (company_id, accounting_period_id, category_status);
