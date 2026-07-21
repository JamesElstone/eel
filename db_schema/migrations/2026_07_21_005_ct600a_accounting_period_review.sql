-- One Section 464A/464C declaration applies to all CT periods in an accounting period.
-- Existing per-CT-period reviews remain as historical records. A fresh declaration is
-- required because the new evidence manifest covers every CT period in the accounting period.

CREATE TABLE IF NOT EXISTS corporation_tax_ct600a_accounting_reviews (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  review_version VARCHAR(50) NOT NULL,
  answers_json LONGTEXT NOT NULL,
  approver_role ENUM('director','adviser') NOT NULL,
  approved_by VARCHAR(100) NOT NULL,
  confirmation_note TEXT DEFAULT NULL,
  evidence_manifest_json LONGTEXT NOT NULL,
  basis_hash CHAR(64) NOT NULL,
  confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct600a_accounting_review_period (company_id, accounting_period_id),
  CONSTRAINT fk_ct600a_accounting_review_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct600a_accounting_review_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
