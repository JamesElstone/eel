<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class IxbrlReadinessService
{
    public function getReadiness(int $companyId, int $accountingPeriodId): array
    {
        (new \eel_accounts\Service\IxbrlFactBuilderService())->ensureSchema();
        $company = $this->fetchCompany($companyId);
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $checks = [];

        $this->addCheck($checks, 'period_selected', 'Period selected', $company !== null && $accountingPeriod !== null, true, $accountingPeriod === null ? 'Select a company and accounting period.' : 'Accounting period is available.');
        $journalCount = $companyId > 0 && $accountingPeriodId > 0 ? \InterfaceDB::countWhere('journals', ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]) : 0;
        $this->addCheck($checks, 'journals_exist', 'At least one journal exists', $journalCount > 0, true, $journalCount . ' journals found.');
        $unbalancedJournals = $this->unbalancedJournalCount($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'journal_lines_balance', 'Journal lines balance', $unbalancedJournals === 0, true, $unbalancedJournals === 0 ? 'No unbalanced posted journals detected.' : $unbalancedJournals . ' unbalanced posted journals detected.');

        $totals = (new \eel_accounts\Service\IxbrlTrialBalanceService())->getTotals($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'trial_balance_balanced', 'Trial balance balanced', !empty($totals['is_balanced']), true, 'Difference: ' . \FormattingFramework::money($totals['difference'] ?? 0));

        $balanceMetrics = (new \eel_accounts\Service\IxbrlBalanceSheetMetricsService())->fetchClosingMetrics($companyId, $accountingPeriodId);
        $balanceDifference = (float)($balanceMetrics['balance_equation_difference'] ?? 0);
        $this->addCheck(
            $checks,
            'closing_balance_sheet_balanced',
            'Closing balance sheet balances',
            !empty($balanceMetrics['is_balance_sheet_balanced']),
            true,
            'Net assets less capital and reserves difference: ' . \FormattingFramework::money($balanceDifference)
        );

        $uncategorised = $this->uncategorisedTransactionCount($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'uncategorised_clear', 'Uncategorised transactions clear', $uncategorised === 0, false, $uncategorised . ' uncategorised transactions found.');

        $unposted = $this->unpostedJournalCount($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'journals_posted', 'Journals posted', $unposted === 0, true, $unposted === 0 ? 'All journals are posted.' : $unposted . ' unposted journals found.');

        $settings = $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : [];
        $missingSettings = $this->missingSettings($settings);
        $this->addCheck($checks, 'required_settings', 'Required company settings present', $missingSettings === [], false, $missingSettings === [] ? 'Core company settings are present.' : 'Missing: ' . implode(', ', $missingSettings));

        $deferredTaxExposure = (new \eel_accounts\Service\Frs105ValidationService())->deferredTaxNominalExposure($companyId, $accountingPeriodId);
        $this->addCheck(
            $checks,
            'frs105_deferred_tax_nominal',
            'FRS 105 deferred tax recognition',
            empty($deferredTaxExposure['exists']),
            false,
            (string)($deferredTaxExposure['detail'] ?? '')
        );

        $companiesHouseComparison = (new \eel_accounts\Service\YearEndCompaniesHouseComparisonService())->fetchComparison($companyId, $accountingPeriodId);
        $comparisonFailures = $this->companiesHouseComparisonFailures($companiesHouseComparison);
        $comparisonWarnings = $this->companiesHouseComparisonWarnings($companiesHouseComparison);
        $comparisonScope = (string)($companiesHouseComparison['comparison_scope'] ?? '');
        $comparisonBlocks = !empty($companiesHouseComparison['available'])
            && $comparisonScope === 'exact_match'
            && $comparisonFailures > 0;
        $comparisonComplete = !empty($companiesHouseComparison['available'])
            && $comparisonScope === 'exact_match'
            && $comparisonFailures === 0
            && $comparisonWarnings === 0;
        $comparisonDetail = empty($companiesHouseComparison['available'])
            ? (string)($companiesHouseComparison['errors'][0] ?? 'No stored Companies House filing is available for comparison.')
            : ($comparisonFailures > 0
                ? $comparisonFailures . ' material Companies House comparison mismatch(es) found for ' . $comparisonScope . '.'
                : ($comparisonScope === 'nearest_match'
                    ? 'Advisory nearest-period comparison only; no exact Companies House filing matched this period.'
                    : ($comparisonWarnings > 0 ? $comparisonWarnings . ' small Companies House comparison variance warning(s) found.' : 'Companies House comparison did not find material exact-period mismatches.')));
        $this->addCheck(
            $checks,
            'companies_house_exact_period_comparison',
            'Companies House comparison',
            $comparisonComplete,
            $comparisonBlocks,
            $comparisonDetail
        );

        $latestRun = (new \eel_accounts\Service\IxbrlFactBuilderService())->getLatestRun($companyId, $accountingPeriodId);
        $factCount = (int)($latestRun['fact_count'] ?? 0);
        $this->addCheck($checks, 'facts_generated', 'Facts generated', $factCount > 0, false, $factCount > 0 ? $factCount . ' generated facts available.' : 'Build facts before generating XHTML.');

        $generated = is_array($latestRun) && (string)($latestRun['status'] ?? '') === 'generated' && (string)($latestRun['generated_path'] ?? '') !== '';
        $validationPassed = $generated && (string)($latestRun['validation_status'] ?? '') === 'passed';
        $this->addCheck($checks, 'ixbrl_generated', 'iXBRL export generated', $generated, false, $generated ? 'Generated filing export file exists.' : 'No generated export yet.');
        $this->addCheck($checks, 'ixbrl_validation_passed', 'iXBRL structural validation passed', $validationPassed, false, $validationPassed ? 'Latest generated export passed internal structural validation.' : 'No internally validated export yet.');

        $externalValidation = (new \eel_accounts\Service\IxbrlExternalValidationService())->externalStatusForRun($latestRun);
        $this->addCheck(
            $checks,
            'ixbrl_external_validation',
            'Arelle external validation',
            (string)($externalValidation['status'] ?? '') === 'passed',
            !empty($externalValidation['blocking']),
            (string)($externalValidation['detail'] ?? 'Arelle external validation has not been run.')
        );

        $blocking = array_values(array_filter($checks, static fn(array $check): bool => !empty($check['blocking']) && empty($check['complete'])));
        $warnings = array_values(array_filter($checks, static fn(array $check): bool => empty($check['blocking']) && empty($check['complete'])));

        return [
            'company' => $company,
            'accounting_period' => $accountingPeriod,
            'checks' => $checks,
            'blocking_errors' => array_map(static fn(array $check): string => (string)$check['detail'], $blocking),
            'warnings' => array_map(static fn(array $check): string => (string)$check['detail'], $warnings),
            'can_build_facts' => $blocking === [],
            'can_generate' => $blocking === [] && $factCount > 0,
            'latest_run' => $latestRun,
            'closing_balance_metrics' => $balanceMetrics,
            'companies_house_comparison' => $companiesHouseComparison,
            'external_validation' => $externalValidation,
        ];
    }

    private function addCheck(array &$checks, string $key, string $label, bool $complete, bool $blocking, string $detail): void
    {
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'complete' => $complete,
            'blocking' => $blocking,
            'status' => $complete ? 'success' : ($blocking ? 'danger' : 'warning'),
            'detail' => $detail,
        ];
    }

    private function fetchCompany(int $companyId): ?array
    {
        $row = \InterfaceDB::fetchOne('SELECT * FROM companies WHERE id = :id LIMIT 1', ['id' => $companyId]);

        return is_array($row) ? $row : null;
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function unbalancedJournalCount(int $companyId, int $accountingPeriodId): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM (
                SELECT j.id
                FROM journals j
                INNER JOIN journal_lines jl ON jl.journal_id = j.id
                WHERE j.company_id = :company_id
                  AND j.accounting_period_id = :accounting_period_id
                  AND j.is_posted = 1
                GROUP BY j.id
                HAVING ABS(COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0)) >= 0.005
             ) x',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
    }

    private function unpostedJournalCount(int $companyId, int $accountingPeriodId): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::columnExists('journals', 'is_posted')) {
            return 0;
        }

        return \InterfaceDB::countWhere('journals', ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'is_posted' => 0]);
    }

    private function uncategorisedTransactionCount(int $companyId, int $accountingPeriodId): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::tableExists('transactions')) {
            return 0;
        }

        return \InterfaceDB::countWhere('transactions', ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'category_status' => 'uncategorised']);
    }

    private function missingSettings(array $settings): array
    {
        $labels = [
            'utr' => 'UTR',
            'default_currency' => 'default currency',
            'default_bank_nominal_id' => 'bank nominal',
            'director_loan_nominal_id' => 'director loan nominal',
            'vat_nominal_id' => 'VAT nominal',
        ];
        $missing = [];
        foreach ($labels as $key => $label) {
            if (trim((string)($settings[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        if (!$this->corporationTaxNominalExists()) {
            $missing[] = 'corporation tax nominal';
        }

        return $missing;
    }

    private function corporationTaxNominalExists(): bool
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT na.id
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE nas.code = :subtype
                OR LOWER(na.name) LIKE :name
             LIMIT 1',
            ['subtype' => 'corp_tax', 'name' => '%corporation tax%']
        );

        return is_array($row);
    }

    private function companiesHouseComparisonFailures(array $comparison): int
    {
        if (empty($comparison['available'])) {
            return 0;
        }

        $count = 0;
        foreach ((array)($comparison['rows'] ?? []) as $row) {
            if ((string)($row['status'] ?? '') === 'fail') {
                $count++;
            }
        }

        return $count;
    }

    private function companiesHouseComparisonWarnings(array $comparison): int
    {
        if (empty($comparison['available'])) {
            return 0;
        }

        $count = 0;
        foreach ((array)($comparison['rows'] ?? []) as $row) {
            if ((string)($row['status'] ?? '') === 'warning') {
                $count++;
            }
        }

        return $count;
    }
}
