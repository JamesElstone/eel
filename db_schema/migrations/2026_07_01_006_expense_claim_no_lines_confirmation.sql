ALTER TABLE expense_claims
  ADD COLUMN IF NOT EXISTS no_lines_confirmed_at datetime DEFAULT NULL AFTER posted_journal_id,
  ADD COLUMN IF NOT EXISTS no_lines_confirmed_by varchar(100) DEFAULT NULL AFTER no_lines_confirmed_at;
