ALTER TABLE statement_uploads
  ADD INDEX IF NOT EXISTS idx_statement_uploads_company_account_source_uploaded (company_id, account_id, source_type, uploaded_at, id);
