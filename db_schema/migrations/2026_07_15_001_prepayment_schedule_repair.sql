-- Repair installations where the original prepayment schedule migration was
-- recorded before calculation-version support was added to that file.
-- Existing snapshots are legacy version 1; new snapshots use version 2.
ALTER TABLE prepayment_schedules
  ADD COLUMN IF NOT EXISTS calculation_version smallint(5) unsigned NOT NULL DEFAULT 1 AFTER total_days;

ALTER TABLE prepayment_schedules
  MODIFY calculation_version smallint(5) unsigned NOT NULL DEFAULT 2;

-- Reassert the graph indexes and relationships which must exist before the
-- posting service can regard the repaired schema as production-ready. These
-- clauses are no-ops on complete version-1 and version-2 installations.
ALTER TABLE prepayment_schedules
  ADD UNIQUE KEY IF NOT EXISTS uq_prepayment_schedules_review_version (review_id, version_no),
  ADD KEY IF NOT EXISTS idx_prepayment_schedules_company_source_period (company_id, source_accounting_period_id, status),
  ADD KEY IF NOT EXISTS idx_prepayment_schedules_source (company_id, source_type, source_id),
  ADD KEY IF NOT EXISTS idx_prepayment_schedules_source_journal (source_journal_id),
  ADD KEY IF NOT EXISTS idx_prepayment_schedules_source_line (source_journal_line_id),
  ADD KEY IF NOT EXISTS idx_prepayment_schedules_asset_nominal (asset_nominal_id),
  ADD KEY IF NOT EXISTS idx_prepayment_schedules_superseded_by (superseded_by_schedule_id);

ALTER TABLE prepayment_schedules
  ADD CONSTRAINT fk_prepayment_schedules_review FOREIGN KEY IF NOT EXISTS (review_id) REFERENCES prepayment_reviews (id) ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_schedules_company FOREIGN KEY IF NOT EXISTS (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_schedules_source_period FOREIGN KEY IF NOT EXISTS (source_accounting_period_id) REFERENCES accounting_periods (id) ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_schedules_source_journal FOREIGN KEY IF NOT EXISTS (source_journal_id) REFERENCES journals (id) ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_schedules_source_line FOREIGN KEY IF NOT EXISTS (source_journal_line_id) REFERENCES journal_lines (id) ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_schedules_expense_nominal FOREIGN KEY IF NOT EXISTS (original_expense_nominal_id) REFERENCES nominal_accounts (id) ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_schedules_asset_nominal FOREIGN KEY IF NOT EXISTS (asset_nominal_id) REFERENCES nominal_accounts (id) ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_schedules_superseded_by FOREIGN KEY IF NOT EXISTS (superseded_by_schedule_id) REFERENCES prepayment_schedules (id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE prepayment_schedule_periods
  ADD UNIQUE KEY IF NOT EXISTS uq_prepayment_schedule_period (schedule_id, accounting_period_id),
  ADD KEY IF NOT EXISTS idx_prepayment_schedule_period_accounting_period (accounting_period_id, schedule_id);

ALTER TABLE prepayment_schedule_periods
  ADD CONSTRAINT fk_prepayment_schedule_period_schedule FOREIGN KEY IF NOT EXISTS (schedule_id) REFERENCES prepayment_schedules (id) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_schedule_period_period FOREIGN KEY IF NOT EXISTS (accounting_period_id) REFERENCES accounting_periods (id) ON UPDATE CASCADE;

ALTER TABLE prepayment_schedule_postings
  ADD UNIQUE KEY IF NOT EXISTS uq_prepayment_schedule_posting_journal (journal_id),
  ADD KEY IF NOT EXISTS idx_prepayment_postings_schedule_period (schedule_id, schedule_period_id, posting_role),
  ADD KEY IF NOT EXISTS idx_prepayment_postings_accounting_period (accounting_period_id, posting_role);

ALTER TABLE prepayment_schedule_postings
  ADD CONSTRAINT fk_prepayment_postings_schedule FOREIGN KEY IF NOT EXISTS (schedule_id) REFERENCES prepayment_schedules (id) ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_postings_schedule_period FOREIGN KEY IF NOT EXISTS (schedule_period_id) REFERENCES prepayment_schedule_periods (id) ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_postings_accounting_period FOREIGN KEY IF NOT EXISTS (accounting_period_id) REFERENCES accounting_periods (id) ON UPDATE CASCADE,
  ADD CONSTRAINT fk_prepayment_postings_journal FOREIGN KEY IF NOT EXISTS (journal_id) REFERENCES journals (id) ON UPDATE CASCADE;

ALTER TABLE prepayment_reviews
  ADD KEY IF NOT EXISTS idx_prepayment_reviews_current_schedule (current_schedule_id);

ALTER TABLE prepayment_reviews
  ADD CONSTRAINT fk_prepayment_reviews_current_schedule
  FOREIGN KEY IF NOT EXISTS (current_schedule_id)
  REFERENCES prepayment_schedules (id)
  ON DELETE SET NULL
  ON UPDATE CASCADE;

-- Repeat the downstream Tax-card permission copy so a partially applied
-- installation is repaired without depending on the old migration rerunning.
INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'tax_prepayment_treatment'
FROM role_card_permissions
WHERE card_key IN ('tax_taxable_profit_bridge', 'prepayments_review');
