ALTER TABLE dividend_vouchers
    ADD COLUMN shareholder_party_id BIGINT(20) NULL AFTER reversal_journal_id,
    ADD KEY idx_dividend_vouchers_shareholder_party (shareholder_party_id),
    ADD CONSTRAINT fk_dividend_vouchers_shareholder_party
        FOREIGN KEY (shareholder_party_id) REFERENCES company_parties (id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

UPDATE dividend_vouchers dv
INNER JOIN (
    SELECT dv_inner.id,
           MIN(h.party_id) AS shareholder_party_id
    FROM dividend_vouchers dv_inner
    INNER JOIN company_shareholdings h
        ON h.company_id = dv_inner.company_id
       AND h.effective_from <= dv_inner.declaration_date
       AND (h.effective_to IS NULL OR h.effective_to >= dv_inner.declaration_date)
    GROUP BY dv_inner.id
    HAVING COUNT(DISTINCT h.party_id) = 1
) unambiguous
    ON unambiguous.id = dv.id
SET dv.shareholder_party_id = unambiguous.shareholder_party_id
WHERE dv.shareholder_party_id IS NULL;
