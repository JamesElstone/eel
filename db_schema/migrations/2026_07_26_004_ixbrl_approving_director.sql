-- Link the accounts approval to a structured company director while retaining
-- the saved name as the statutory-document snapshot.

ALTER TABLE ixbrl_accounts_disclosures
  ADD COLUMN IF NOT EXISTS approving_director_id BIGINT NULL
    AFTER accounts_approval_date,
  ADD KEY IF NOT EXISTS idx_ixbrl_disclosures_approving_director (approving_director_id),
  ADD CONSTRAINT fk_ixbrl_disclosures_approving_director
    FOREIGN KEY IF NOT EXISTS (approving_director_id) REFERENCES company_directors (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- Backfill only an unambiguous director who belonged to the company and was in
-- office on the recorded approval date. Ambiguous legacy names remain NULL and
-- must be explicitly selected in the disclosure workflow.
UPDATE ixbrl_accounts_disclosures disclosures
INNER JOIN (
  SELECT legacy.id AS disclosure_id,
         MIN(directors.id) AS director_id
  FROM ixbrl_accounts_disclosures legacy
  INNER JOIN company_directors directors
    ON directors.company_id = legacy.company_id
   AND TRIM(directors.full_name) = TRIM(legacy.approving_director_name)
   AND LOWER(TRIM(directors.officer_role)) = 'director'
   AND (directors.appointed_on IS NULL OR directors.appointed_on <= legacy.accounts_approval_date)
   AND (directors.resigned_on IS NULL OR directors.resigned_on >= legacy.accounts_approval_date)
  WHERE legacy.approving_director_id IS NULL
    AND legacy.accounts_approval_date IS NOT NULL
    AND NULLIF(TRIM(legacy.approving_director_name), '') IS NOT NULL
  GROUP BY legacy.id
  HAVING COUNT(*) = 1
) unique_director ON unique_director.disclosure_id = disclosures.id
SET disclosures.approving_director_id = unique_director.director_id
WHERE disclosures.approving_director_id IS NULL;
