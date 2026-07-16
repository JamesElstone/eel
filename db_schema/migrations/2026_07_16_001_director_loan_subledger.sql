-- Per-director loan subledger. The existing Director Loan Asset and Director
-- Loan Liability nominals remain company-level control accounts.

CREATE TABLE IF NOT EXISTS company_directors (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  source VARCHAR(50) NOT NULL DEFAULT 'companies_house',
  external_key VARCHAR(255) NOT NULL,
  full_name VARCHAR(255) NOT NULL,
  officer_role VARCHAR(100) NOT NULL DEFAULT 'director',
  appointed_on DATE NULL,
  resigned_on DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  source_json LONGTEXT NULL,
  last_synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_directors_source_identity (company_id, source, external_key),
  KEY idx_company_directors_company_status (company_id, is_active, full_name),
  KEY idx_company_directors_tenure (company_id, appointed_on, resigned_on),
  CONSTRAINT fk_company_directors_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS director_id BIGINT NULL AFTER nominal_account_id,
  ADD KEY IF NOT EXISTS idx_transactions_director (director_id),
  ADD CONSTRAINT fk_transactions_director
    FOREIGN KEY IF NOT EXISTS (director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE transaction_split_lines
  ADD COLUMN IF NOT EXISTS director_id BIGINT NULL AFTER nominal_account_id,
  ADD KEY IF NOT EXISTS idx_transaction_split_lines_director (director_id),
  ADD CONSTRAINT fk_transaction_split_lines_director
    FOREIGN KEY IF NOT EXISTS (director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE expense_claim_lines
  ADD COLUMN IF NOT EXISTS director_id BIGINT NULL AFTER nominal_account_id,
  ADD KEY IF NOT EXISTS idx_expense_claim_lines_director (director_id),
  ADD CONSTRAINT fk_expense_claim_lines_director
    FOREIGN KEY IF NOT EXISTS (director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE categorisation_rules
  ADD COLUMN IF NOT EXISTS director_id BIGINT NULL AFTER nominal_account_id,
  ADD KEY IF NOT EXISTS idx_categorisation_rules_director (director_id),
  ADD CONSTRAINT fk_categorisation_rules_director
    FOREIGN KEY IF NOT EXISTS (director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE dividend_vouchers
  ADD COLUMN IF NOT EXISTS director_id BIGINT NULL AFTER director_name,
  ADD KEY IF NOT EXISTS idx_dividend_vouchers_director (director_id),
  ADD CONSTRAINT fk_dividend_vouchers_director
    FOREIGN KEY IF NOT EXISTS (director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE journal_lines
  ADD COLUMN IF NOT EXISTS director_id BIGINT NULL AFTER nominal_account_id,
  ADD KEY IF NOT EXISTS idx_journal_lines_director (director_id),
  ADD KEY IF NOT EXISTS idx_journal_lines_nominal_director (nominal_account_id, director_id),
  ADD CONSTRAINT fk_journal_lines_director
    FOREIGN KEY IF NOT EXISTS (director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS director_loan_attribution_audit (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  source_type VARCHAR(64) NOT NULL,
  source_id BIGINT NOT NULL,
  old_director_id BIGINT NULL,
  new_director_id BIGINT NULL,
  changed_by VARCHAR(100) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dla_attribution_audit_source (source_type, source_id, changed_at),
  KEY idx_dla_attribution_audit_company (company_id, changed_at),
  KEY idx_dla_attribution_audit_old_director (old_director_id),
  KEY idx_dla_attribution_audit_new_director (new_director_id),
  CONSTRAINT fk_dla_attribution_audit_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dla_attribution_audit_old_director
    FOREIGN KEY (old_director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_dla_attribution_audit_new_director
    FOREIGN KEY (new_director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legal set-off evidence is not an accounting fact and is no longer stored.
DELETE FROM year_end_review_acknowledgements
WHERE check_code = 'director_loan_set_off_criteria';

-- A DLA rule cannot run until its director attribution is unambiguous.
UPDATE categorisation_rules cr
SET cr.is_active = 0
WHERE cr.director_id IS NULL
  AND EXISTS (
    SELECT 1
    FROM company_settings setting_row
    WHERE setting_row.company_id = cr.company_id
      AND setting_row.setting IN (
        'director_loan_asset_nominal_id',
        'director_loan_liability_nominal_id',
        'director_loan_nominal_id'
      )
      AND TRIM(COALESCE(setting_row.value, '')) = CAST(cr.nominal_account_id AS CHAR)
  );
