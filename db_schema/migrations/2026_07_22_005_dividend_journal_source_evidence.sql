DROP TRIGGER IF EXISTS trg_journals_append_only_update;

ALTER TABLE journals
  MODIFY source_type enum(
    'bank_csv',
    'director_loan_register',
    'director_loan_offset',
    'expense_register',
    'manual',
    'dividend',
    'asset_register',
    'asset_depreciation',
    'asset_disposal'
  ) NOT NULL;

UPDATE journals j
INNER JOIN dividend_vouchers dv
    ON dv.journal_id = j.id
SET j.source_type = 'dividend'
WHERE j.source_type = 'manual'
  AND COALESCE(j.source_ref, '') LIKE 'dividend:%';

UPDATE journals j
INNER JOIN dividend_vouchers dv
    ON dv.reversal_journal_id = j.id
SET j.source_type = 'dividend'
WHERE j.source_type = 'manual'
  AND COALESCE(j.source_ref, '') LIKE 'dividend:void:%';

CREATE TRIGGER trg_journals_append_only_update
BEFORE UPDATE ON journals
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journals are append-only; post a reversal and replacement';
