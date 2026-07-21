-- Replace director-attributed loan control entries with party attribution.
-- This deliberately leaves unrelated director references (for example dividend
-- vouchers) intact and requires every loan entry to be reviewed again.

CREATE TABLE IF NOT EXISTS participator_loan_attribution_audit (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  source_type VARCHAR(64) NOT NULL,
  source_id BIGINT NOT NULL,
  old_party_id BIGINT NULL,
  new_party_id BIGINT NULL,
  changed_by VARCHAR(100) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pla_attribution_audit_source (source_type, source_id, changed_at),
  KEY idx_pla_attribution_audit_company (company_id, changed_at),
  CONSTRAINT fk_pla_attribution_audit_company FOREIGN KEY (company_id)
    REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pla_attribution_audit_old_party FOREIGN KEY (old_party_id)
    REFERENCES company_parties (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_pla_attribution_audit_new_party FOREIGN KEY (new_party_id)
    REFERENCES company_parties (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM director_loan_attribution_audit;

UPDATE journal_lines jl
INNER JOIN journals j ON j.id = jl.journal_id
SET jl.director_id = NULL, jl.party_id = NULL
WHERE EXISTS (
  SELECT 1
  FROM company_settings loan_setting
  WHERE loan_setting.company_id = j.company_id
    AND loan_setting.setting IN (
      'director_loan_asset_nominal_id',
      'director_loan_liability_nominal_id',
      'director_loan_nominal_id',
      'participator_loan_asset_nominal_id',
      'participator_loan_liability_nominal_id'
    )
    AND loan_setting.value = CAST(jl.nominal_account_id AS CHAR)
);

UPDATE transactions t
INNER JOIN journals j ON j.company_id = t.company_id
  AND j.source_type = 'bank_csv'
  AND j.source_ref = CONCAT('transaction:', t.id)
INNER JOIN journal_lines jl ON jl.journal_id = j.id
SET t.director_id = NULL, t.party_id = NULL
WHERE EXISTS (
  SELECT 1
  FROM company_settings loan_setting
  WHERE loan_setting.company_id = t.company_id
    AND loan_setting.setting IN (
      'director_loan_asset_nominal_id',
      'director_loan_liability_nominal_id',
      'director_loan_nominal_id',
      'participator_loan_asset_nominal_id',
      'participator_loan_liability_nominal_id'
    )
    AND loan_setting.value = CAST(jl.nominal_account_id AS CHAR)
);

DELETE FROM company_settings
WHERE setting IN (
  'director_loan_nominal_id',
  'director_loan_asset_nominal_id',
  'director_loan_liability_nominal_id',
  'participator_loan_asset_nominal_id',
  'participator_loan_liability_nominal_id'
);
