-- Idempotent compatibility repair for installations that applied
-- 2026_07_26_002 before the SQLite-safe physical column name was adopted.

ALTER TABLE director_loan_reporting_presentations
  ADD COLUMN IF NOT EXISTS annual_rate_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER deferment_evidence;
