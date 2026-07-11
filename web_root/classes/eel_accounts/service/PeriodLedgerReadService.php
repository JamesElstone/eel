<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class PeriodLedgerReadService
{
    /** @var array<string, PeriodLedgerDataset> Request-local cache only. */
    private array $datasets = [];

    public function scope(int $companyId, int $accountingPeriodId, ?string $asAtDate = null, ?string $fromDate = null): PeriodLedgerScope
    {
        $period = \InterfaceDB::fetchOne(
            'SELECT period_start, period_end
             FROM accounting_periods
             WHERE id = :accounting_period_id AND company_id = :company_id
             LIMIT 1',
            ['accounting_period_id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period)) {
            throw new \InvalidArgumentException('The selected accounting period could not be found.');
        }
        $periodEnd = (string)$period['period_end'];

        $periodStart = (string)$period['period_start'];
        $effectiveStart = $fromDate !== null && trim($fromDate) !== '' ? trim($fromDate) : $periodStart;
        if ($effectiveStart < $periodStart) {
            throw new \InvalidArgumentException('The ledger start date must fall inside the accounting period.');
        }

        return new PeriodLedgerScope(
            $companyId,
            $accountingPeriodId,
            $effectiveStart,
            $periodEnd,
            $asAtDate !== null && trim($asAtDate) !== '' ? trim($asAtDate) : $periodEnd,
        );
    }

    public function fetch(PeriodLedgerScope $scope): PeriodLedgerDataset
    {
        $key = $scope->cacheKey();
        if (isset($this->datasets[$key])) {
            return $this->datasets[$key];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT na.id AS nominal_account_id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    na.account_type,
                    COALESCE(nas.code, \'\') AS account_subtype_code,
                    COALESCE(nas.name, \'\') AS account_subtype_name,
                    COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                    DATE_FORMAT(j.journal_date, \'%Y-%m-01\') AS month_start,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
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
             GROUP BY na.id, na.code, na.name, na.account_type, nas.code, nas.name, na.tax_treatment, DATE_FORMAT(j.journal_date, \'%Y-%m-01\')
             ORDER BY month_start ASC, na.code ASC, na.id ASC',
            [
                'close_journal_tag' => RetainedEarningsCloseService::JOURNAL_TAG,
                'asset_depreciation_source_type' => YearEndClosePreviewService::ASSET_DEPRECIATION_SOURCE_TYPE,
                'company_id' => $scope->companyId,
                'accounting_period_id' => $scope->accountingPeriodId,
                'period_start' => $scope->periodStart,
                'period_end' => $scope->asAtDate,
                'income_type' => 'income',
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        );
        $journalCount = (int)(\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM journals j
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND jem_close.id IS NULL',
            [
                'close_journal_tag' => RetainedEarningsCloseService::JOURNAL_TAG,
                'company_id' => $scope->companyId,
                'accounting_period_id' => $scope->accountingPeriodId,
                'period_start' => $scope->periodStart,
                'period_end' => $scope->asAtDate,
            ]
        ) ?: 0);

        return $this->datasets[$key] = new PeriodLedgerDataset($scope, $rows, $journalCount);
    }

    public function clearRuntimeCache(): void
    {
        $this->datasets = [];
    }
}
