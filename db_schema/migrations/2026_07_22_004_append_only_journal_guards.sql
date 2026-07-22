ALTER TABLE journals
  DROP FOREIGN KEY fk_journals_company,
  DROP FOREIGN KEY fk_journals_accounting_period;

ALTER TABLE journals
  ADD CONSTRAINT fk_journals_company
    FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_journals_accounting_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE RESTRICT ON UPDATE CASCADE;

DROP TRIGGER IF EXISTS trg_journals_append_only_update;
CREATE TRIGGER trg_journals_append_only_update
BEFORE UPDATE ON journals
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journals are append-only; post a reversal and replacement';

DROP TRIGGER IF EXISTS trg_journals_append_only_delete;
CREATE TRIGGER trg_journals_append_only_delete
BEFORE DELETE ON journals
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journals are append-only; post a reversal instead of deleting';

DROP TRIGGER IF EXISTS trg_journal_lines_append_only_update;
CREATE TRIGGER trg_journal_lines_append_only_update
BEFORE UPDATE ON journal_lines
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal lines are append-only; post a reversal and replacement';

DROP TRIGGER IF EXISTS trg_journal_lines_append_only_delete;
CREATE TRIGGER trg_journal_lines_append_only_delete
BEFORE DELETE ON journal_lines
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal lines are append-only; post a reversal instead of deleting';

DROP TRIGGER IF EXISTS trg_journal_metadata_append_only_update;
CREATE TRIGGER trg_journal_metadata_append_only_update
BEFORE UPDATE ON journal_entry_metadata
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal metadata is append-only';

DROP TRIGGER IF EXISTS trg_journal_metadata_append_only_delete;
CREATE TRIGGER trg_journal_metadata_append_only_delete
BEFORE DELETE ON journal_entry_metadata
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal metadata is append-only';
