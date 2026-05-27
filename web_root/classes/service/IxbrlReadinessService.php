<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class IxbrlReadinessService
{
    public function getReadiness(int $companyId, int $accountingPeriodId): array
    {
        (new IxbrlFactBuilderService())->ensureSchema();
        $company = $this->fetchCompany($companyId);
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $checks = [];

        $this->addCheck($checks, 'period_selected', 'Period selected', $company !== null && $accountingPeriod !== null, true, $accountingPeriod === null ? 'Select a company and accounting period.' : 'Accounting period is available.');
        $journalCount = $companyId > 0 && $accountingPeriodId > 0 ? InterfaceDB::countWhere('journals', ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]) : 0;
        $this->addCheck($checks, 'journals_exist', 'At least one journal exists', $journalCount > 0, true, $journalCount . ' journals found.');
        $unbalancedJournals = $this->unbalancedJournalCount($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'journal_lines_balance', 'Journal lines balance', $unbalancedJournals === 0, true, $unbalancedJournals === 0 ? 'No unbalanced posted journals detected.' : $unbalancedJournals . ' unbalanced posted journals detected.');

        $totals = (new IxbrlTrialBalanceService())->getTotals($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'trial_balance_balanced', 'Trial balance balanced', !empty($totals['is_balanced']), true, 'Difference: ' . FormattingFramework::money($totals['difference'] ?? 0));

        $uncategorised = $this->uncategorisedTransactionCount($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'uncategorised_clear', 'Uncategorised transactions clear', $uncategorised === 0, false, $uncategorised . ' uncategorised transactions found.');

        $unposted = $this->unpostedJournalCount($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'journals_posted', 'Journals posted', $unposted === 0, true, $unposted === 0 ? 'All journals are posted.' : $unposted . ' unposted journals found.');

        $settings = $companyId > 0 ? (new CompanySettingsStore($companyId))->all() : [];
        $missingSettings = $this->missingSettings($settings);
        $this->addCheck($checks, 'required_settings', 'Required company settings present', $missingSettings === [], false, $missingSettings === [] ? 'Core company settings are present.' : 'Missing: ' . implode(', ', $missingSettings));

        $latestRun = (new IxbrlFactBuilderService())->getLatestRun($companyId, $accountingPeriodId);
        $factCount = (int)($latestRun['fact_count'] ?? 0);
        $this->addCheck($checks, 'facts_generated', 'Facts generated', $factCount > 0, false, $factCount > 0 ? $factCount . ' generated facts available.' : 'Build facts before generating XHTML.');

        $generated = is_array($latestRun) && (string)($latestRun['status'] ?? '') === 'generated' && (string)($latestRun['generated_path'] ?? '') !== '';
        $this->addCheck($checks, 'ixbrl_generated', 'iXBRL generated', $generated, false, $generated ? 'Generated preview file exists.' : 'No generated preview yet.');

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
        $row = InterfaceDB::fetchOne('SELECT * FROM companies WHERE id = :id LIMIT 1', ['id' => $companyId]);

        return is_array($row) ? $row : null;
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = InterfaceDB::fetchOne(
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

        return (int)InterfaceDB::fetchColumn(
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
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !InterfaceDB::columnExists('journals', 'is_posted')) {
            return 0;
        }

        return InterfaceDB::countWhere('journals', ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'is_posted' => 0]);
    }

    private function uncategorisedTransactionCount(int $companyId, int $accountingPeriodId): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !InterfaceDB::tableExists('transactions')) {
            return 0;
        }

        return InterfaceDB::countWhere('transactions', ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'category_status' => 'uncategorised']);
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
        $row = InterfaceDB::fetchOne(
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
}
