-- Automated, append-only accounting-period prepayment schedules.
INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'prepayments', 'Prepayments', 'asset', 25, 1
WHERE NOT EXISTS (
    SELECT 1 FROM nominal_account_subtypes WHERE code = 'prepayments'
);

-- Nominal codes are user-visible accounting identities. If 1150 is absent,
-- insert it. If the correct Prepayments account already exists, this is a
-- no-op. A conflicting 1150 deliberately reaches the unique code constraint
-- and aborts instead of silently repurposing the account.
INSERT INTO nominal_accounts (
    code, name, account_type, account_subtype_id, tax_treatment,
    prepayment_candidate, is_active, sort_order, origin_type
)
SELECT '1150', 'Prepayments', 'asset', nas.id, 'other', 0, 1, 25, 'manual'
FROM nominal_account_subtypes nas
WHERE nas.code = 'prepayments'
  AND NOT EXISTS (
      SELECT 1
      FROM nominal_accounts
      WHERE code = '1150'
        AND account_type = 'asset'
        AND LOWER(TRIM(name)) = 'prepayments'
  );

UPDATE nominal_accounts na
INNER JOIN nominal_account_subtypes nas ON nas.code = 'prepayments'
SET na.account_subtype_id = nas.id,
    na.account_type = 'asset',
    na.tax_treatment = 'other',
    na.is_active = 1
WHERE na.code = '1150'
  AND na.account_type = 'asset'
  AND LOWER(TRIM(na.name)) = 'prepayments';

INSERT INTO company_settings (company_id, setting, type, value, created_at, updated_at)
SELECT c.id, 'prepayment_asset_nominal_id', 'int', CAST(na.id AS CHAR), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM companies c
INNER JOIN nominal_accounts na ON na.code = '1150' AND na.account_type = 'asset' AND na.is_active = 1
WHERE NOT EXISTS (
    SELECT 1 FROM company_settings cs
    WHERE cs.company_id = c.id AND cs.setting = 'prepayment_asset_nominal_id'
);

UPDATE prepayment_reviews
SET status = 'not_prepaid',
    service_start_date = NULL,
    service_end_date = NULL,
    reviewed_at = COALESCE(reviewed_at, CURRENT_TIMESTAMP),
    reviewed_by = COALESCE(reviewed_by, 'migration')
WHERE status = 'pending';

ALTER TABLE prepayment_reviews
  MODIFY source_type enum('transaction','transaction_split_line','expense_claim_line') NOT NULL,
  MODIFY status enum('not_prepaid','prepaid') NOT NULL DEFAULT 'not_prepaid';

