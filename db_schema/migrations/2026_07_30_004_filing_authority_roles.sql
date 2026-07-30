ALTER TABLE company_party_roles
  MODIFY COLUMN role_type ENUM(
    'participator',
    'associate',
    'company_secretary',
    'authorised_agent',
    'authorised_employee',
    'tax_agent_or_accountant',
    'liquidator'
  ) NOT NULL;

ALTER TABLE ct600_return_authorisations
  ADD COLUMN IF NOT EXISTS declarant_name VARCHAR(100) NULL AFTER accounting_period_id,
  ADD COLUMN IF NOT EXISTS declarant_party_id BIGINT NULL AFTER declarant_status,
  ADD COLUMN IF NOT EXISTS declarant_director_id BIGINT NULL AFTER declarant_party_id,
  ADD COLUMN IF NOT EXISTS declarant_role_id BIGINT NULL AFTER declarant_director_id,
  ADD KEY IF NOT EXISTS idx_ct600_authorisation_company (company_id),
  ADD KEY IF NOT EXISTS idx_ct600_authorisation_period (accounting_period_id),
  ADD KEY IF NOT EXISTS idx_ct600_authorisation_party (declarant_party_id),
  ADD KEY IF NOT EXISTS idx_ct600_authorisation_director (declarant_director_id),
  ADD KEY IF NOT EXISTS idx_ct600_authorisation_party_role (declarant_role_id),
  ADD CONSTRAINT fk_ct600_authorisation_company
    FOREIGN KEY IF NOT EXISTS (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ct600_authorisation_period
    FOREIGN KEY IF NOT EXISTS (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ct600_authorisation_party
    FOREIGN KEY IF NOT EXISTS (declarant_party_id) REFERENCES company_parties (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ct600_authorisation_director
    FOREIGN KEY IF NOT EXISTS (declarant_director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ct600_authorisation_party_role
    FOREIGN KEY IF NOT EXISTS (declarant_role_id) REFERENCES company_party_roles (id)
    ON DELETE SET NULL ON UPDATE CASCADE;
