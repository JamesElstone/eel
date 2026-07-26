-- Live participator-loan terms are owned by the party, not by an accounting period.
CREATE TABLE IF NOT EXISTS participator_loan_party_terms (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  party_id BIGINT NOT NULL,
  interest_rate_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  security_type ENUM('secured','unsecured') NOT NULL DEFAULT 'unsecured',
  repayable_on_demand TINYINT(1) NOT NULL DEFAULT 1,
  repayment_timing ENUM('within_12_months','after_12_months') NOT NULL DEFAULT 'within_12_months',
  deferment_right_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  set_off_right_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  settlement_intention ENUM('net','simultaneous','independently') NOT NULL DEFAULT 'independently',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_by VARCHAR(100) NOT NULL,
  updated_by VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_participator_loan_party_terms (company_id, party_id),
  CONSTRAINT fk_participator_loan_party_terms_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_participator_loan_party_terms_party FOREIGN KEY (party_id) REFERENCES company_parties(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participator_loan_party_terms_audit (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  party_id BIGINT NOT NULL,
  old_terms_json LONGTEXT NULL,
  new_terms_json LONGTEXT NOT NULL,
  old_revision INT UNSIGNED NOT NULL,
  new_revision INT UNSIGNED NOT NULL,
  changed_by VARCHAR(100) NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_participator_loan_party_terms_audit (company_id, party_id, changed_at),
  CONSTRAINT fk_participator_loan_party_terms_audit_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_participator_loan_party_terms_audit_party FOREIGN KEY (party_id) REFERENCES company_parties(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participator_loan_party_term_snapshots (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  party_id BIGINT NOT NULL,
  liability_nominal_account_id INT NULL,
  terms_json LONGTEXT NOT NULL,
  created_by VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_participator_loan_party_term_snapshot (company_id, accounting_period_id, party_id),
  CONSTRAINT fk_participator_loan_party_term_snapshots_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_participator_loan_party_term_snapshots_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_participator_loan_party_term_snapshots_party FOREIGN KEY (party_id) REFERENCES company_parties(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_participator_loan_party_term_snapshots_nominal FOREIGN KEY (liability_nominal_account_id) REFERENCES nominal_accounts(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The replaced company-level data is deliberately not carried forward.
DROP TABLE IF EXISTS director_loan_reporting_presentation_audit;
DROP TABLE IF EXISTS director_loan_reporting_presentations;
