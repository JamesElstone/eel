<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class IxbrlTrialBalanceService
{
    public function getTrialBalance(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT
                na.id AS nominal_account_id,
                na.code,
                na.name,
                na.account_type,
                nas.code AS subtype_code,
                nas.name AS subtype_name,
                COALESCE(SUM(jl.debit), 0) AS total_debit,
                COALESCE(SUM(jl.credit), 0) AS total_credit,
                COALESCE(SUM(jl.debit - jl.credit), 0) AS net_movement
             FROM journal_lines jl
             JOIN journals j ON j.id = jl.journal_id
             JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
             GROUP BY na.id, na.code, na.name, na.account_type, nas.code, nas.name
             ORDER BY na.sort_order, na.code',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        return array_map(static function (array $row): array {
            $row['nominal_account_id'] = (int)($row['nominal_account_id'] ?? 0);
            $row['total_debit'] = round((float)($row['total_debit'] ?? 0), 2);
            $row['total_credit'] = round((float)($row['total_credit'] ?? 0), 2);
            $row['net_movement'] = round((float)($row['net_movement'] ?? 0), 2);
            $row['balance_side'] = $row['net_movement'] >= 0 ? 'debit' : 'credit';

            return $row;
        }, $rows);
    }

    public function getTotals(int $companyId, int $accountingPeriodId): array
    {
        $rows = $this->getTrialBalance($companyId, $accountingPeriodId);
        $debit = 0.0;
        $credit = 0.0;
        foreach ($rows as $row) {
            $debit += (float)($row['total_debit'] ?? 0);
            $credit += (float)($row['total_credit'] ?? 0);
        }

        return [
            'total_debit' => round($debit, 2),
            'total_credit' => round($credit, 2),
            'difference' => round($debit - $credit, 2),
            'row_count' => count($rows),
            'is_balanced' => abs($debit - $credit) < 0.005,
        ];
    }

    public function isBalanced(int $companyId, int $accountingPeriodId): bool
    {
        return !empty($this->getTotals($companyId, $accountingPeriodId)['is_balanced']);
    }
}
