-- Normalise historic HMRC-imported currency labels without changing rule values.
UPDATE tax_rate_rules
SET rule_label = TRIM(
    REPLACE(REPLACE(
        REPLACE(REPLACE(
            REPLACE(REPLACE(
                rule_label,
                CONVERT(0xC382C2A3 USING utf8mb4) COLLATE utf8mb4_unicode_ci, 'GBP '
            ),
                CONVERT(0xC2A3 USING utf8mb4) COLLATE utf8mb4_unicode_ci, 'GBP '
            ),
                CONVERT(0xC382C2A0 USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' '
            ),
                CONVERT(0xC2A0 USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' '
            ),
        'rate(companies', 'rate (companies'),
        'fence(companies', 'fence (companies')
)
WHERE is_active = 1
  AND tax_domain = 'corporation_tax'
  AND regime = 'ring_fence';
