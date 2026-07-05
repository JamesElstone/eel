ALTER TABLE journal_entry_metadata
  DROP KEY IF EXISTS uq_journal_entry_metadata_key,
  ADD KEY IF NOT EXISTS idx_journal_entry_metadata_key (company_id, accounting_period_id, journal_tag, journal_key);

DROP TABLE IF EXISTS accounting_period_adjustments;
