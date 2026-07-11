ALTER TABLE transactions
  ADD INDEX IF NOT EXISTS idx_transactions_company_period_date (company_id, accounting_period_id, txn_date);

ALTER TABLE statement_import_rows
  ADD INDEX IF NOT EXISTS idx_statement_import_rows_period_date_upload (accounting_period_id, chosen_txn_date, upload_id);

ALTER TABLE statement_uploads
  ADD INDEX IF NOT EXISTS idx_statement_uploads_company_month_period_rows (company_id, statement_month, accounting_period_id, rows_parsed);

ALTER TABLE journals
  ADD INDEX IF NOT EXISTS idx_journals_company_period_posted_date (company_id, accounting_period_id, is_posted, journal_date);
