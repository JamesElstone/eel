<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

use eel_accounts\Repository\IxbrlAccountsArtifactRepository;
use eel_accounts\Repository\IxbrlValidationRunRepository;

final class IxbrlReadinessService
{
    public function getReadiness(int $companyId, int $accountingPeriodId): array
    {
        $cacheKey = \eel_accounts\Support\RequestCache::key($companyId, $accountingPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'ixbrl.readiness',
            $cacheKey,
            function () use ($companyId, $accountingPeriodId): array {
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
            'Statutory accounts basis approved',
            $approvalCurrent,
            ['build', 'generate', 'filing'],
            $approvalCurrent
                ? 'The current disclosures and authority-neutral accounts report have an immutable approval.'
                : (string)(($filingApproval['errors'] ?? [])[0]
                    ?? 'Approve disclosures and build filing facts from the Accounts Disclosures panel.')
        );

        $missingEvidenceError = $this->missingApprovalEvidenceError($filingApproval);
        $this->addCheck(
            $checks,
            'filing_approval_evidence_available',
            'Filing approval evidence available',
            $missingEvidenceError === '',
            ['build', 'generate', 'filing'],
            $missingEvidenceError !== ''
                ? $missingEvidenceError
                : 'The latest filing approval has an available immutable evidence bundle.'
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

        $authorityReadiness = $this->authorityReadiness(
            $companyId,
            $accountingPeriodId,
            is_array($latestRun) ? $latestRun : null,
            $filingApproval
        );
        $hmrcAccounts = (array)($authorityReadiness['hmrc_accounts'] ?? []);
        $generated = !empty($hmrcAccounts['generated']);
        $fileExists = !empty($hmrcAccounts['file_exists']);
        $validationPassed = (string)($hmrcAccounts['core_status'] ?? '') === 'passed'
            && (string)($hmrcAccounts['authority_status'] ?? '') === 'passed';
        $this->addCheck(
            $checks,
            'ixbrl_generated',
            'HMRC accounts iXBRL generated',
            $generated && $fileExists,
            ['filing'],
            $generated && $fileExists
                ? 'The current HMRC accounts artifact exists independently of the Companies House artifact.'
                : 'Generate the current HMRC Accounting iXBRL export.'
        );
        $this->addCheck(
            $checks,
            'ixbrl_validation_passed',
            'HMRC accounts structural and profile validation passed',
            $validationPassed,
            ['filing'],
            $validationPassed
                ? 'The HMRC accounts artifact passed its core checks and HMRC 2011 transformation-registry profile.'
                : (string)(($hmrcAccounts['errors'] ?? [])[0]
                    ?? 'Generate the HMRC accounts export so its authority-specific validation can run.')
        );

        $externalValidation = (array)($hmrcAccounts['external_validation'] ?? [
            'status' => 'not_run',
            'detail' => 'HMRC Arelle validation has not been run for the current HMRC accounts artifact.',
        ]);
        $this->addCheck(
            $checks,
            'ixbrl_external_validation',
            'HMRC Arelle external validation',
            (string)($externalValidation['status'] ?? '') === 'passed',
            ['filing'],
            (string)($externalValidation['detail'] ?? 'Arelle external validation has not been run.')
        );

        $artifactCurrent = !empty($hmrcAccounts['ready']);
        $this->addCheck(
            $checks,
            'ixbrl_validated_artifact_current',
            'Validated artifact hash matches',
            $artifactCurrent,
            ['filing'],
            $artifactCurrent
                ? 'The current HMRC file matches its immutable artifact, HMRC profile, and validation evidence.'
                : (string)(($hmrcAccounts['errors'] ?? [])[0]
                    ?? 'The HMRC artifact bytes, approved basis, authority profile, and validation evidence must all match.')
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
            'authority_readiness' => $authorityReadiness,
            'hmrc_accounts' => $hmrcAccounts,
            'hmrc_computations' => (array)($authorityReadiness['hmrc_computations'] ?? []),
            'companies_house_accounts' => (array)($authorityReadiness['companies_house_accounts'] ?? []),
            'ready_for_hmrc_accounts' => !empty($hmrcAccounts['ready']),
            'ready_for_companies_house_accounts' => !empty($authorityReadiness['companies_house_accounts']['ready']),
        ];
            }
        );
    }

