-- Preserve explicit filing authority evidence and make HMRC conversation
-- cleanup attempts independently auditable and safely retryable.

ALTER TABLE hmrc_ct600_submissions
  ADD COLUMN IF NOT EXISTS authority_confirmed tinyint(1) NOT NULL DEFAULT 0 AFTER declaration_confirmed,
  ADD COLUMN IF NOT EXISTS authority_confirmed_at datetime DEFAULT NULL AFTER authority_confirmed,
  ADD COLUMN IF NOT EXISTS authority_confirmed_by varchar(255) DEFAULT NULL AFTER authority_confirmed_at,
  ADD COLUMN IF NOT EXISTS cleanup_attempts int(11) NOT NULL DEFAULT 0 AFTER cleanup_error;
