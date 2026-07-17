CREATE TABLE IF NOT EXISTS ixbrl_accounts_disclosures (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  accounting_standard VARCHAR(20) NOT NULL DEFAULT 'FRS_105',
  average_number_employees INT UNSIGNED NULL,
  entity_dormant TINYINT(1) NULL,
  entity_trading_status VARCHAR(30) NULL,
  micro_entity_eligibility_confirmed TINYINT(1) NULL,
  going_concern_basis_appropriate TINYINT(1) NULL,
  has_material_off_balance_sheet_arrangements TINYINT(1) NULL,
  has_director_advances_credits_or_guarantees TINYINT(1) NULL,
  has_financial_commitments_guarantees_or_contingencies TINYINT(1) NULL,
  accounts_approval_date DATE NULL,
  approving_director_name VARCHAR(255) NULL,
  prepared_under_small_companies_regime TINYINT(1) NULL,
  audit_exempt_section_477 TINYINT(1) NULL,
  directors_acknowledge_responsibilities TINYINT(1) NULL,
  members_have_not_required_audit TINYINT(1) NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_by VARCHAR(100) NOT NULL,
  updated_by VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ixbrl_disclosures_company_period (company_id, accounting_period_id),
  KEY idx_ixbrl_disclosures_period (accounting_period_id),
  CONSTRAINT chk_ixbrl_disclosures_standard
    CHECK (accounting_standard IN ('FRS_105')),
  CONSTRAINT chk_ixbrl_disclosures_entity_dormant
    CHECK (entity_dormant IS NULL OR entity_dormant IN (0, 1)),
  CONSTRAINT chk_ixbrl_disclosures_trading_status
    CHECK (entity_trading_status IS NULL OR entity_trading_status IN ('trading', 'never_traded', 'no_longer_trading')),
  CONSTRAINT chk_ixbrl_disclosures_micro_entity_eligibility
    CHECK (micro_entity_eligibility_confirmed IS NULL OR micro_entity_eligibility_confirmed IN (0, 1)),
  CONSTRAINT chk_ixbrl_disclosures_going_concern
    CHECK (going_concern_basis_appropriate IS NULL OR going_concern_basis_appropriate IN (0, 1)),
  CONSTRAINT chk_ixbrl_disclosures_off_balance_sheet
    CHECK (has_material_off_balance_sheet_arrangements IS NULL OR has_material_off_balance_sheet_arrangements IN (0, 1)),
  CONSTRAINT chk_ixbrl_disclosures_director_advances
    CHECK (has_director_advances_credits_or_guarantees IS NULL OR has_director_advances_credits_or_guarantees IN (0, 1)),
  CONSTRAINT chk_ixbrl_disclosures_financial_commitments
    CHECK (has_financial_commitments_guarantees_or_contingencies IS NULL OR has_financial_commitments_guarantees_or_contingencies IN (0, 1)),
  CONSTRAINT chk_ixbrl_disclosures_small_companies
    CHECK (prepared_under_small_companies_regime IS NULL OR prepared_under_small_companies_regime IN (0, 1)),
  CONSTRAINT chk_ixbrl_disclosures_audit_exempt
    CHECK (audit_exempt_section_477 IS NULL OR audit_exempt_section_477 IN (0, 1)),
  CONSTRAINT chk_ixbrl_disclosures_directors_responsibilities
    CHECK (directors_acknowledge_responsibilities IS NULL OR directors_acknowledge_responsibilities IN (0, 1)),
  CONSTRAINT chk_ixbrl_disclosures_members_audit
    CHECK (members_have_not_required_audit IS NULL OR members_have_not_required_audit IN (0, 1)),
  CONSTRAINT fk_ixbrl_disclosures_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ixbrl_disclosures_accounting_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'ixbrl_accounts_disclosures'
FROM role_card_permissions
WHERE card_key IN ('ixbrl_readiness', 'ixbrl_facts_preview');
