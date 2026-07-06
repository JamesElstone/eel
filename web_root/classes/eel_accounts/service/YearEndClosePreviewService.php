<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class YearEndClosePreviewService
{
    public const ASSET_DEPRECIATION_SOURCE_TYPE = 'asset_depreciation';

    public function depreciationExpenseForPeriod(int $companyId, int $accountingPeriodId, string $periodStart = '', string $periodEnd = ''): float
    {
        $total = 0.0;
        foreach ($this->depreciationRowsForPeriod($companyId, $accountingPeriodId, $periodStart, $periodEnd) as $row) {
            $total = round($total + (float)($row['amount'] ?? 0), 2);
        }

        return round(max(0.0, $total), 2);
    }

    public function depreciationRowsForPeriod(int $companyId, int $accountingPeriodId, string $periodStart = '', string $periodEnd = ''): array
    {
        $rows = array_merge(
            $this->postedDepreciationRows($companyId, $accountingPeriodId),
            $this->pendingDepreciationRows($companyId, $accountingPeriodId)
        );

        $allocatedRows = [];
        foreach ($rows as $row) {
            $amount = round((float)($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $entryStart = (string)($row['period_start'] ?? '');
            $entryEnd = (string)($row['period_end'] ?? '');
            if ($periodStart !== '' && $periodEnd !== '') {
                $entryDays = $this->periodDays($entryStart, $entryEnd);
                $overlapDays = $this->overlapDays($entryStart, $entryEnd, $periodStart, $periodEnd);
                if ($entryDays <= 0 || $overlapDays <= 0) {
                    continue;
                }
                $amount = round($amount * ($overlapDays / $entryDays), 2);
            }

            if ($amount <= 0) {
                continue;
            }

            $row['amount'] = $amount;
            $allocatedRows[] = $row;
        }

        return $allocatedRows;
    }

    public function pendingBalanceSheetAdjustments(int $companyId, int $accountingPeriodId, string $periodEnd): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null || (string)($accountingPeriod['period_end'] ?? '') === '') {
            return [];
        }

        if ($periodEnd !== '' && $periodEnd < (string)$accountingPeriod['period_end']) {
            return [];
        }

        if ((new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId)) {
            return [];
        }

        return array_merge(
            $this->pendingDepreciationBalanceSheetAdjustments($companyId, $accountingPeriodId),
            $this->pendingDirectorLoanOffsetAdjustments($companyId, $accountingPeriodId),
            $this->pendingRetainedEarningsCloseAdjustment($companyId, $accountingPeriodId, $accountingPeriod)
        );
    }

    public function depreciationExpenseNominal(): ?array
    {
        return $this->nominalByCode('6200', 'expense');
    }

    private function postedDepreciationRows(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->tableExists('asset_depreciation_entries') || !$this->tableExists('asset_register')) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT ade.asset_id,
                    COALESCE(ar.asset_code, \'\') AS asset_code,
                    ar.accum_dep_nominal_id,
                    ade.period_start,
                    ade.period_end,
                    ade.amount,
                    0 AS is_pending
             FROM asset_depreciation_entries ade
             INNER JOIN asset_register ar ON ar.id = ade.asset_id
             WHERE ade.accounting_period_id = :accounting_period_id
               AND ar.company_id = :company_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ) ?: [];

        return array_map(static fn(array $row): array => [
            'asset_id' => (int)($row['asset_id'] ?? 0),
            'asset_code' => (string)($row['asset_code'] ?? ''),
            'accum_dep_nominal_id' => (int)($row['accum_dep_nominal_id'] ?? 0),
            'period_start' => (string)($row['period_start'] ?? ''),
            'period_end' => (string)($row['period_end'] ?? ''),
            'amount' => round((float)($row['amount'] ?? 0), 2),
            'is_pending' => false,
        ], $rows);
    }

    private function pendingDepreciationRows(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->tableExists('asset_register')) {
            return [];
        }

        $preview = (new \eel_accounts\Service\AssetService())->previewDepreciationRun($companyId, $accountingPeriodId);
        $previewRows = array_values(array_filter(
            (array)($preview['rows'] ?? []),
            static fn(mixed $row): bool => is_array($row) && (int)($row['asset_id'] ?? 0) > 0
        ));
        if (empty($preview['success']) || $previewRows === []) {
            return [];
        }

        $assetIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['asset_id'], $previewRows)));
        $assetMap = $this->assetDepreciationNominalMap($assetIds);
        $rows = [];
        foreach ($previewRows as $row) {
            $assetId = (int)($row['asset_id'] ?? 0);
            $asset = (array)($assetMap[$assetId] ?? []);
            $rows[] = [
                'asset_id' => $assetId,
                'asset_code' => (string)($row['asset_code'] ?? $asset['asset_code'] ?? ''),
                'accum_dep_nominal_id' => (int)($asset['accum_dep_nominal_id'] ?? 0),
                'period_start' => (string)($row['period_start'] ?? ''),
                'period_end' => (string)($row['period_end'] ?? ''),
                'amount' => round((float)($row['amount'] ?? 0), 2),
                'is_pending' => true,
            ];
        }

        return $rows;
    }

    private function pendingDepreciationBalanceSheetAdjustments(int $companyId, int $accountingPeriodId): array
    {
        $adjustments = [];
        foreach ($this->pendingDepreciationRows($companyId, $accountingPeriodId) as $row) {
            $nominalId = (int)($row['accum_dep_nominal_id'] ?? 0);
            $amount = round((float)($row['amount'] ?? 0), 2);
            if ($nominalId <= 0 || $amount <= 0) {
                continue;
            }

            $nominal = $this->nominalById($nominalId);
            if ($nominal === null) {
                continue;
            }

            $adjustments[] = $nominal + [
                'debit' => 0.0,
                'credit' => $amount,
                'source' => 'pending_depreciation',
            ];
        }

        return $adjustments;
    }

    private function pendingDirectorLoanOffsetAdjustments(int $companyId, int $accountingPeriodId): array
    {
        $context = (new \eel_accounts\Service\DirectorLoanReconciliationService())->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available']) || empty($context['can_post']) || empty($context['closing_balance_acknowledged'])) {
            return [];
        }

        $adjustments = [];
        foreach ((array)($context['proposed_lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }

            $nominalId = (int)($line['nominal_account_id'] ?? 0);
            $nominal = $nominalId > 0 ? $this->nominalById($nominalId) : null;
            if ($nominal === null) {
                continue;
            }

            $adjustments[] = $nominal + [
                'debit' => round((float)($line['debit'] ?? 0), 2),
                'credit' => round((float)($line['credit'] ?? 0), 2),
                'source' => 'pending_director_loan_offset',
            ];
        }

        return $adjustments;
    }

    private function pendingRetainedEarningsCloseAdjustment(int $companyId, int $accountingPeriodId, array $accountingPeriod): array
    {
        if (!$this->tableExists('journal_entry_metadata')) {
            return [];
        }

        $existingClose = (new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
            $companyId,
            $accountingPeriodId,
            \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
            \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_KEY
        );
        if (is_array($existingClose)) {
            return [];
        }

        $review = (new \eel_accounts\Service\YearEndLockService())->fetchReview($companyId, $accountingPeriodId);
        if (!is_array($review) || trim((string)($review['retained_earnings_close_acknowledged_at'] ?? '')) === '') {
            return [];
        }

        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $profitLoss = $this->profitBeforeTaxIncludingDepreciation($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $storedProfitLoss = round((float)($review['retained_earnings_close_current_profit_loss'] ?? 0), 2);
        $storedMovement = round((float)($review['retained_earnings_close_amount'] ?? 0), 2);
        if (abs($storedProfitLoss - $profitLoss) >= 0.005 || abs($storedMovement - $profitLoss) >= 0.005) {
            return [];
        }

        $nominal = $this->nominalByCode(\eel_accounts\Service\RetainedEarningsCloseService::RETAINED_EARNINGS_CODE, 'equity');
        if ($nominal === null || abs($profitLoss) < 0.005) {
            return [];
        }

        return [[
            ...$nominal,
            'debit' => $profitLoss < 0 ? abs($profitLoss) : 0.0,
            'credit' => $profitLoss > 0 ? $profitLoss : 0.0,
            'source' => 'pending_retained_earnings_close',
        ]];
    }

    private function profitBeforeTaxIncludingDepreciation(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): float
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT na.account_type,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND COALESCE(j.source_type, \'\') <> :asset_depreciation_source_type
               AND jem_close.id IS NULL
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             GROUP BY na.account_type',
            [
                'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                'asset_depreciation_source_type' => self::ASSET_DEPRECIATION_SOURCE_TYPE,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'income_type' => 'income',
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        ) ?: [];

        $income = 0.0;
        $expenses = 0.0;
        foreach ($rows as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);
            if ($accountType === 'income') {
                $income += round($credit - $debit, 2);
            } else {
                $expenses += round($debit - $credit, 2);
            }
        }

        $expenses = round($expenses + $this->depreciationExpenseForPeriod($companyId, $accountingPeriodId, $periodStart, $periodEnd), 2);

        return round($income - $expenses, 2);
    }

    private function assetDepreciationNominalMap(array $assetIds): array
    {
        $assetIds = array_values(array_filter(array_unique(array_map('intval', $assetIds)), static fn(int $id): bool => $id > 0));
        if ($assetIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($assetIds), '?'));
        $rows = \InterfaceDB::prepareExecute(
            'SELECT id, asset_code, accum_dep_nominal_id
             FROM asset_register
             WHERE id IN (' . $placeholders . ')',
            $assetIds
        )->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int)($row['id'] ?? 0)] = $row;
        }

        return $map;
    }

    private function nominalById(int $nominalId): ?array
    {
        if ($nominalId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT na.id AS nominal_account_id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    COALESCE(na.account_type, \'\') AS account_type,
                    COALESCE(nas.code, \'\') AS subtype_code
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id
             LIMIT 1',
            ['id' => $nominalId]
        );

        return is_array($row) ? $row : null;
    }

    private function nominalByCode(string $code, string $accountType = ''): ?array
    {
        $params = ['code' => $code];
        $accountTypeSql = '';
        if ($accountType !== '') {
            $accountTypeSql = ' AND na.account_type = :account_type';
            $params['account_type'] = $accountType;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT na.id AS nominal_account_id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    COALESCE(na.account_type, \'\') AS account_type,
                    COALESCE(nas.code, \'\') AS subtype_code
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.code = :code
               ' . $accountTypeSql . '
               AND COALESCE(na.is_active, 0) = 1
             ORDER BY na.id
             LIMIT 1',
            $params
        );

        return is_array($row) ? $row : null;
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, period_start, period_end
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function periodDays(string $start, string $end): int
    {
        if ($start === '' || $end === '' || $end < $start) {
            return 0;
        }

        return (int)(new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1;
    }

    private function overlapDays(string $entryStart, string $entryEnd, string $periodStart, string $periodEnd): int
    {
        if ($entryStart === '' || $entryEnd === '' || $periodStart === '' || $periodEnd === '') {
            return 0;
        }

        $start = max($entryStart, $periodStart);
        $end = min($entryEnd, $periodEnd);

        return $end < $start ? 0 : $this->periodDays($start, $end);
    }

    private function tableExists(string $table): bool
    {
        try {
            return \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