    /**
     * Returns authority-specific artifact and validation decisions. A failure for one
     * destination is deliberately not copied onto either of the other destinations.
     *
     * @param array<string,mixed>|null $latestRun
     * @param array<string,mixed> $filingApproval
     * @return array<string,mixed>
     */
    private function authorityReadiness(
        int $companyId,
        int $accountingPeriodId,
        ?array $latestRun,
        array $filingApproval
    ): array {
        $profiles = new IxbrlAuthorityProfileService();
        $hmrcAccountsProfile = $profiles->profile(IxbrlAuthorityProfileService::HMRC_CT_ACCOUNTS);
        $hmrcComputationProfile = $profiles->profile(IxbrlAuthorityProfileService::HMRC_CT_COMPUTATION);
        $companiesHouseProfile = $profiles->profile(IxbrlAuthorityProfileService::COMPANIES_HOUSE_ACCOUNTS);
        $unavailable = !\InterfaceDB::tableExists('ixbrl_accounts_artifacts')
            || !\InterfaceDB::tableExists('ixbrl_validation_runs');
        if ($unavailable) {
            $message = 'Apply the authority-specific iXBRL artifact and validation migration.';
            return [
                'hmrc_accounts' => $this->missingAuthorityStatus($hmrcAccountsProfile, $message),
                'hmrc_computations' => [
                    'profile' => $hmrcComputationProfile->toArray(),
                    'profile_fingerprint' => $hmrcComputationProfile->fingerprint(),
                    'ready' => false,
                    'periods' => [],
                    'errors' => [$message],
                ],
                'companies_house_accounts' => $this->missingAuthorityStatus($companiesHouseProfile, $message),
            ];
        }

        $artifacts = new IxbrlAccountsArtifactRepository();
        $validations = new IxbrlValidationRunRepository();
        $approval = is_array($filingApproval['approval'] ?? null)
            ? (array)$filingApproval['approval']
            : [];
        $hmrcArtifact = $artifacts->findCurrent(
            $companyId,
            $accountingPeriodId,
            IxbrlAccountsArtifactRepository::AUTHORITY_HMRC,
            'ordinary'
        );
        $companiesHouseFilingKind = (new CompaniesHouseAccountsSubmissionService())
            ->filingKindForArtifact($companyId, $accountingPeriodId);
        $companiesHouseArtifact = $this->latestCompaniesHouseArtifact(
            $artifacts,
            $companyId,
            $accountingPeriodId,
            $companiesHouseFilingKind
        );

        return [
            'hmrc_accounts' => $this->accountsAuthorityStatus(
                $hmrcArtifact,
                $hmrcAccountsProfile,
                $validations,
                $latestRun,
                $approval
            ),
            'hmrc_computations' => $this->computationAuthorityStatus(
                $companyId,
                $accountingPeriodId,
                $hmrcComputationProfile
            ),
            'companies_house_accounts' => $this->accountsAuthorityStatus(
                $companiesHouseArtifact,
                $companiesHouseProfile,
                $validations,
                $latestRun,
                $approval
            ) + ['filing_kind' => $companiesHouseFilingKind],
        ];
    }

    private function latestCompaniesHouseArtifact(
        IxbrlAccountsArtifactRepository $artifacts,
        int $companyId,
        int $accountingPeriodId,
        string $filingKind
    ): ?array {
        $filingKind = strtolower(trim($filingKind));
        if (!in_array($filingKind, ['original', 'revised'], true)) {
            return null;
        }

        return $artifacts->findCurrent(
            $companyId,
            $accountingPeriodId,
            IxbrlAccountsArtifactRepository::AUTHORITY_COMPANIES_HOUSE,
            $filingKind
        );
    }

