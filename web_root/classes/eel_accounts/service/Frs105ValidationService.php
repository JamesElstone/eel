<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class Frs105ValidationService
{
    public function deferredTaxNominalExposure(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->emptyDeferredTaxExposure();
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT
                na.id AS nominal_account_id,
                na.code,
                na.name,
                na.account_type,
                COALESCE(SUM(COALESCE(period_lines.debit, 0)), 0) AS total_debit,
                COALESCE(SUM(COALESCE(period_lines.credit, 0)), 0) AS total_credit,
                COALESCE(SUM(COALESCE(period_lines.debit, 0) - COALESCE(period_lines.credit, 0)), 0) AS net_movement
             FROM nominal_accounts na
             LEFT JOIN (
                SELECT jl.nominal_account_id,
                       jl.debit,
                       jl.credit
                FROM journals j
                INNER JOIN journal_lines jl ON jl.journal_id = j.id
                WHERE j.company_id = :company_id
                  AND j.accounting_period_id = :accounting_period_id
                  AND COALESCE(j.is_posted, 0) = 1
             ) period_lines ON period_lines.nominal_account_id = na.id
             WHERE COALESCE(na.is_active, 0) = 1
               AND (
                    LOWER(COALESCE(na.name, \'\')) LIKE :name_pattern
                    OR LOWER(COALESCE(na.name, \'\')) LIKE :reverse_name_pattern
                    OR LOWER(COALESCE(na.code, \'\')) LIKE :code_pattern
                    OR LOWER(COALESCE(na.code, \'\')) LIKE :reverse_code_pattern
               )
             GROUP BY na.id, na.code, na.name, na.account_type
             ORDER BY na.sort_order, na.code',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'name_pattern' => '%deferred%tax%',
                'reverse_name_pattern' => '%tax%deferred%',
                'code_pattern' => '%deferred%tax%',
                'reverse_code_pattern' => '%tax%deferred%',
            ]
        );

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($rows as &$row) {
            $row['nominal_account_id'] = (int)($row['nominal_account_id'] ?? 0);
            $row['total_debit'] = round((float)($row['total_debit'] ?? 0), 2);
            $row['total_credit'] = round((float)($row['total_credit'] ?? 0), 2);
            $row['net_movement'] = round((float)($row['net_movement'] ?? 0), 2);
            $totalDebit += (float)$row['total_debit'];
            $totalCredit += (float)$row['total_credit'];
        }
        unset($row);

        $count = count($rows);
        $netMovement = round($totalDebit - $totalCredit, 2);
        $settings = $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : [];

        return [
            'exists' => $count > 0,
            'count' => $count,
            'rows' => $rows,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'net_movement' => $netMovement,
            'detail' => $count > 0
                ? 'FRS 105 prohibits recognising deferred tax. ' . $count . ' active deferred tax nominal(s) exist; remove or reclassify them before relying on the accounts/iXBRL output. Net period exposure: ' . (new \eel_accounts\Service\CompanySettingsService())->money($settings, $netMovement) . '.'
                : 'No active deferred tax nominal was found for this FRS 105 accounts period.',
        ];
    }

    private function emptyDeferredTaxExposure(): array
    {
        return [
            'exists' => false,
            'count' => 0,
            'rows' => [],
            'total_debit' => 0.0,
            'total_credit' => 0.0,
            'net_movement' => 0.0,
            'detail' => 'No active deferred tax nominal was found for this FRS 105 accounts period.',
        ];
    }
}
