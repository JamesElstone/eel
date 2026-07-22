CREATE TABLE IF NOT EXISTS corporation_tax_line_treatment_decisions (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  journal_id bigint(20) NOT NULL,
  journal_line_id bigint(20) NOT NULL,
  tax_treatment enum('allowable','disallowable','capital') NOT NULL,
  basis_hash char(64) NOT NULL,
  rule_id int(11) DEFAULT NULL,
  rule_code varchar(64) NOT NULL DEFAULT '',
  rule_version varchar(32) NOT NULL DEFAULT '',
  decided_by varchar(255) NOT NULL,
  decided_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_ct_line_treatment_latest (journal_line_id,id),
  KEY idx_ct_line_treatment_period (company_id,accounting_period_id,id),
  CONSTRAINT fk_ct_line_treatment_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_line_treatment_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_line_treatment_journal FOREIGN KEY (journal_id) REFERENCES journals (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_line_treatment_line FOREIGN KEY (journal_line_id) REFERENCES journal_lines (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_line_treatment_rule FOREIGN KEY (rule_id) REFERENCES corporation_tax_treatment_rules (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'corporation_tax_review'
FROM role_card_permissions
WHERE card_key = 'year_end_tax_readiness';

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'loan_review'
FROM role_card_permissions
WHERE card_key = 'director_loan_s455';