CREATE TABLE IF NOT EXISTS prepayment_schedules (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  review_id bigint(20) NOT NULL,
  version_no int(11) NOT NULL,
  company_id int(11) NOT NULL,
  source_accounting_period_id int(11) NOT NULL,
  source_type enum('transaction','transaction_split_line','expense_claim_line') NOT NULL,
  source_id bigint(20) NOT NULL,
  source_journal_id bigint(20) NOT NULL,
  source_journal_line_id bigint(20) NOT NULL,
  source_date date NOT NULL,
  source_amount_pence bigint(20) NOT NULL,
  original_expense_nominal_id int(11) NOT NULL,
  asset_nominal_id int(11) NOT NULL,
  service_start_date date NOT NULL,
  service_end_date date NOT NULL,
  total_days int(11) NOT NULL,
  calculation_version smallint(5) unsigned NOT NULL DEFAULT 2,
  calculation_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status enum('draft','active','superseded','complete','needs_review') NOT NULL DEFAULT 'active',
  superseded_by_schedule_id bigint(20) DEFAULT NULL,
  created_by varchar(100) NOT NULL DEFAULT 'web_app',
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_prepayment_schedules_review_version (review_id, version_no),
  KEY idx_prepayment_schedules_company_source_period (company_id, source_accounting_period_id, status),
  KEY idx_prepayment_schedules_source (company_id, source_type, source_id),
  KEY idx_prepayment_schedules_source_journal (source_journal_id),
  KEY idx_prepayment_schedules_source_line (source_journal_line_id),
  KEY idx_prepayment_schedules_asset_nominal (asset_nominal_id),
  KEY idx_prepayment_schedules_superseded_by (superseded_by_schedule_id),
  CONSTRAINT fk_prepayment_schedules_review FOREIGN KEY (review_id) REFERENCES prepayment_reviews (id) ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_schedules_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_schedules_source_period FOREIGN KEY (source_accounting_period_id) REFERENCES accounting_periods (id) ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_schedules_source_journal FOREIGN KEY (source_journal_id) REFERENCES journals (id) ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_schedules_source_line FOREIGN KEY (source_journal_line_id) REFERENCES journal_lines (id) ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_schedules_expense_nominal FOREIGN KEY (original_expense_nominal_id) REFERENCES nominal_accounts (id) ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_schedules_asset_nominal FOREIGN KEY (asset_nominal_id) REFERENCES nominal_accounts (id) ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_schedules_superseded_by FOREIGN KEY (superseded_by_schedule_id) REFERENCES prepayment_schedules (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_prepayment_schedules_amount CHECK (source_amount_pence > 0),
  CONSTRAINT chk_prepayment_schedules_days CHECK (total_days > 0),
  CONSTRAINT chk_prepayment_schedules_dates CHECK (service_start_date <= service_end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A partially applied development migration can already have the schedule
-- table. Preserve those hashes as version 1, then make version 2 the default
-- for every newly-created snapshot.
ALTER TABLE prepayment_schedules
  ADD COLUMN IF NOT EXISTS calculation_version smallint(5) unsigned NOT NULL DEFAULT 1 AFTER total_days;

ALTER TABLE prepayment_schedules
  MODIFY calculation_version smallint(5) unsigned NOT NULL DEFAULT 2;

CREATE TABLE IF NOT EXISTS prepayment_schedule_periods (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  schedule_id bigint(20) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  period_start date NOT NULL,
  period_end date NOT NULL,
  overlap_start date DEFAULT NULL,
  overlap_end date DEFAULT NULL,
  overlap_days int(11) NOT NULL,
  expense_pence bigint(20) NOT NULL,
  opening_deferred_pence bigint(20) NOT NULL,
  closing_deferred_pence bigint(20) NOT NULL,
  is_source_period tinyint(1) NOT NULL DEFAULT 0,
  allocation_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_prepayment_schedule_period (schedule_id, accounting_period_id),
  KEY idx_prepayment_schedule_period_accounting_period (accounting_period_id, schedule_id),
  CONSTRAINT fk_prepayment_schedule_period_schedule FOREIGN KEY (schedule_id) REFERENCES prepayment_schedules (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_schedule_period_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON UPDATE CASCADE,
  CONSTRAINT chk_prepayment_schedule_period_days CHECK (overlap_days >= 0),
  CONSTRAINT chk_prepayment_schedule_period_expense CHECK (expense_pence >= 0),
  CONSTRAINT chk_prepayment_schedule_period_opening CHECK (opening_deferred_pence >= 0),
  CONSTRAINT chk_prepayment_schedule_period_closing CHECK (closing_deferred_pence >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prepayment_schedule_postings (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  schedule_id bigint(20) NOT NULL,
  schedule_period_id bigint(20) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  journal_id bigint(20) NOT NULL,
  posting_role enum('deferral','release') NOT NULL,
  posting_type enum('deferral','release','correction','reopen_compensation') NOT NULL,
  effect_pence bigint(20) NOT NULL,
  target_pence bigint(20) NOT NULL,
  calculation_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by varchar(100) NOT NULL DEFAULT 'web_app',
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_prepayment_schedule_posting_journal (journal_id),
  KEY idx_prepayment_postings_schedule_period (schedule_id, schedule_period_id, posting_role),
  KEY idx_prepayment_postings_accounting_period (accounting_period_id, posting_role),
  CONSTRAINT fk_prepayment_postings_schedule FOREIGN KEY (schedule_id) REFERENCES prepayment_schedules (id) ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_postings_schedule_period FOREIGN KEY (schedule_period_id) REFERENCES prepayment_schedule_periods (id) ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_postings_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON UPDATE CASCADE,
  CONSTRAINT fk_prepayment_postings_journal FOREIGN KEY (journal_id) REFERENCES journals (id) ON UPDATE CASCADE,
  CONSTRAINT chk_prepayment_postings_effect CHECK (effect_pence <> 0),
  CONSTRAINT chk_prepayment_postings_target CHECK (target_pence >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE prepayment_reviews
  ADD COLUMN IF NOT EXISTS current_schedule_id bigint(20) DEFAULT NULL AFTER reversal_journal_id,
  ADD KEY IF NOT EXISTS idx_prepayment_reviews_current_schedule (current_schedule_id);

ALTER TABLE prepayment_reviews
  ADD CONSTRAINT fk_prepayment_reviews_current_schedule
  FOREIGN KEY IF NOT EXISTS (current_schedule_id)
  REFERENCES prepayment_schedules (id)
  ON DELETE SET NULL
  ON UPDATE CASCADE;
