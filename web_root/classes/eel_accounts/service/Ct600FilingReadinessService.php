<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class Ct600FilingReadinessService
{
    /** @return array<string, mixed> */
    public function assess(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        ?string $environment = null
    ): array {
        $configuration = new HmrcCtConfigurationService();
        $environment = HmrcCtConfigurationService::normaliseEnvironment(
            $environment ?? $configuration->environment()
        );
        $profile = $configuration->profile($environment);
        $checks = [];
        $warnings = [];

        $company = $companyId > 0
            ? (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId)
            : null;
        $accountingPeriod = $companyId > 0 && $accountingPeriodId > 0
            ? (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId)
            : null;
        $ctPeriod = $this->ctPeriod($companyId, $accountingPeriodId, $ctPeriodId);

        $this->addCheck(
            $checks,
            'company_identity',
            'Company identity',
            is_array($company)
                && (string)($company['company_status'] ?? '') === 'active'
                && preg_match('/^[A-Z0-9]{8}$/', strtoupper(trim((string)($company['company_number'] ?? '')))) === 1,
            'An active company with an eight-character Companies House number is required.',
            ['prepare', 'submit']
        );
        $this->addCheck(
            $checks,
            'accounting_period',
            'Accounting period ownership',
            is_array($accountingPeriod),
            'The selected accounting period does not belong to the company.',
            ['prepare', 'submit']
        );
        $this->addCheck(
            $checks,
            'ct_period',
            'Corporation Tax period ownership',
            is_array($ctPeriod),
            'The selected Corporation Tax period does not belong to the company and accounting period.',
            ['prepare', 'submit']
        );

        $testDataEligible = $environment !== HmrcCtConfigurationService::TEST
            || $configuration->isSyntheticTestCompany($companyId);
        $this->addCheck(
            $checks,
            'environment_data_scope',
            'Environment data scope',
            $testDataEligible,
            'HMRC ETS/TEST accepts synthetic data only. This company is not on the server-controlled synthetic-company allowlist; use TIL for real AP79 validation.',
            ['prepare', 'submit'],
            $environment === HmrcCtConfigurationService::TEST
                ? 'The company is explicitly configured as deterministic synthetic test data.'
                : ($environment === HmrcCtConfigurationService::TIL
                    ? 'TIL may validate the real frozen return without registering a statutory filing.'
                    : 'LIVE is the statutory filing environment.')
        );

        $lock = is_array($accountingPeriod)
            ? (new YearEndLockService())->fetchReview($companyId, $accountingPeriodId)
            : null;
        $locked = is_array($lock) && !empty($lock['is_locked']);
        $this->addCheck(
            $checks,
            'year_end_locked',
            'Year End locked',
            $locked,
            'Year End must remain locked before a CT600 package can be prepared or submitted.',
            ['prepare', 'submit'],
            $locked ? 'Locked at ' . (string)($lock['locked_at'] ?? '') . '.' : ''
        );

        $settings = $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : [];
        $utr = preg_replace('/\s+/', '', trim((string)($settings['utr'] ?? ''))) ?? '';
        $utrValid = preg_match('/^\d{10}$/', $utr) === 1;
        $this->addCheck(
            $checks,
            'utr',
            'Corporation Tax UTR',
            $utrValid,
            'A genuine 10-digit Corporation Tax UTR is required; it is stored as text so leading zeroes are preserved.',
            ['prepare', 'submit']
        );

        $computation = is_array($ctPeriod)
            ? (new CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, $ctPeriodId)
            : ['available' => false];
        $persistence = (array)($computation['computation_persistence'] ?? []);
        $computationCurrent = !empty($computation['available'])
            && !empty($persistence['current'])
            && (int)($computation['computation_run_id'] ?? 0) === (int)($ctPeriod['latest_computation_run_id'] ?? 0);
        $this->addCheck(
            $checks,
            'locked_computation',
            'Locked CT computation snapshot',
            $computationCurrent,
            'The latest lock-time Corporation Tax computation is missing or stale.',
            ['prepare', 'submit'],
            $computationCurrent ? 'Computation run #' . (int)$computation['computation_run_id'] . ' is current.' : ''
        );

        $accounts = is_array($accountingPeriod) && class_exists(IxbrlFilingArtifactService::class)
            ? (new IxbrlFilingArtifactService())->locate($companyId, $accountingPeriodId)
            : ['ok' => false, 'errors' => ['A current accounts iXBRL filing artifact is required.'], 'warnings' => []];
        $this->addCheck(
            $checks,
            'accounts_ixbrl',
            'Accounts iXBRL',
            !empty($accounts['ok']),
            (string)(($accounts['errors'] ?? [])[0] ?? 'A current internally and externally validated accounts iXBRL is required.'),
            ['prepare', 'submit'],
            !empty($accounts['ok']) ? (string)($accounts['filename'] ?? '') : ''
        );
        $warnings = array_merge($warnings, (array)($accounts['warnings'] ?? []));

        $computations = is_array($ctPeriod)
            ? (new CtComputationIxbrlArtifactService())->locate($companyId, $ctPeriodId)
            : ['ok' => false, 'errors' => ['A period-specific computations iXBRL filing artifact is required.'], 'warnings' => []];
        $this->addCheck(
            $checks,
            'computations_ixbrl',
            'Computations iXBRL',
            !empty($computations['ok']),
            (string)(($computations['errors'] ?? [])[0] ?? 'A current internally and externally validated computations iXBRL is required.'),
            ['prepare', 'submit'],
            !empty($computations['ok']) ? (string)($computations['filename'] ?? '') : ''
        );
        $warnings = array_merge($warnings, (array)($computations['warnings'] ?? []));

        $taxonomy = $this->taxonomyAcceptance(
            is_array($accountingPeriod) ? $accountingPeriod : [],
            is_array($ctPeriod) ? $ctPeriod : [],
            is_array($accounts) ? $accounts : [],
            is_array($computations) ? $computations : []
        );
        $this->addCheck(
            $checks,
            'accepted_taxonomies',
            'HMRC-accepted iXBRL taxonomies',
            !empty($taxonomy['accepted']),
            (string)(($taxonomy['errors'] ?? [])[0] ?? 'The accounts or computations taxonomy is not accepted for this period.'),
            ['prepare', 'submit'],
            !empty($taxonomy['accepted'])
                ? 'Catalog checked ' . (string)($taxonomy['catalog_checked_at'] ?? '') . '.'
                : ''
        );
        $warnings = array_merge($warnings, (array)($taxonomy['warnings'] ?? []));

        $supplementary = is_array($ctPeriod)
            ? (new Ct600SupplementaryEligibilityService())->assess(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                is_array($computation) ? $computation : []
            )
            : ['ok' => false, 'blockers' => ['Supplementary-page eligibility could not be assessed.'], 'warnings' => []];
        $this->addCheck(
            $checks,
            'phase_one_scope',
            'Phase-one CT600 scope',
            !empty($supplementary['ok']),
            (string)(($supplementary['blockers'] ?? [])[0] ?? 'An unsupported supplementary page or CT600 case is required.'),
            ['prepare', 'submit']
        );
        $warnings = array_merge($warnings, (array)($supplementary['warnings'] ?? []));

        $schemaReady = is_file((string)$profile['schema_path'])
            && is_file((string)$profile['envelope_schema_path'])
            && is_file((string)$profile['schematron_xslt_path']);
        $this->addCheck(
            $checks,
            'rim_artifacts',
            'HMRC CT600 V3 RIM artefacts',
            $schemaReady,
            'Install the pinned HMRC CT600 V3 V1.994 XSD, envelope schema, and Schematron XSLT before preparation.',
            ['prepare', 'submit'],
            $schemaReady ? 'Configured RIM ' . (string)$profile['rim_version'] . '.' : ''
        );
        $xslReady = class_exists(\XSLTProcessor::class)
            && method_exists(\XSLTProcessor::class, 'setSecurityPrefs');
        $this->addCheck(
            $checks,
            'schematron_runtime',
            'HMRC Schematron validation runtime',
            $xslReady,
            'Enable PHP ext-xsl so the pinned HMRC V1.994 business-rule transform can run before preparation.',
            ['prepare', 'submit'],
            $xslReady ? 'PHP XSL/libxslt is available.' : ''
        );

        $credentials = $configuration->credentialStatus($environment);
        $this->addCheck(
            $checks,
            'xml_credentials',
            'HMRC CT XML credentials',
            !empty($credentials['ok']),
            (string)(($credentials['errors'] ?? [])[0] ?? 'Dedicated HMRC CT XML credentials and Vendor ID are required.'),
            ['submit']
        );

        $liveEnablement = $environment === HmrcCtConfigurationService::LIVE
            ? $configuration->liveEnablementStatus($companyId)
            : ['ok' => true, 'errors' => []];
        $this->addCheck(
            $checks,
            'live_enablement',
            'Guarded LIVE enablement',
            !empty($liveEnablement['ok']),
            (string)(($liveEnablement['errors'] ?? [])[0] ?? 'LIVE CT600 filing is not authorised for this company.'),
            ['submit'],
            $environment !== HmrcCtConfigurationService::LIVE
                ? 'Not required outside LIVE.'
                : (!empty($liveEnablement['ok']) ? 'LIVE is explicitly enabled for this enrolled company.' : '')
        );

        $sequence = $this->sequenceEligibility($companyId, $accountingPeriodId, $ctPeriod, $environment);
        $this->addCheck(
            $checks,
            'submission_sequence',
            'Submission sequence',
            !empty($sequence['ok']),
            (string)(($sequence['errors'] ?? [])[0] ?? 'An earlier CT period must receive LIVE acceptance first.'),
            ['submit'],
            $environment !== HmrcCtConfigurationService::LIVE
                ? 'TEST and TIL do not alter or depend on statutory filing state.'
                : ''
        );

        $prepareBlockers = $this->blockersForStage($checks, 'prepare');
        $submitBlockers = $this->blockersForStage($checks, 'submit');

        return [
            'ok' => $prepareBlockers === [],
            'can_prepare' => $prepareBlockers === [],
            'can_submit' => $submitBlockers === [],
            'environment' => $environment,
            'profile' => $this->publicProfile($profile),
            'company' => $company,
            'accounting_period' => $accountingPeriod,
            'ct_period' => $ctPeriod,
            'lock' => $lock,
            'utr' => $utrValid ? $utr : '',
            'computation' => $computation,
            'accounts' => $accounts,
            'computations' => $computations,
            'taxonomy' => $taxonomy,
            'credentials' => $credentials,
            'live_enablement' => $liveEnablement,
            'supplementary' => $supplementary,
            'supplementary_assessment_id' => $supplementary['assessment_id'] ?? null,
            'supplementary_assessment_hash' => $supplementary['assessment_hash'] ?? null,
            'sequence' => $sequence,
            'checks' => $checks,
            'blockers' => $prepareBlockers,
            'submit_blockers' => $submitBlockers,
            'warnings' => array_values(array_unique(array_filter(array_map('strval', $warnings)))),
        ];
    }

    /** @return array<string, mixed> */
    private function taxonomyAcceptance(
        array $accountingPeriod,
        array $ctPeriod,
        array $accounts,
        array $computations,
    ): array {
        if (empty($accounts['ok']) || empty($computations['ok'])) {
            return [
                'accepted' => false,
                'errors' => ['Validated accounts and computations iXBRL are required before taxonomy acceptance can be checked.'],
                'warnings' => [],
                'catalog_checked_at' => Ct600TaxonomyAcceptanceService::CATALOG_CHECKED_AT,
            ];
        }

        $accountsProfile = '';
        $accountsRunId = (int)($accounts['run_id'] ?? 0);
        if ($accountsRunId > 0
            && \InterfaceDB::tableExists('ixbrl_generation_runs')
            && \InterfaceDB::columnExists('ixbrl_generation_runs', 'taxonomy_profile')) {
            $accountsProfile = trim((string)\InterfaceDB::fetchColumn(
                'SELECT taxonomy_profile FROM ixbrl_generation_runs WHERE id = :id LIMIT 1',
                ['id' => $accountsRunId]
            ));
        }

        $service = new Ct600TaxonomyAcceptanceService();
        $accountsResult = $service->assessDocument(
            'accounts',
            (string)($accountingPeriod['period_end'] ?? ''),
            ['taxonomy_profile' => $accountsProfile]
        );
        $computationResult = $service->assessDocument(
            'computation',
            (string)($ctPeriod['period_end'] ?? ''),
            ['taxonomy_profile' => (string)($computations['taxonomy_profile'] ?? '')]
        );

        return [
            'accepted' => !empty($accountsResult['accepted']) && !empty($computationResult['accepted']),
            'accounts' => $accountsResult,
            'computation' => $computationResult,
            'errors' => array_values(array_unique(array_merge(
                (array)($accountsResult['errors'] ?? []),
                (array)($computationResult['errors'] ?? [])
            ))),
            'warnings' => array_values(array_unique(array_merge(
                (array)($accountsResult['warnings'] ?? []),
                (array)($computationResult['warnings'] ?? [])
            ))),
            'catalog_checked_at' => Ct600TaxonomyAcceptanceService::CATALOG_CHECKED_AT,
            'source_updated_at' => Ct600TaxonomyAcceptanceService::SOURCE_UPDATED_AT,
        ];
    }

    /** @return array<string, mixed>|null */
    private function ctPeriod(int $companyId, int $accountingPeriodId, int $ctPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0
            || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM corporation_tax_periods
             WHERE id = :id AND company_id = :company_id AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['id' => $ctPeriodId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function sequenceEligibility(int $companyId, int $accountingPeriodId, ?array $ctPeriod, string $environment): array
    {
        if (!is_array($ctPeriod)) {
            return ['ok' => false, 'errors' => ['Select a valid CT period.']];
        }
        if ($environment !== HmrcCtConfigurationService::LIVE) {
            return ['ok' => true, 'errors' => []];
        }

        $blocking = \InterfaceDB::fetchOne(
            'SELECT id, sequence_no, period_start, period_end, status
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND sequence_no < :sequence_no
               AND status <> :accepted
             ORDER BY sequence_no ASC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'sequence_no' => (int)$ctPeriod['sequence_no'],
                'accepted' => 'accepted',
            ]
        );
        if (is_array($blocking)) {
            return [
                'ok' => false,
                'errors' => ['CT Period ' . (int)$blocking['sequence_no'] . ' must receive final LIVE acceptance before this later period can be filed.'],
            ];
        }

        if (\InterfaceDB::tableExists('hmrc_ct600_submissions')) {
            $whereEnvironment = \InterfaceDB::columnExists('hmrc_ct600_submissions', 'environment')
                ? "environment = 'LIVE' AND business_outcome = 'live_accepted'"
                : "mode = 'LIVE' AND status = 'accepted'";
            $accepted = (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM hmrc_ct600_submissions
                 WHERE company_id = :company_id AND ct_period_id = :ct_period_id
                   AND submission_type = :submission_type AND ' . $whereEnvironment,
                [
                    'company_id' => $companyId,
                    'ct_period_id' => (int)$ctPeriod['id'],
                    'submission_type' => 'original',
                ]
            );
            if ($accepted > 0) {
                return ['ok' => false, 'errors' => ['A LIVE original return has already been accepted for this CT period.']];
            }
        }

        return ['ok' => true, 'errors' => []];
    }

    private function addCheck(
        array &$checks,
        string $key,
        string $label,
        bool $passed,
        string $failureDetail,
        array $stages,
        string $successDetail = ''
    ): void {
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'status' => $passed ? 'success' : 'danger',
            'status_label' => $passed ? 'Ready' : 'Blocked',
            'detail' => $passed ? ($successDetail !== '' ? $successDetail : 'Ready.') : $failureDetail,
            'stages' => array_values($stages),
        ];
    }

    /** @return array<int, string> */
    private function blockersForStage(array $checks, string $stage): array
    {
        $blockers = [];
        foreach ($checks as $check) {
            if (!empty($check['passed']) || !in_array($stage, (array)($check['stages'] ?? []), true)) {
                continue;
            }
            $blockers[] = (string)($check['label'] ?? 'Check') . ': ' . (string)($check['detail'] ?? 'Blocked.');
        }

        return $blockers;
    }

    /** @return array<string, mixed> */
    private function publicProfile(array $profile): array
    {
        return [
            'environment' => (string)$profile['environment'],
            'endpoint' => (string)$profile['endpoint'],
            'class' => (string)$profile['class'],
            'gateway_test' => (string)$profile['gateway_test'],
            'product' => (string)$profile['product'],
            'version' => (string)$profile['version'],
            'schema_version' => (string)$profile['schema_version'],
            'rim_version' => (string)$profile['rim_version'],
            'schema_ready' => is_file((string)$profile['schema_path']),
        ];
    }
}
