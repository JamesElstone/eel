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
        $company = $this->fetchCompany($companyId);
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $settings = $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : [];
        $settingsService = new \eel_accounts\Service\CompanySettingsService();
        $checks = [];

        $validSelection = $company !== null && $accountingPeriod !== null;
        $this->addCheck(
            $checks,
            'period_selected',
            'Company and period selected',
            $validSelection,
            ['build', 'generate', 'filing'],
            $validSelection
                ? 'The selected accounting period belongs to this company.'
                : 'Select a valid company and accounting period.'
        );

        $identityErrors = $company !== null
            ? (new IxbrlCompanyIdentityService())->errors($company)
            : ['Select a company before checking its Companies House identity.'];
        $this->addCheck(
            $checks,
            'supported_company_identity',
            'Supported Companies House identity',
            $validSelection && $identityErrors === [],
            ['build'],
            $identityErrors === []
                ? 'Active England and Wales private limited company identity and registered office are complete.'
                : implode(' ', $identityErrors)
        );

        $presentationCurrency = strtoupper(trim((string)($settings['default_currency'] ?? '')));
        $this->addCheck(
            $checks,
            'presentation_currency_gbp',
            'GBP presentation currency',
            $validSelection && $presentationCurrency === 'GBP',
            ['build'],
            $presentationCurrency === 'GBP'
                ? 'The filing profile presents figures in pounds sterling (GBP).'
                : 'The current iXBRL filing profile supports default currency GBP only.'
        );

        $journalCount = $validSelection
            ? \InterfaceDB::countWhere('journals', ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId])
            : 0;
        $this->addCheck(
            $checks,
            'journals_exist',
            'At least one journal exists',
            $validSelection && $journalCount > 0,
            ['build'],
            $validSelection ? $journalCount . ' journals found.' : 'A valid period is required before journals can be checked.'
        );

        $unbalancedJournals = $this->unbalancedJournalCount($companyId, $accountingPeriodId);
        $this->addCheck(
            $checks,
            'journal_lines_balance',
            'Journal lines balance',
            $validSelection && $unbalancedJournals === 0,
            ['build'],
            !$validSelection
                ? 'A valid period is required before journal lines can be checked.'
                : ($unbalancedJournals === 0 ? 'No unbalanced posted journals detected.' : $unbalancedJournals . ' unbalanced posted journals detected.')
        );

        $totals = $validSelection
            ? (new \eel_accounts\Service\IxbrlTrialBalanceService())->getTotals($companyId, $accountingPeriodId)
            : ['is_balanced' => false, 'difference' => 0];
        $this->addCheck(
            $checks,
            'trial_balance_balanced',
            'Trial balance balanced',
            $validSelection && !empty($totals['is_balanced']),
            ['build'],
            $validSelection
                ? 'Difference: ' . $settingsService->money($settings, $totals['difference'] ?? 0)
                : 'A valid period is required before the trial balance can be checked.'
        );

        $balanceMetrics = $validSelection
            ? (new \eel_accounts\Service\IxbrlBalanceSheetMetricsService())
                ->fetchClosingMetrics($companyId, $accountingPeriodId, false, false)
            : [];
        $balanceDifference = (float)($balanceMetrics['balance_equation_difference'] ?? 0);
        $this->addCheck(
            $checks,
            'closing_balance_sheet_balanced',
            'Closing balance sheet balances',
            $validSelection && !empty($balanceMetrics['is_balance_sheet_balanced']),
            ['build'],
            $validSelection
                ? 'Net assets less capital and reserves difference: ' . $settingsService->money($settings, $balanceDifference)
                : 'A valid period is required before closing balances can be checked.'
        );

        $closingReliable = $validSelection && !empty($balanceMetrics['reliable_closing_balance']);
        $reliabilityDetail = $closingReliable
            ? 'Closing balances are based on a reliable locked-period chain.'
            : (string)(
                ($balanceMetrics['warnings'] ?? [])[0]
                ?? 'The closing balances are provisional because an earlier accounting period is not locked.'
            );
        $this->addCheck(
            $checks,
            'closing_balance_reliable',
            'Closing balances are final',
            $closingReliable,
            ['generate', 'filing'],
            $reliabilityDetail
        );

        $uncategorised = $this->uncategorisedTransactionCount($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'uncategorised_clear', 'Uncategorised transactions clear', $validSelection && $uncategorised === 0, ['build'], $uncategorised . ' uncategorised transactions found.');

        $unposted = $this->unpostedJournalCount($companyId, $accountingPeriodId);
        $this->addCheck($checks, 'journals_posted', 'Journals posted', $validSelection && $unposted === 0, ['build'], $unposted === 0 ? 'All journals are posted.' : $unposted . ' unposted journals found.');

        $missingSettings = $this->missingSettings($settings);
        $this->addCheck($checks, 'required_settings', 'Required company settings present', $validSelection && $missingSettings === [], ['build'], $missingSettings === [] ? 'Core company settings are present.' : 'Missing: ' . implode(', ', $missingSettings));

        $deferredTaxExposure = $validSelection
            ? (new \eel_accounts\Service\Frs105ValidationService())->deferredTaxNominalExposure($companyId, $accountingPeriodId)
            : ['exists' => false, 'detail' => 'A valid period is required before FRS 105 nominal exposure can be checked.'];
        $this->addCheck(
            $checks,
            'frs105_deferred_tax_nominal',
            'FRS 105 deferred tax recognition',
            empty($deferredTaxExposure['exists']),
            [],
            (string)($deferredTaxExposure['detail'] ?? '')
        );

        $disclosures = (new IxbrlAccountsDisclosureService())->fetch($companyId, $accountingPeriodId);
        $disclosuresComplete = $validSelection && !empty($disclosures['complete']);
        $disclosureDetail = $disclosuresComplete
            ? 'All period-specific filing disclosures have been explicitly confirmed.'
            : ((array)($disclosures['errors'] ?? []) !== []
                ? (string)((array)$disclosures['errors'])[0]
                : ((array)($disclosures['profile_errors'] ?? []) !== []
                    ? (string)((array)$disclosures['profile_errors'])[0]
                    : 'Complete: ' . implode(', ', (array)($disclosures['missing_labels'] ?? [])) . '.'));
        $this->addCheck(
            $checks,
            'accounts_disclosures_complete',
            'Accounts disclosures confirmed',
            $disclosuresComplete,
            ['build'],
            $disclosureDetail
        );

        $microEligibility = null;
        $microEligibilityError = '';
        $disclosureRow = (array)($disclosures['disclosures'] ?? []);
        if ($validSelection && is_numeric($disclosureRow['average_number_employees'] ?? null)) {
            try {
                $mapping = (new IxbrlAccountsMappingService())->getAccountsMapping($companyId, $accountingPeriodId);
                $buckets = (array)($mapping['buckets'] ?? []);
                $microEligibility = (new IxbrlMicroEntityEligibilityService())->evaluate(
                    (string)$accountingPeriod['period_start'],
                    (string)$accountingPeriod['period_end'],
                    (float)($buckets['turnover'] ?? 0),
                    (float)($buckets['fixed_assets'] ?? 0)
                        + (float)($buckets['current_assets'] ?? 0)
                        + (float)($buckets['prepayments_accrued_income'] ?? 0),
                    (int)$disclosureRow['average_number_employees']
                );
            } catch (\Throwable $exception) {
                $microEligibilityError = $exception->getMessage();
            }
        }
        $microEligible = is_array($microEligibility) && !empty($microEligibility['qualifies']);
        $this->addCheck(
            $checks,
            'micro_entity_size_thresholds',
            'Micro-entity size thresholds',
            $microEligible,
            ['build'],
            is_array($microEligibility)
                ? (new IxbrlMicroEntityEligibilityService())->detail($microEligibility)
                : ($microEligibilityError !== ''
                    ? $microEligibilityError
                    : 'Confirm the average number of employees before checking the three required FRS 105 micro-entity thresholds.')
        );

        $yearEndLocked = $validSelection
            && (new YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        $this->addCheck(
            $checks,
            'year_end_locked',
            'Year End finalised',
            $yearEndLocked,
            ['build', 'generate', 'filing'],
            $yearEndLocked
                ? 'Year End is locked; its ledger and Corporation Tax calculation evidence are authoritative.'
                : 'Complete and lock Year End before approving the filing basis.'
        );

        $filingApproval = $validSelection
            ? (new IxbrlAccountsFilingApprovalService())->status($companyId, $accountingPeriodId)
            : ['state' => 'absent', 'errors' => ['Select a company and accounting period.']];
        $approvalCurrent = (string)($filingApproval['state'] ?? '') === 'current';
        $this->addCheck(
            $checks,
            'filing_basis_approved',
            'Complete filing basis approved',
            $approvalCurrent,
            ['build', 'generate', 'filing'],
            $approvalCurrent
                ? 'The current disclosures, report mapping and CT calculation seals have an immutable approval.'
                : (string)(($filingApproval['errors'] ?? [])[0]
                    ?? 'Approve disclosures and build filing facts from the Accounts Disclosures panel.')
        );

        $latestRun = $validSelection && \InterfaceDB::tableExists('ixbrl_generation_runs')
            ? (new \eel_accounts\Service\IxbrlFactBuilderService())->getLatestRun($companyId, $accountingPeriodId)
            : null;
        $arelleStatus = (new IxbrlExternalValidationService())->configurationStatus();
        $this->addCheck(
            $checks,
            'arelle_installed',
            'Arelle installed',
            !empty($arelleStatus['installed']),
            [],
            (string)($arelleStatus['detail'] ?? 'Arelle installation could not be checked.')
        );
        $factCount = (int)($latestRun['fact_count'] ?? 0);
        $runFreshness = (array)($latestRun['run_freshness'] ?? []);
        $factsCurrent = $factCount > 0 && (string)($runFreshness['state'] ?? '') === 'current';
        $factsDetail = $factCount <= 0
            ? 'Build facts before generating XHTML.'
            : ($factsCurrent
                ? $factCount . ' current generated facts available.'
                : (string)($runFreshness['detail'] ?? 'The generated facts are not current and must be rebuilt.'));
        $this->addCheck($checks, 'facts_generated', 'Facts generated and current', $factsCurrent, ['generate', 'filing'], $factsDetail);

        $comparativeFactsRequired = $validSelection
            && $this->comparativeFactsRequired($companyId, $accountingPeriodId);
        $missingProfileFacts = $this->missingRequiredProfileFacts(
            is_array($latestRun) ? (int)($latestRun['id'] ?? 0) : 0,
            $comparativeFactsRequired
        );
        $this->addCheck(
            $checks,
            'required_profile_facts',
            'Required FRS 105 profile facts present',
            $factsCurrent && $missingProfileFacts === [],
            ['generate', 'filing'],
            !$factsCurrent
                ? 'The generated facts are not current and must be rebuilt before their required keys can be checked.'
                : ($missingProfileFacts === []
                    ? 'The current snapshot contains every required identity and statutory profile fact.'
                    : 'Missing required fact keys: ' . implode(', ', $missingProfileFacts) . '.')
        );

        $generated = $factsCurrent
            && is_array($latestRun)
            && (string)($latestRun['status'] ?? '') === 'generated'
            && (string)($latestRun['generated_path'] ?? '') !== '';
        $generatedPath = is_array($latestRun) ? trim((string)($latestRun['generated_path'] ?? '')) : '';
        $fileExists = $generatedPath !== '' && is_file($generatedPath);
        $validationPassed = $generated && (string)($latestRun['validation_status'] ?? '') === 'passed';
        $this->addCheck($checks, 'ixbrl_generated', 'iXBRL export generated', $generated && $fileExists, ['filing'], $generated && $fileExists ? 'Generated filing export file exists.' : 'No current generated export file exists.');
        $this->addCheck($checks, 'ixbrl_validation_passed', 'iXBRL structural validation passed', $validationPassed, ['filing'], $validationPassed ? 'Latest generated export passed internal structural validation.' : 'No current internally validated export exists.');

        $externalValidation = (new \eel_accounts\Service\IxbrlExternalValidationService())->externalStatusForRun($latestRun);
        $this->addCheck(
            $checks,
            'ixbrl_external_validation',
            'Arelle external validation',
            (string)($externalValidation['status'] ?? '') === 'passed',
            ['filing'],
            (string)($externalValidation['detail'] ?? 'Arelle external validation has not been run.')
        );

        $outputHash = is_array($latestRun) ? trim((string)($latestRun['output_sha256'] ?? '')) : '';
        $validatedHash = is_array($latestRun) ? trim((string)($latestRun['external_validated_sha256'] ?? '')) : '';
        $diskHash = $fileExists ? (string)(hash_file('sha256', $generatedPath) ?: '') : '';
        $artifactCurrent = $fileExists
            && $outputHash !== ''
            && $validatedHash !== ''
            && $diskHash !== ''
            && hash_equals($outputHash, $validatedHash)
            && hash_equals($outputHash, $diskHash);
        $this->addCheck(
            $checks,
            'ixbrl_validated_artifact_current',
            'Validated artifact hash matches',
            $artifactCurrent,
            ['filing'],
            $artifactCurrent
                ? 'The current file matches the generated and Arelle-validated SHA-256 values.'
                : 'Generate and externally validate the current file; all three SHA-256 values must match.'
        );

        $buildBlocking = $this->incompleteForStage($checks, 'build');
        $generationBlocking = array_values(array_filter(
            $checks,
            static fn(array $check): bool => empty($check['complete'])
                && array_intersect(['build', 'generate'], (array)($check['blocking_stages'] ?? [])) !== []
        ));
        $filingBlocking = array_values(array_filter(
            $checks,
            static fn(array $check): bool => empty($check['complete'])
                && (array)($check['blocking_stages'] ?? []) !== []
        ));
        $warnings = array_values(array_filter(
            $checks,
            static fn(array $check): bool => empty($check['complete'])
                && (array)($check['blocking_stages'] ?? []) === []
        ));
        $canBuild = $buildBlocking === [];
        $canGenerate = $generationBlocking === [];
        $canValidate = $validSelection && $generated && $fileExists;
        $readyForFiling = $filingBlocking === [];

        return [
            'company' => $company,
            'accounting_period' => $accountingPeriod,
            'checks' => $checks,
            'blocking_errors' => array_map(static fn(array $check): string => (string)$check['detail'], $buildBlocking),
            'generation_errors' => array_map(static fn(array $check): string => (string)$check['detail'], $generationBlocking),
            'filing_errors' => array_map(static fn(array $check): string => (string)$check['detail'], $filingBlocking),
            'warnings' => array_map(static fn(array $check): string => (string)$check['detail'], $warnings),
            'can_build_facts' => $canBuild,
            'can_generate' => $canGenerate,
            'can_validate' => $canValidate,
            'ready_for_filing' => $readyForFiling,
            'capabilities' => [
                'can_build_facts' => $canBuild,
                'can_generate' => $canGenerate,
                'can_validate' => $canValidate,
                'ready_for_filing' => $readyForFiling,
            ],
            'facts_current' => $factsCurrent,
            'year_end_locked' => $yearEndLocked,
            'filing_approval' => $filingApproval,
            'closing_balance_reliable' => $closingReliable,
            'run_freshness' => $runFreshness,
            'latest_run' => $latestRun,
            'closing_balance_metrics' => $balanceMetrics,
            'disclosures' => $disclosures,
            'external_validation' => $externalValidation,
            'arelle_status' => $arelleStatus,
        ];
    }

    private function addCheck(
        array &$checks,
        string $key,
        string $label,
        bool $complete,
        array $blockingStages,
        string $detail
    ): void
    {
        $blockingStages = array_values(array_intersect(['build', 'generate', 'filing'], $blockingStages));
        $statusLabel = 'Warning';
        if ($complete) {
            $statusLabel = 'Ready';
        } elseif (in_array('build', $blockingStages, true)) {
            $statusLabel = 'Build blocked';
        } elseif (in_array('generate', $blockingStages, true)) {
            $statusLabel = 'Generation blocked';
        } elseif (in_array('filing', $blockingStages, true)) {
            $statusLabel = 'Filing blocked';
        }

        $checks[] = [
            'key' => $key,
            'label' => $label,
            'complete' => $complete,
            'blocking' => $blockingStages !== [],
            'blocking_stages' => $blockingStages,
            'status' => $complete ? 'success' : ($blockingStages !== [] ? 'danger' : 'warning'),
            'status_label' => $statusLabel,
            'detail' => $detail,
        ];
    }

    private function incompleteForStage(array $checks, string $stage): array
    {
        return array_values(array_filter(
            $checks,
            static fn(array $check): bool => empty($check['complete'])
                && in_array($stage, (array)($check['blocking_stages'] ?? []), true)
        ));
    }

    private function requiredProfileFactKeys(): array
    {
        $required = [];
        foreach ((new IxbrlTaxonomyProfileService())->mappings() as $mapping) {
            if (empty($mapping['is_active']) || empty($mapping['is_required'])) {
                continue;
            }
            $factKey = trim((string)($mapping['fact_key'] ?? ''));
            if ($factKey !== '') {
                $required[$factKey] = true;
            }
        }

        return array_keys($required);
    }

    private function missingRequiredProfileFacts(int $runId, bool $comparativeRequired = false): array
    {
        $required = $this->requiredProfileFactKeys();
        $comparativeRequiredKeys = [];
        if ($comparativeRequired) {
            foreach ((new IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                if (!empty($mapping['is_active'])
                    && !empty($mapping['is_required'])
                    && !empty($mapping['comparative_enabled'])) {
                    $factKey = trim((string)($mapping['fact_key'] ?? ''));
                    if ($factKey !== '') {
                        $comparativeRequiredKeys[] = $factKey;
                    }
                }
            }
        }
        if ($runId <= 0 || !\InterfaceDB::tableExists('ixbrl_generation_facts')) {
            return array_merge(
                $required,
                array_map(static fn(string $factKey): string => 'comparative:' . $factKey, $comparativeRequiredKeys)
            );
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT fact_key, context_ref
             FROM ixbrl_generation_facts
             WHERE run_id = :run_id',
            ['run_id' => $runId]
        );
        $currentPresent = [];
        $comparativePresent = [];
        foreach ($rows as $row) {
            $factKey = trim((string)($row['fact_key'] ?? ''));
            if ($factKey === '') {
                continue;
            }
            if (str_starts_with((string)($row['context_ref'] ?? ''), 'comparative_')) {
                $comparativePresent[$factKey] = true;
            } else {
                $currentPresent[$factKey] = true;
            }
        }

        $missing = array_values(array_filter(
            $required,
            static fn(string $factKey): bool => !isset($currentPresent[$factKey])
        ));
        foreach ($comparativeRequiredKeys as $factKey) {
            if (!isset($comparativePresent[$factKey])) {
                $missing[] = 'comparative:' . $factKey;
            }
        }

        return $missing;
    }

    private function comparativeFactsRequired(int $companyId, int $accountingPeriodId): bool
    {
        if ($companyId <= 0
            || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists('year_end_reviews')) {
            return false;
        }
        $periodStart = trim((string)(\InterfaceDB::fetchColumn(
            'SELECT period_start FROM accounting_periods
             WHERE id = :accounting_period_id AND company_id = :company_id
             LIMIT 1',
            ['accounting_period_id' => $accountingPeriodId, 'company_id' => $companyId]
        ) ?: ''));
        if ($periodStart === '') {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_periods ap
             INNER JOIN year_end_reviews yr
               ON yr.company_id = ap.company_id
              AND yr.accounting_period_id = ap.id
              AND yr.is_locked = 1
             WHERE ap.company_id = :company_id
               AND ap.period_end < :period_start',
            ['company_id' => $companyId, 'period_start' => $periodStart]
        ) > 0;
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
            'participator_loan_asset_nominal_id' => 'participator loan asset nominal',
            'participator_loan_liability_nominal_id' => 'participator loan liability nominal',
            'vat_nominal_id' => 'VAT nominal',
            'corporation_tax_expense_nominal_id' => 'Corporation Tax expense nominal',
            'corporation_tax_liability_nominal_id' => 'Corporation Tax liability nominal',
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

}