    /** @param array<string,mixed>|null $artifact @param array<string,mixed>|null $latestRun @param array<string,mixed> $approval */
    private function accountsAuthorityStatus(
        ?array $artifact,
        IxbrlAuthorityProfile $profile,
        IxbrlValidationRunRepository $validations,
        ?array $latestRun,
        array $approval
    ): array {
        if (!is_array($artifact)) {
            return $this->missingAuthorityStatus($profile, 'No ' . $this->authorityLabel($profile) . ' artifact has been generated.');
        }

        $errors = [];
        $path = trim((string)($artifact['output_path'] ?? ''));
        $expectedHash = strtolower(trim((string)($artifact['output_sha256'] ?? '')));
        $diskHash = $path !== '' && is_file($path)
            ? ((new IxbrlArtifactFingerprintService())->sha256($path) ?? '')
            : '';
        $fileExists = $path !== '' && is_file($path);
        if (!$fileExists) {
            $errors[] = 'The ' . $this->authorityLabel($profile) . ' artifact file is missing.';
        } elseif ($expectedHash === '' || $diskHash === '' || !hash_equals($expectedHash, $diskHash)) {
            $errors[] = 'The ' . $this->authorityLabel($profile) . ' artifact file no longer matches its immutable SHA-256.';
        }

        $profileMatches = (string)($artifact['profile_key'] ?? '') === $profile->key()
            && (string)($artifact['profile_version'] ?? '') === $profile->version()
            && hash_equals((string)($artifact['profile_fingerprint'] ?? ''), $profile->fingerprint())
            && (string)($artifact['transformation_registry_uri'] ?? '') === $profile->transformationNamespace();
        if (!$profileMatches) {
            $errors[] = 'The artifact was not generated with the current ' . $this->authorityLabel($profile)
                . ' authority profile (' . $this->registryYear($profile) . ' transformation registry).';
        }

        $basisMatches = is_array($latestRun)
            && (int)($artifact['generation_run_id'] ?? 0) === (int)($latestRun['id'] ?? 0)
            && (int)($artifact['filing_approval_id'] ?? 0) === (int)($approval['id'] ?? 0)
            && trim((string)($artifact['filing_approval_hash'] ?? '')) !== ''
            && hash_equals(
                strtolower((string)($artifact['filing_approval_hash'] ?? '')),
                strtolower((string)($approval['basis_hash'] ?? ''))
            );
        if (!$basisMatches) {
            $errors[] = 'The ' . $this->authorityLabel($profile) . ' artifact belongs to an earlier approved accounts basis.';
        }
        if ($profile->authority() === 'HMRC'
            && (!is_array($latestRun)
                || (string)($latestRun['validation_status'] ?? '') !== 'passed'
                || (string)($latestRun['external_validation_status'] ?? '') !== 'passed'
                || trim((string)($latestRun['external_validator'] ?? '')) === ''
                || trim((string)($latestRun['external_validator_version'] ?? '')) === ''
                || $expectedHash === ''
                || !hash_equals(
                    $expectedHash,
                    strtolower(trim((string)($latestRun['external_validated_sha256'] ?? '')))
                ))) {
            $errors[] = 'The latest HMRC accounts iXBRL validation attempt is not filing-ready.';
        }

        $validation = $validations->latestForArtifact((int)($artifact['id'] ?? 0));
        $validationMatches = is_array($validation)
            && (string)($validation['profile_key'] ?? '') === $profile->key()
            && (string)($validation['profile_version'] ?? '') === $profile->version()
            && hash_equals((string)($validation['profile_fingerprint'] ?? ''), $profile->fingerprint())
            && $expectedHash !== ''
            && hash_equals((string)($validation['artifact_sha256'] ?? ''), $expectedHash);
        if (!is_array($validation)) {
            $errors[] = 'The current ' . $this->authorityLabel($profile) . ' artifact has not been validated.';
        } elseif (!$validationMatches) {
            $errors[] = 'The latest validation evidence does not belong to the current '
                . $this->authorityLabel($profile) . ' artifact and profile.';
        } elseif ((string)($validation['overall_status'] ?? '') !== 'passed') {
            $errors[] = 'The current ' . $this->authorityLabel($profile) . ' artifact did not pass its independent validation.';
        }

        $ready = $errors === [];
        $artifactBytesCurrent = $fileExists && $expectedHash !== '' && $diskHash !== ''
            && hash_equals($expectedHash, $diskHash);
        $state = $ready
            ? 'filing_ready'
            : (!$fileExists ? 'not_generated'
                : (!$profileMatches || !$basisMatches || !$artifactBytesCurrent ? 'stale'
                    : (!is_array($validation) ? 'not_validated' : 'failed')));
        $displayValidation = $this->validationDisplay($validation);

        return [
            'profile' => $profile->toArray(),
            'profile_fingerprint' => $profile->fingerprint(),
            'artifact' => $artifact,
            'artifact_id' => (int)($artifact['id'] ?? 0),
            'validation' => $validation,
            'validation_run_id' => (int)($validation['id'] ?? 0),
            'display_validation' => $displayValidation,
            'generated' => true,
            'file_exists' => $fileExists,
            'basis_current' => $basisMatches,
            'profile_current' => $profileMatches,
            'core_status' => (string)($validation['core_status'] ?? 'not_run'),
            'authority_status' => (string)($validation['authority_status'] ?? 'not_run'),
            'arelle_status' => (string)($validation['arelle_status'] ?? 'not_run'),
            'ready' => $ready,
            'state' => $state,
            'errors' => array_values(array_unique($errors)),
            'external_validation' => $this->externalValidationSummary($profile, $validation, $validationMatches),
        ];
    }

