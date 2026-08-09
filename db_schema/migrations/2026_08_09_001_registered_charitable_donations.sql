INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'charitable_donations', 'Charitable Donations', 'expense', 615, 1
WHERE NOT EXISTS (
  SELECT 1 FROM nominal_account_subtypes WHERE code = 'charitable_donations'
);

INSERT INTO nominal_accounts (
  code, name, account_type, account_subtype_id, tax_treatment,
  prepayment_candidate, is_active, sort_order
)
SELECT '6160', 'Charitable Donations', 'expense', nas.id, 'other', 0, 1, 6160
FROM nominal_account_subtypes nas
WHERE nas.code = 'charitable_donations'
  AND NOT EXISTS (SELECT 1 FROM nominal_accounts WHERE code = '6160');

UPDATE nominal_accounts na
INNER JOIN nominal_account_subtypes nas ON nas.code = 'charitable_donations'
SET na.name = 'Charitable Donations',
    na.account_type = 'expense',
    na.account_subtype_id = nas.id,
    na.tax_treatment = 'other',
    na.prepayment_candidate = 0,
    na.is_active = 1,
    na.sort_order = 6160
WHERE na.code = '6160';

INSERT INTO corporation_tax_treatment_rules (
  rule_code, rule_version, priority, nominal_account_id, nominal_code,
  account_type, tax_treatment, effective_from, source_url,
  source_checked_at, rationale, review_status, is_active
)
SELECT
  'qualifying_charitable_donation', '2026-08-09', 5, na.id, na.code,
  'expense', 'other', '2010-04-01',
  'https://www.gov.uk/tax-limited-company-gives-to-charity/donating-money',
  '2026-08-09',
  'A verified cash donation is added back in arriving at profits and separately deducted as a qualifying charitable donation. The generic treatment remains other until bank-transaction registry evidence is current.',
  'reviewed', 1
FROM nominal_accounts na
WHERE na.code = '6160'
ON DUPLICATE KEY UPDATE
  priority = VALUES(priority),
  nominal_account_id = VALUES(nominal_account_id),
  nominal_code = VALUES(nominal_code),
  account_type = VALUES(account_type),
  tax_treatment = VALUES(tax_treatment),
  effective_from = VALUES(effective_from),
  source_url = VALUES(source_url),
  source_checked_at = VALUES(source_checked_at),
  rationale = VALUES(rationale),
  review_status = VALUES(review_status),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS transaction_charitable_donation_verifications (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  transaction_id bigint(20) NOT NULL,
  authority enum('cc_ew','oscr','ccni') NOT NULL,
  registration_number varchar(32) NOT NULL,
  entity_suffix varchar(32) NOT NULL DEFAULT '',
  registered_name varchar(500) NOT NULL,
  registry_status varchar(100) NOT NULL,
  registered_on date DEFAULT NULL,
  removed_on date DEFAULT NULL,
  source_url varchar(2000) NOT NULL,
  verified_at datetime NOT NULL DEFAULT current_timestamp(),
  verified_by varchar(100) NOT NULL,
  response_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  basis_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_charity_verification_current (transaction_id, basis_sha256, id),
  KEY idx_charity_verification_period (company_id, accounting_period_id, id),
  KEY idx_charity_verification_register (authority, registration_number),
  CONSTRAINT fk_charity_verification_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_charity_verification_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_charity_verification_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_charity_verifications_append_only_update;
CREATE TRIGGER trg_charity_verifications_append_only_update
BEFORE UPDATE ON transaction_charitable_donation_verifications
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Charity verification evidence is append-only; record a new verification';

DROP TRIGGER IF EXISTS trg_charity_verifications_append_only_delete;
CREATE TRIGGER trg_charity_verifications_append_only_delete
BEFORE DELETE ON transaction_charitable_donation_verifications
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Charity verification evidence is append-only';

INSERT IGNORE INTO ct_filing_canonical_sources
  (target_scope, canonical_key, source_label, value_type, source_section, is_required)
VALUES
  ('ct600_rim', 'ct600.calculation.qualifying_charitable_donations', 'CT600 qualifying charitable donations', 'numeric', 'tax_liability', 0);

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'tax_charitable_donations'
FROM role_card_permissions
WHERE card_key = 'tax_disallowable_add_backs';
