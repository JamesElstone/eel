<?php
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Reads posted P&L movements at their actual journal date for Corporation Tax
 * treatment. The ordinary PeriodLedgerReadService remains monthly because its
 * rows also drive P&L display and trend cards.
 */
final class DatedTaxTreatmentLedgerService
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $rows = [];

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(PeriodLedgerScope $scope): array
    {
        $key = $scope->cacheKey();
        if (isset($this->rows[$key])) {
            return $this->rows[$key];
        }

        $requestRows = \eel_accounts\Support\RequestCache::remember(
            'dated-tax-treatment-ledger.rows',
            $key,
            fn(): array => $this->fetchUncached($scope)
        );

        return $this->rows[$key] = (array)$requestRows;
    }

    /** @return list<array<string, mixed>> */
    private function fetchUncached(PeriodLedgerScope $scope): array
    {

        $monthExpression = \InterfaceDB::driverName() === 'sqlite'
            ? "strftime('%Y-%m-01', j.journal_date)"
            : "DATE_FORMAT(j.journal_date, '%Y-%m-01')";
        $assetDisposalSourceExpression = "CASE
            WHEN COALESCE(j.source_type, '') = 'asset_disposal' THEN 'asset_disposal'
            ELSE ''
        END";

        $correctionJoins = '';
        $correctionWhere = '';
        if (\InterfaceDB::tableExists('journal_reversals')) {
            $correctionJoins = '
             LEFT JOIN journal_reversals jr_source ON jr_source.source_journal_id = j.id
             LEFT JOIN journal_reversals jr_reversal ON jr_reversal.reversal_journal_id = j.id';
            $correctionWhere = '
               AND jr_source.source_journal_id IS NULL
               AND jr_reversal.reversal_journal_id IS NULL';
        }

        return \InterfaceDB::fetchAll(
            'SELECT j.id AS journal_id,
                    jl.id AS journal_line_id,
                    COALESCE(j.source_type, \'\') AS source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    COALESCE(j.description, \'\') AS journal_description,
                    COALESCE(jl.line_description, \'\') AS line_description,
                    na.id AS nominal_account_id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    na.account_type,
                    COALESCE(nas.code, \'\') AS account_subtype_code,
                    COALESCE(nas.name, \'\') AS account_subtype_name,
                    COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                    ' . $assetDisposalSourceExpression . ' AS journal_source_type,
                    j.journal_date,
                    ' . $monthExpression . ' AS month_start,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             LEFT JOIN journal_entry_metadata jem_close
              ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             ' . $correctionJoins . '
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND COALESCE(j.source_type, \'\') <> :asset_depreciation_source_type
               AND jem_close.id IS NULL
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             ' . $correctionWhere . '
             GROUP BY j.id,
                      jl.id,
                      j.source_type,
                      j.source_ref,
                      j.description,
                      jl.line_description,
                      na.id,
                      na.code,
                      na.name,
                      na.account_type,
                      nas.code,
                      nas.name,
                      na.tax_treatment,
                      ' . $assetDisposalSourceExpression . ',
                      j.journal_date,
                      ' . $monthExpression . '
             ORDER BY j.journal_date ASC, j.id ASC, jl.id ASC',
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
        ) ?: [];
    }

    public function clearRuntimeCache(): void
    {
        $this->rows = [];
    }
}