    private function missingAuthorityStatus(IxbrlAuthorityProfile $profile, string $message): array
    {
        return [
            'profile' => $profile->toArray(),
            'profile_fingerprint' => $profile->fingerprint(),
            'artifact' => null,
            'artifact_id' => 0,
            'validation' => null,
            'validation_run_id' => 0,
            'display_validation' => [],
            'generated' => false,
            'file_exists' => false,
            'basis_current' => false,
            'profile_current' => false,
            'core_status' => 'not_run',
            'authority_status' => 'not_run',
            'arelle_status' => 'not_run',
            'ready' => false,
            'state' => 'not_generated',
            'errors' => [$message],
            'external_validation' => ['status' => 'not_run', 'detail' => $message],
        ];
    }

    private function computationAuthorityStatus(
        int $companyId,
        int $accountingPeriodId,
        IxbrlAuthorityProfile $profile
    ): array {
        $runs = \InterfaceDB::fetchAll(
            'SELECT * FROM corporation_tax_computation_runs
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
             ORDER BY ct_period_id ASC, id DESC',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        $latestByPeriod = [];
        foreach ($runs as $run) {
            $ctPeriodId = (int)($run['ct_period_id'] ?? 0);
            if ($ctPeriodId > 0 && !isset($latestByPeriod[$ctPeriodId])) {
                $latestByPeriod[$ctPeriodId] = $run;
            }
        }

        $periods = [];
        $errors = [];
        foreach ($latestByPeriod as $ctPeriodId => $run) {
            $path = trim((string)($run['generated_path'] ?? ''));
            $artifactHash = strtolower(trim((string)($run['output_sha256'] ?? '')));
            $diskHash = $path !== '' && is_file($path)
                ? ((new IxbrlArtifactFingerprintService())->sha256($path) ?? '')
                : '';
            $periodErrors = [];
            if ($path === '' || !is_file($path)) {
                $periodErrors[] = 'The HMRC computation artifact has not been generated or its file is missing.';
            } elseif ($artifactHash === '' || $diskHash === '' || !hash_equals($artifactHash, $diskHash)) {
                $periodErrors[] = 'The HMRC computation artifact no longer matches its immutable SHA-256.';
            }
            if ((string)($run['ixbrl_status'] ?? '') !== 'validated'
                || (string)($run['validation_status'] ?? '') !== 'passed'
                || (string)($run['external_validation_status'] ?? '') !== 'passed'
                || $artifactHash === ''
                || !hash_equals(
                    $artifactHash,
                    strtolower(trim((string)($run['external_validated_sha256'] ?? '')))
                )) {
                $periodErrors[] = 'The latest HMRC computation validation attempt is not filing-ready.';
            }
            $validation = \InterfaceDB::fetchOne(
                'SELECT * FROM ixbrl_validation_runs WHERE computation_run_id = :run_id ORDER BY id DESC LIMIT 1',
                ['run_id' => (int)($run['id'] ?? 0)]
            );
            $validationMatches = is_array($validation)
                && (string)($validation['profile_key'] ?? '') === $profile->key()
                && (string)($validation['profile_version'] ?? '') === $profile->version()
                && hash_equals((string)($validation['profile_fingerprint'] ?? ''), $profile->fingerprint())
                && $artifactHash !== ''
                && hash_equals((string)($validation['artifact_sha256'] ?? ''), $artifactHash);
            if (!is_array($validation)) {
                $periodErrors[] = 'The current HMRC computation artifact has not been validated.';
            } elseif (!$validationMatches) {
                $periodErrors[] = 'The HMRC computation validation evidence does not match the current 2011-profile artifact.';
            } elseif ((string)($validation['overall_status'] ?? '') !== 'passed') {
                $periodErrors[] = 'The current HMRC computation artifact did not pass its independent validation.';
            }
            $ready = $periodErrors === [];
            $periods[(string)$ctPeriodId] = [
                'profile' => $profile->toArray(),
                'profile_fingerprint' => $profile->fingerprint(),
                'run' => $run,
                'computation_run_id' => (int)($run['id'] ?? 0),
                'validation' => $validation,
                'validation_run_id' => (int)($validation['id'] ?? 0),
                'display_validation' => $this->validationDisplay(is_array($validation) ? $validation : null),
                'generated' => $path !== '',
                'file_exists' => $path !== '' && is_file($path),
                'profile_current' => $validationMatches,
                'ready' => $ready,
                'state' => $ready ? 'filing_ready' : ($path === '' ? 'not_generated'
                    : (!is_array($validation) ? 'not_validated'
                        : ((string)($validation['overall_status'] ?? '') === 'passed' ? 'stale' : 'failed'))),
                'errors' => array_values(array_unique($periodErrors)),
            ];
            foreach ($periodErrors as $error) {
                $errors[] = 'Corporation Tax period ' . $ctPeriodId . ': ' . $error;
            }
        }

        return [
            'profile' => $profile->toArray(),
            'profile_fingerprint' => $profile->fingerprint(),
            'ready' => $periods !== [] && array_reduce(
                $periods,
                static fn(bool $ready, array $period): bool => $ready && !empty($period['ready']),
                true
            ),
            'periods' => $periods,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @param array<string,mixed>|null $validation */
    private function validationDisplay(?array $validation): array
    {
        if (!is_array($validation)) {
            return [];
        }
        $arelle = $this->decodeJsonArray($validation['arelle_results_json'] ?? null);
        return [
            'status' => (string)($validation['overall_status'] ?? 'not_run'),
            'validation_status' => (string)($validation['core_status'] ?? 'not_run'),
            'authority_validation_status' => (string)($validation['authority_status'] ?? 'not_run'),
            'external_validation_status' => (string)($validation['arelle_status'] ?? 'not_run'),
            'external_validator' => (string)($validation['validator_name'] ?? ''),
            'external_validator_version' => (string)($validation['validator_version'] ?? ''),
            'external_validation_errors_json' => \eel_accounts\Support\Utf8::json((array)($arelle['errors'] ?? [])),
            'external_validation_warnings_json' => \eel_accounts\Support\Utf8::json((array)($arelle['warnings'] ?? [])),
            'external_validation_log_path' => (string)($validation['arelle_log_path'] ?? $arelle['log_path'] ?? ''),
            'external_validated_at' => (string)($validation['validated_at'] ?? ''),
            'version' => (string)($validation['validator_version'] ?? ''),
            'errors' => (array)($arelle['errors'] ?? []),
            'warnings' => (array)($arelle['warnings'] ?? []),
            'log_path' => (string)($validation['arelle_log_path'] ?? $arelle['log_path'] ?? ''),
            'validated_at' => (string)($validation['validated_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed>|null $validation */
    private function externalValidationSummary(
        IxbrlAuthorityProfile $profile,
        ?array $validation,
        bool $validationMatches
    ): array {
        if (!is_array($validation)) {
            return ['status' => 'not_run', 'detail' => 'Arelle has not validated the current ' . $this->authorityLabel($profile) . ' artifact.'];
        }
        if (!$validationMatches) {
            return ['status' => 'stale', 'detail' => 'The Arelle result belongs to different artifact bytes or an earlier authority profile.'];
        }
        $status = (string)($validation['arelle_status'] ?? 'not_run');
        return [
            'status' => $status,
            'detail' => $status === 'passed'
                ? 'Arelle passed the current ' . $this->authorityLabel($profile) . ' artifact under its independent profile.'
                : 'Arelle validation for the current ' . $this->authorityLabel($profile) . ' artifact is '
                    . str_replace('_', ' ', $status) . '.',
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function authorityLabel(IxbrlAuthorityProfile $profile): string
    {
        return $profile->authority() === 'HMRC'
            ? ($profile->key() === IxbrlAuthorityProfileService::HMRC_CT_COMPUTATION
                ? 'HMRC computation iXBRL'
                : 'HMRC accounts iXBRL')
            : 'Companies House accounts iXBRL';
    }

    private function registryYear(IxbrlAuthorityProfile $profile): string
    {
        return str_contains($profile->transformationNamespace(), '/2011-') ? '2011' : '2015';
    }

    /** Returns a generation blocker when the approval points at deleted Year End evidence. */
    private function missingApprovalEvidenceError(array $filingApproval): string
    {
        $approval = is_array($filingApproval['approval'] ?? null)
            ? (array)$filingApproval['approval']
            : [];
        $approvalId = (int)($approval['id'] ?? 0);
        $bundleId = (int)($approval['evidence_bundle_id'] ?? 0);
        if ($approvalId <= 0 || $bundleId <= 0
            || !\InterfaceDB::tableExists('filing_evidence_bundles')) {
            return '';
        }

        $bundleExists = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM filing_evidence_bundles WHERE id = :id',
            ['id' => $bundleId]
        ) === 1;
        if ($bundleExists) {
            return '';
        }

        return 'Filing approval #' . $approvalId . ' refers to missing evidence bundle #' . $bundleId
            . '. Unlock Year End, then re-lock it to create replacement immutable filing evidence; '
            . 'approve the filing basis again before generating iXBRL.';
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
