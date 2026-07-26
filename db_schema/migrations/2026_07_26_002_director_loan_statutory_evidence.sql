-- Evidence and disclosure terms for Director Loan statutory presentation.
--
-- A same-party asset and liability may only be set off when both IAS 32-style
-- conditions have been explicitly confirmed. A liability may only be shown
-- after more than one year when an unconditional right to defer settlement for
-- at least twelve months existed at the balance-sheet date.

ALTER TABLE director_loan_reporting_presentations
  ADD COLUMN IF NOT EXISTS set_off_right_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER classification,
  ADD COLUMN IF NOT EXISTS set_off_net_settlement_intended TINYINT(1) NOT NULL DEFAULT 0 AFTER set_off_right_confirmed,
  ADD COLUMN IF NOT EXISTS set_off_evidence VARCHAR(2000) NOT NULL DEFAULT '' AFTER set_off_net_settlement_intended,
  ADD COLUMN IF NOT EXISTS deferment_right_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER set_off_evidence,
  ADD COLUMN IF NOT EXISTS deferment_evidence VARCHAR(2000) NOT NULL DEFAULT '' AFTER deferment_right_confirmed,
  ADD COLUMN IF NOT EXISTS annual_rate_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER deferment_evidence,
  ADD COLUMN IF NOT EXISTS main_terms VARCHAR(1000) NOT NULL DEFAULT 'Unsecured.' AFTER annual_rate_percent,
  ADD COLUMN IF NOT EXISTS repayment_conditions VARCHAR(1000) NOT NULL DEFAULT 'Repayable on demand.' AFTER main_terms;

ALTER TABLE director_loan_reporting_presentation_audit
  ADD COLUMN IF NOT EXISTS old_evidence_json LONGTEXT NULL AFTER new_classification,
  ADD COLUMN IF NOT EXISTS new_evidence_json LONGTEXT NULL AFTER old_evidence_json;
