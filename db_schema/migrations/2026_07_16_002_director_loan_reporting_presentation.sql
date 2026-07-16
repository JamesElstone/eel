CREATE TABLE IF NOT EXISTS director_loan_reporting_presentations (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  liability_nominal_account_id INT NOT NULL,
  classification VARCHAR(40) NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_by VARCHAR(100) NOT NULL,
  updated_by VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_dla_reporting_presentation_company_period (company_id, accounting_period_id),
  KEY idx_dla_reporting_presentation_nominal (liability_nominal_account_id),
  CONSTRAINT chk_dla_reporting_presentation_classification
    CHECK (classification IN ('within_one_year', 'after_more_than_one_year')),
  CONSTRAINT fk_dla_reporting_presentation_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dla_reporting_presentation_accounting_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dla_reporting_presentation_nominal
    FOREIGN KEY (liability_nominal_account_id) REFERENCES nominal_accounts (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS director_loan_reporting_presentation_audit (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  old_liability_nominal_account_id INT NULL,
  new_liability_nominal_account_id INT NULL,
  old_classification VARCHAR(40) NOT NULL,
  new_classification VARCHAR(40) NOT NULL,
  old_revision INT UNSIGNED NOT NULL,
  new_revision INT UNSIGNED NOT NULL,
  changed_by VARCHAR(100) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dla_reporting_presentation_audit_scope (company_id, accounting_period_id, changed_at),
  KEY idx_dla_reporting_presentation_audit_old_nominal (old_liability_nominal_account_id),
  KEY idx_dla_reporting_presentation_audit_new_nominal (new_liability_nominal_account_id),
  CONSTRAINT chk_dla_reporting_presentation_audit_old_classification
    CHECK (old_classification IN ('within_one_year', 'after_more_than_one_year')),
  CONSTRAINT chk_dla_reporting_presentation_audit_new_classification
    CHECK (new_classification IN ('within_one_year', 'after_more_than_one_year')),
  CONSTRAINT fk_dla_reporting_presentation_audit_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dla_reporting_presentation_audit_accounting_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dla_reporting_presentation_audit_old_nominal
    FOREIGN KEY (old_liability_nominal_account_id) REFERENCES nominal_accounts (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_dla_reporting_presentation_audit_new_nominal
    FOREIGN KEY (new_liability_nominal_account_id) REFERENCES nominal_accounts (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
