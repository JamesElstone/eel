<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompanyMinutesService
{
    public function listMinutes(int $companyId, int $accountingPeriodId, int $limit = 500): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->minutesTablesAvailable()) {
            return [];
        }

        $limit = max(1, min($limit, 1000));
        $stmt = \InterfaceDB::prepare(
            "SELECT dv.declaration_date AS date,
                    dv.minutes_text AS minutes,
                    'dividend_voucher' AS source_type,
                    dv.id AS source_id
             FROM dividend_vouchers dv
             INNER JOIN accounting_periods ap
                ON ap.id = dv.accounting_period_id
               AND ap.company_id = dv.company_id
             WHERE dv.company_id = :company_id
               AND dv.accounting_period_id = :accounting_period_id
               AND dv.declaration_date BETWEEN ap.period_start AND ap.period_end
               AND TRIM(COALESCE(dv.minutes_text, '')) <> ''
             ORDER BY dv.declaration_date DESC, dv.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        return array_values(array_filter(
            $stmt->fetchAll() ?: [],
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function minutesTablesAvailable(): bool
    {
        return \InterfaceDB::tableExists('dividend_vouchers')
            && \InterfaceDB::tableExists('accounting_periods');
    }
}
