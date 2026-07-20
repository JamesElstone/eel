ALTER TABLE company_incorporation_share_classes
    ADD COLUMN IF NOT EXISTS issued_at DATETIME NULL AFTER company_id;

UPDATE company_incorporation_share_classes sc
INNER JOIN companies c ON c.id = sc.company_id
SET sc.issued_at = COALESCE(sc.issued_at, CONCAT(c.incorporation_date, ' 00:00:00'))
WHERE sc.issued_at IS NULL
  AND c.incorporation_date IS NOT NULL;
