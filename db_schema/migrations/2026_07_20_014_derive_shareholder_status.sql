DELETE FROM company_party_roles
WHERE role_type = 'shareholder';

ALTER TABLE company_party_roles
  MODIFY COLUMN role_type ENUM('participator','associate') NOT NULL;
