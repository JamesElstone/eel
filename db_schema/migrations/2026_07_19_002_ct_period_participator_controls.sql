-- Effective-dated ownership, participator-loan evidence, and CT-period tax facts.

CREATE TABLE IF NOT EXISTS company_parties (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  party_type ENUM('individual','company','trust','partnership','other') NOT NULL DEFAULT 'individual',
  legal_name VARCHAR(255) NOT NULL,
  linked_director_id BIGINT NULL,
  source_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_company_parties_company_name (company_id, legal_name),
  UNIQUE KEY uq_company_parties_linked_director (company_id, linked_director_id),
  CONSTRAINT fk_company_parties_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_company_parties_director FOREIGN KEY (linked_director_id) REFERENCES company_directors(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_party_roles (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  party_id BIGINT NOT NULL,
  role_type ENUM('shareholder','participator','associate') NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  source_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_company_party_roles_effective (company_id, role_type, effective_from, effective_to),
  KEY idx_company_party_roles_party (party_id, effective_from, effective_to),
  CONSTRAINT fk_company_party_roles_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_company_party_roles_party FOREIGN KEY (party_id) REFERENCES company_parties(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_company_party_roles_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_shareholdings (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  party_id BIGINT NOT NULL,
  share_class_id INT NOT NULL,
  quantity INT NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  source_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_company_shareholdings_effective (company_id, share_class_id, effective_from, effective_to),
  KEY idx_company_shareholdings_party (party_id, effective_from, effective_to),
  CONSTRAINT fk_company_shareholdings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_company_shareholdings_party FOREIGN KEY (party_id) REFERENCES company_parties(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_company_shareholdings_class FOREIGN KEY (share_class_id) REFERENCES company_incorporation_share_classes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_company_shareholdings_quantity CHECK (quantity > 0),
  CONSTRAINT chk_company_shareholdings_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corporation_tax_period_facts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  ct_period_id INT NOT NULL,
  associated_company_count INT NOT NULL DEFAULT 0,
  confirmed_at DATETIME NULL,
  confirmed_by VARCHAR(100) NULL,
  confirmation_note TEXT NULL,
  basis_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_period_facts_period (ct_period_id),
  KEY idx_ct_period_facts_company_period (company_id, accounting_period_id),
  CONSTRAINT fk_ct_period_facts_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_period_facts_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_period_facts_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_ct_period_facts_associated_count CHECK (associated_company_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS s455_rate_rules (
  id INT NOT NULL AUTO_INCREMENT,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  rate DECIMAL(9,6) NOT NULL,
  source_note VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_s455_rate_rules_effective (is_active, effective_from, effective_to),
  CONSTRAINT chk_s455_rate_rules_rate CHECK (rate >= 0 AND rate <= 1),
  CONSTRAINT chk_s455_rate_rules_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO s455_rate_rules (effective_from, effective_to, rate, source_note)
SELECT '2016-04-06', '2022-04-05', 0.325000, 'CTA 2010 s455 dated local catalogue'
WHERE NOT EXISTS (SELECT 1 FROM s455_rate_rules WHERE effective_from = '2016-04-06');

INSERT INTO s455_rate_rules (effective_from, effective_to, rate, source_note)
SELECT '2022-04-06', NULL, 0.337500, 'CTA 2010 s455 dated local catalogue'
WHERE NOT EXISTS (SELECT 1 FROM s455_rate_rules WHERE effective_from = '2022-04-06');

CREATE TABLE IF NOT EXISTS corporation_tax_s455_reviews (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  ct_period_id INT NOT NULL,
  close_company_status ENUM('unconfirmed','yes','no') NOT NULL DEFAULT 'unconfirmed',
  gross_principal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  gross_tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  qualifying_repayments DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  relief_tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  net_tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ct600a_required TINYINT(1) NOT NULL DEFAULT 0,
  repayment_deadline DATE NOT NULL,
  evidence_cutoff DATETIME NOT NULL,
  window_status ENUM('provisional_window_open','window_complete') NOT NULL,
  basis_hash CHAR(64) NOT NULL,
  basis_json LONGTEXT NOT NULL,
  confirmed_at DATETIME NULL,
  confirmed_by VARCHAR(100) NULL,
  confirmation_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_s455_review_period (ct_period_id),
  KEY idx_ct_s455_review_company_period (company_id, accounting_period_id),
  CONSTRAINT fk_ct_s455_review_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_s455_review_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_s455_review_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS party_id BIGINT NULL AFTER director_id,
  ADD KEY IF NOT EXISTS idx_transactions_party (party_id),
  ADD CONSTRAINT fk_transactions_party FOREIGN KEY IF NOT EXISTS (party_id) REFERENCES company_parties(id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE journal_lines
  ADD COLUMN IF NOT EXISTS party_id BIGINT NULL AFTER director_id,
  ADD KEY IF NOT EXISTS idx_journal_lines_party (party_id),
  ADD KEY IF NOT EXISTS idx_journal_lines_nominal_party (nominal_account_id, party_id),
  ADD CONSTRAINT fk_journal_lines_party FOREIGN KEY IF NOT EXISTS (party_id) REFERENCES company_parties(id) ON DELETE SET NULL ON UPDATE CASCADE;

INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'participator_loan_asset', 'Participator Loan Asset', 'asset', 31, 1
WHERE NOT EXISTS (SELECT 1 FROM nominal_account_subtypes WHERE code = 'participator_loan_asset');

INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'participator_loan_liability', 'Participator Loan Liability', 'liability', 51, 1
WHERE NOT EXISTS (SELECT 1 FROM nominal_account_subtypes WHERE code = 'participator_loan_liability');

INSERT INTO corporation_tax_period_facts (
  company_id, accounting_period_id, ct_period_id, associated_company_count
)
SELECT ctp.company_id,
       ctp.accounting_period_id,
       ctp.id,
       GREATEST(0, CAST(COALESCE(cs.value, '0') AS SIGNED))
FROM corporation_tax_periods ctp
LEFT JOIN company_settings cs
  ON cs.company_id = ctp.company_id
 AND cs.setting = 'associated_company_count'
WHERE NOT EXISTS (
  SELECT 1 FROM corporation_tax_period_facts existing_fact WHERE existing_fact.ct_period_id = ctp.id
);

DELETE FROM company_settings WHERE setting = 'associated_company_count';

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT existing_permission.role_id, new_card.card_key
FROM role_card_permissions existing_permission
INNER JOIN (
  SELECT 'incorporation_ownership_parties' AS card_key
  UNION ALL SELECT 'tax_ct_period_facts'
  UNION ALL SELECT 'director_loan_s455'
) new_card
WHERE existing_permission.card_key IN ('incorporation_share_capital','tax_rate_bands','director_loan_state');
