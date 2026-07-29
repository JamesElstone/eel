<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class IxbrlTaxComputationService
{
    private const SECTION_ORDER = [
        'identity' => 0,
        'detailed_profit_and_loss' => 10,
        'accounts_adjustments' => 20,
        'capital_allowances' => 30,
        'losses' => 40,
        'tax_liability' => 50,
    ];
    private const PRESENTATION_LABELS = [
        'identity.company_name' => 'Company name',
        'identity.company_number' => 'Company number',
        'filing_identity.utr' => 'Unique Taxpayer Reference',
        'ct_period.start_date' => 'Period start',
        'ct_period.end_date' => 'Period end',
        'computation.summary.accounting_profit' => 'Profit or loss per accounts',
        'computation.summary.disallowable_add_backs' => 'Disallowable expenses added back',
        'computation.summary.capital_expenditure_add_backs' => 'Capital expenditure added back',
        'computation.summary.disposal_profit_or_loss_adjustment' => 'Loss or profit on disposal of fixed assets',
        'computation.summary.depreciation_add_back' => 'Depreciation added back',
        'report.accounts_adjustment.revised_figure_before_tax' => 'Revised figure before tax',
        'report.accounts_adjustment.adjusted_loss_of_period' => 'Adjusted loss of period',
        'report.accounts_adjustment.adjusted_profit_for_period' => 'Adjusted profit for the period',
        'report.main_pool.opening_wdv' => 'Main pool, written down value',
        'report.main_pool.aia_qualifying_expenditure' => 'Main pool, expenditure qualifying for annual investment allowance',
        'report.main_pool.wda_qualifying_expenditure' => 'Main pool, expenditure qualifying for writing down allowance',
        'report.main_pool.total_qualifying_expenditure' => 'Main pool, total qualifying expenditure',
        'report.main_pool.aia_claimed' => 'Main pool, annual investment allowance',
        'report.main_pool.disposal_receipts' => 'Main pool, total disposal receipts',
        'report.main_pool.wda_claimed' => 'Main pool, writing down allowances',
        'report.main_pool.balancing_allowance' => 'Main pool, balancing allowances',
        'report.main_pool.balancing_charge' => 'Main pool, balancing charges',
        'report.main_pool.closing_wdv' => 'Main pool, written down value',
        'report.main_pool.total_fya_and_wda' => 'Main pool, total FYA and WDA',
        'report.main_pool.total_allowances' => 'Main pool, total allowances',
        'computation.summary.capital_allowances' => 'Capital allowances',
        'computation.summary.taxable_before_losses' => 'Trading profit or loss for the period',
        'computation.summary.losses_brought_forward' => 'Loss brought forward',
        'computation.summary.losses_used' => 'Losses used in this period',
        'computation.summary.losses_carried_forward' => 'Loss carried forward',
        'computation.summary.loss_restriction.post_2017_trading_losses.brought_forward' => 'Post-1 April 2017 trading losses brought forward',
        'report.loss.post_2017_trading_loss_arising' => 'Post-1 April 2017 trading losses arising',
        'computation.summary.loss_restriction.post_2017_trading_losses.used' => 'Post-1 April 2017 trading losses used against total profits',
        'computation.summary.loss_restriction.post_2017_trading_losses.carried_forward' => 'Post-1 April 2017 trading losses carried forward',
        'computation.summary.loss_restriction.deduction_allowance.amount' => 'Non-group deductions allowance for the period',
        'report.loss.qualifying_profits' => 'Qualifying profits',
        'report.loss.carried_forward_relief_claimed' => 'Carried-forward loss relief claimed against total profits',
        'computation.summary.loss_restriction.calculated_loss_restriction' => 'Calculated loss restriction',
        'computation.summary.taxable_profit' => 'Taxable total profits',
        'computation.summary.ordinary_corporation_tax' => 'Corporation Tax chargeable',
        'computation.summary.s455_tax' => 'Tax on loans to participators',
        'computation.summary.estimated_corporation_tax' => 'Net Corporation Tax payable',
        'return_position.ct600a_a80' => 'Tax on loans to participators',
        'return_position.tax_payable' => 'Net Corporation Tax payable',
    ];
    private const SUPPORTED_PROFILE_LABELS = [
        'ordinary-uk-trading-frs105' => 'FRS 105 micro-entity',
    ];
    private const LEGACY_TRADE_CONCEPTS = [
        'ProfitLossPerAccounts',
        'AdjustmentsMiscellaneousExpensesPerAccounts',
        'AdjustmentsCapitalExpenditure',
        'AdjustmentsLossOrProfitOnSale',
        'AdjustmentsDepreciation',
        'TotalCapitalAllowances',
    ];
    private const LEGACY_REVIEWED_CONCEPTS = [
        'CompanyName',
        'TaxReference',
        'StartOfPeriodCoveredByReturn',
        'EndOfPeriodCoveredByReturn',
        'ProfitLossPerAccounts',
        'AdjustmentsMiscellaneousExpensesPerAccounts',
        'AdjustmentsCapitalExpenditure',
        'AdjustmentsLossOrProfitOnSale',
        'AdjustmentsDepreciation',
        'TotalCapitalAllowances',
        'ProfitsBeforeOtherDeductionsAndReliefs',
        'TradingLossesBroughtForward',
        'TradingLossesBroughtForwardAmountUsedAgainstTotalProfits',
        'TotalProfitsChargeableToCorporationTax',
        'CorporationTaxChargeable',
        'TaxPayableOnLoansToParticipators',
        'NetTaxPayable',
    ];

    public function generateFilingExport(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        ?\Closure $beforeExternalValidation = null
    ): array
    {
        $model = (new CtPeriodFilingModelService())->build($companyId, $accountingPeriodId, $ctPeriodId);
        if (empty($model['available'])) {
            return ['success' => false, 'errors' => (array)($model['errors'] ?? ['The locked filing model is unavailable.'])];
        }
        $run = (array)$model['run'];
        $runId = (int)$run['run_id'];
        $catalogue = new HmrcCtComputationCatalogueService();
        $package = $catalogue->resolveForPeriod((string)$run['period_start'], (string)$run['period_end']);
        if (!is_array($package)) {
            return $this->failRun($runId, 'No verified HMRC computation-taxonomy package with a combined CT/DPL entry point applies to this CT period.');
        }
        $packageHash = $catalogue->verifiedPackageHash($package);
        if ($packageHash === null) {
            return $this->failRun($runId, 'The applicable computation-taxonomy package is missing, changed or has no verified inventory hash.');
        }
        $profile = (new CtFilingMappingService())->activeProfile(CtFilingMappingService::TARGET_COMPUTATION, (int)$package['id']);
        if (!is_array($profile)) {
            return $this->failRun($runId, 'No active, compatible database mapping profile exists for the applicable computation taxonomy.');
        }
        if (preg_match('/^[a-f0-9]{64}$/i', (string)($profile['content_hash'] ?? '')) !== 1) {
            return $this->failRun($runId, 'The active computation mapping profile has no valid content hash.');
        }
        $reportProfileHash = $this->reportProfileHash((string)$profile['content_hash']);
        $mappingModel = $model;
        $ct600aTax = round((float)($model['model']['ct600a']['tax_payable'] ?? 0), 2);
        $ordinaryTax = round((float)($model['model']['computation']['summary']['ordinary_corporation_tax'] ?? 0), 2);
        $mappingModel['facts']['return_position.ct600a_a80'] = $ct600aTax;
        $mappingModel['facts']['return_position.tax_payable'] = round($ordinaryTax + $ct600aTax, 2);
        // Preserve compatibility with immutable filing bases created under
        // the previous reviewed mapping profile.
        $mappingModel['facts']['computation.summary.s455_tax'] = $ct600aTax;
        $mappingModel['facts']['computation.summary.estimated_corporation_tax'] = round($ordinaryTax + $ct600aTax, 2);
        $mappingModel = $this->withSemanticCapitalAdjustmentFacts($mappingModel);
        $mappedFacts = (new CtFilingMappingService())->mapFrozenFacts(
            CtFilingMappingService::TARGET_COMPUTATION,
            $mappingModel,
            $profile
        );
        if (empty($mappedFacts['success'])) {
            return $this->failRun(
                $runId,
                (string)(($mappedFacts['errors'] ?? [])[0] ?? 'The frozen filing facts could not be mapped.')
            );
        }

        $generator = new IxbrlGeneratorService();
        $artifact = null;
        $evidenceArtifact = null;
        try {
            $evidenceArtifact = (new FilingEvidenceService())->reserveArtifact(
                $companyId,
                $accountingPeriodId,
                'computation_ixbrl',
                $ctPeriodId,
                ['computation_run_id' => $runId]
            );
            $validationResources = $catalogue->validationResources($package);
            $rendered = $this->renderMappedDocument(
                $generator,
                $mappingModel,
                (array)$mappedFacts['mappings'],
                (string)$validationResources['schema_ref'],
                (string)$evidenceArtifact['display_id']
            );
            $errors = $generator->validateStructure($rendered['xhtml'], [$rendered['schema_ref']]);
            if ($errors !== []) {
                throw new \RuntimeException(implode(' ', $errors));
            }
            $artifact = $generator->storeImmutableArtifact(
                $companyId,
                (string)$model['model']['identity']['company_number'],
                $accountingPeriodId,
                (int)($model['approval']['id'] ?? 0),
                $runId,
                IxbrlArtifactFilenameService::DESTINATION_HMRC_CT600,
                str_replace('-', '', (string)$run['period_start']),
                str_replace('-', '', (string)$run['period_end']),
                $rendered['xhtml']
            );
            $beforeExternalValidation?->__invoke();
            $external = (new IxbrlExternalValidationService())->validateArtifact(
                (string)$artifact['path'],
                [(string)$validationResources['package_archive']]
            );
            $externalStatus = (string)($external['status'] ?? 'error');
            $validatorVersion = trim((string)($external['version'] ?? ''));
            $validatedHash = strtolower(trim((string)($external['validated_sha256'] ?? '')));
            $fileable = $externalStatus === 'passed'
                && $validatorVersion !== ''
                && hash_equals((string)$artifact['sha256'], $validatedHash);
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_computation_runs SET
                   ixbrl_status = :ixbrl_status,
                   computation_taxonomy_package_id = :package_id,
                   computation_taxonomy_package_hash = :package_hash,
                   ixbrl_mapping_profile_id = :profile_id,
                   ixbrl_mapping_hash = :profile_hash,
                   filing_basis_version = :basis_version,
                   filing_basis_hash = :basis_hash,
                   generated_path = :path,
                   generated_filename = :filename,
                   taxonomy_profile = :taxonomy_profile,
                   validation_status = :validation_status,
                   validation_errors_json = :validation_errors,
                   external_validator = :external_validator,
                   external_validator_version = :external_validator_version,
                   external_validation_status = :external_status,
                   external_validation_errors_json = :external_errors,
                   external_validation_warnings_json = :external_warnings,
                   external_validation_log_path = :external_log,
                   external_validated_at = CURRENT_TIMESTAMP,
                   output_sha256 = :output_sha256,
                   external_validated_sha256 = :validated_sha256,
                   ixbrl_generated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    'ixbrl_status' => $fileable ? 'validated' : 'validation_failed',
                    'package_id' => (int)$package['id'],
                    'package_hash' => $packageHash,
                    'profile_id' => (int)$profile['id'],
                    'profile_hash' => $reportProfileHash,
                    'basis_version' => (string)$model['basis_version'],
                    'basis_hash' => (string)$model['basis_hash'],
                    'path' => $artifact['path'],
                    'filename' => $artifact['filename'],
                    'taxonomy_profile' => (string)$package['taxonomy_version'] . '/' . (string)$package['artifact_version'],
                    'validation_status' => 'passed',
                    'validation_errors' => \eel_accounts\Support\Utf8::json([], JSON_UNESCAPED_SLASHES),
                    'external_validator' => 'arelle',
                    'external_validator_version' => $validatorVersion !== '' ? $validatorVersion : null,
                    'external_status' => $externalStatus,
                    'external_errors' => \eel_accounts\Support\Utf8::json($this->externalDiagnosticsForStorage($external, 'error'), JSON_UNESCAPED_SLASHES),
                    'external_warnings' => \eel_accounts\Support\Utf8::json($this->externalDiagnosticsForStorage($external, 'warning'), JSON_UNESCAPED_SLASHES),
                    'external_log' => ($external['log_path'] ?? null) ?: null,
                    'output_sha256' => $artifact['sha256'],
                    'validated_sha256' => ($external['validated_sha256'] ?? null) ?: null,
                    'id' => $runId,
                ]
            );
            (new FilingEvidenceService())->completeArtifact((int)$evidenceArtifact['id'], [
                'status' => $fileable ? 'validated' : 'generated',
                'filename' => (string)$artifact['filename'],
                'path' => (string)$artifact['path'],
                'sha256' => (string)$artifact['sha256'],
                'schema_identity' => (string)$validationResources['schema_ref'],
                'schema_manifest_sha256' => $packageHash,
                'validator_name' => 'arelle',
                'validator_version' => $validatorVersion,
                'validation_status' => $externalStatus,
                'identifier_embedded' => true,
                'metadata' => ['computation_run_id' => $runId, 'mapping_hash' => $reportProfileHash],
            ]);
            return [
                'success' => $fileable,
                'errors' => $fileable ? [] : ((array)($external['errors'] ?? []) !== []
                    ? (array)$external['errors']
                    : ['Arelle validation did not return a complete validator identity and matching artifact hash.']),
                'warnings' => (array)($external['warnings'] ?? []),
                'filename' => $artifact['filename'],
                'path' => $artifact['path'],
                'sha256' => $artifact['sha256'],
                'run_id' => $runId,
                'evidence_artifact_id' => (string)$evidenceArtifact['display_id'],
            ];
        } catch (\Throwable $exception) {
            if (is_array($evidenceArtifact)) {
                (new FilingEvidenceService())->failArtifact((int)$evidenceArtifact['id'], $exception->getMessage());
            }
            if (is_array($artifact) && !empty($artifact['created'])) {
                $generator->removeManagedArtifact((string)$artifact['path'], $companyId);
            }
            return $this->failRun($runId, $exception->getMessage());
        }
    }

    public function status(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $model = (new CtPeriodFilingModelService())->build($companyId, $accountingPeriodId, $ctPeriodId);
        $errors = (array)($model['errors'] ?? []);
        $package = null;
        $profile = null;
        $run = is_array($model['run'] ?? null) ? (array)$model['run'] : [];
        if ($run !== []) {
            $package = (new HmrcCtComputationCatalogueService())->resolveForPeriod((string)$run['period_start'], (string)$run['period_end']);
            if (!is_array($package)) {
                $errors[] = 'No verified computation taxonomy applies to this CT period.';
            } else {
                if ((new HmrcCtComputationCatalogueService())->verifiedPackageHash($package) === null) {
                    $errors[] = 'The applicable computation taxonomy inventory is missing or has changed.';
                }
                $profile = (new CtFilingMappingService())->activeProfile(CtFilingMappingService::TARGET_COMPUTATION, (int)$package['id']);
                if (!is_array($profile)) {
                    $errors[] = 'No active compatible computation mapping profile applies.';
                }
            }
        }
        $stored = isset($run['run_id']) ? \InterfaceDB::fetchOne('SELECT * FROM corporation_tax_computation_runs WHERE id = :id', ['id' => (int)$run['run_id']]) : null;
        $artifactHash = is_array($stored)
            ? (new IxbrlArtifactFingerprintService())->sha256((string)($stored['generated_path'] ?? ''))
            : null;
        $artifactErrors = $this->artifactErrors($companyId, $accountingPeriodId, $ctPeriodId, $model, $package, $profile, $stored, false, $artifactHash);
        $fresh = $artifactErrors === [];
        $fileableErrors = $fresh
            ? $this->artifactErrors($companyId, $accountingPeriodId, $ctPeriodId, $model, $package, $profile, $stored, true, $artifactHash)
            : $artifactErrors;
        return [
            'ready' => $errors === [], 'errors' => array_values(array_unique($errors)),
            'artifact_errors' => $artifactErrors, 'fileable_errors' => $fileableErrors,
            'model' => $model, 'package' => $package, 'profile' => $profile,
            'run' => $stored, 'fresh' => $fresh, 'fileable' => $fileableErrors === [],
        ];
    }

    public function validateFilingExport(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $status = $this->status($companyId, $accountingPeriodId, $ctPeriodId);
        if (empty($status['fresh'])) {
            return ['success' => false, 'errors' => ['Generate a current CT-period iXBRL artifact before validation.']];
        }
        $run = (array)$status['run'];
        $path = (string)$run['generated_path'];
        $package = (array)$status['package'];
        try {
            $validationResources = (new HmrcCtComputationCatalogueService())->validationResources($package);
        } catch (\Throwable $exception) {
            return $this->failRun((int)$run['id'], $exception->getMessage());
        }
        $schemaRef = (string)$validationResources['schema_ref'];
        $xhtml = file_get_contents($path);
        $internalErrors = is_string($xhtml) ? (new IxbrlGeneratorService())->validateStructure($xhtml, [$schemaRef]) : ['The artifact could not be read.'];
        if ($internalErrors !== []) {
            return $this->failRun((int)$run['id'], implode(' ', $internalErrors));
        }
        $external = (new IxbrlExternalValidationService())->validateArtifact(
            $path,
            [(string)$validationResources['package_archive']]
        );
        $validatorVersion = trim((string)($external['version'] ?? ''));
        $validatedHash = strtolower(trim((string)($external['validated_sha256'] ?? '')));
        $passed = (string)($external['status'] ?? '') === 'passed'
            && $validatorVersion !== ''
            && hash_equals(strtolower((string)$run['output_sha256']), $validatedHash);
        \InterfaceDB::prepareExecute(
            'UPDATE corporation_tax_computation_runs SET ixbrl_status = :ixbrl_status, validation_status = :validation_status,
             validation_errors_json = :validation_errors, external_validator = :validator,
             external_validator_version = :validator_version,
             external_validation_status = :external_status, external_validation_errors_json = :external_errors,
             external_validation_warnings_json = :external_warnings, external_validation_log_path = :external_log,
             external_validated_at = CURRENT_TIMESTAMP, external_validated_sha256 = :validated_sha256 WHERE id = :id',
            ['ixbrl_status' => $passed ? 'validated' : 'validation_failed', 'validation_status' => 'passed', 'validation_errors' => \eel_accounts\Support\Utf8::json([], JSON_UNESCAPED_SLASHES), 'validator' => 'arelle', 'validator_version' => $validatorVersion !== '' ? $validatorVersion : null, 'external_status' => (string)($external['status'] ?? 'error'), 'external_errors' => \eel_accounts\Support\Utf8::json($this->externalDiagnosticsForStorage($external, 'error'), JSON_UNESCAPED_SLASHES), 'external_warnings' => \eel_accounts\Support\Utf8::json($this->externalDiagnosticsForStorage($external, 'warning'), JSON_UNESCAPED_SLASHES), 'external_log' => ($external['log_path'] ?? null) ?: null, 'validated_sha256' => ($external['validated_sha256'] ?? null) ?: null, 'id' => (int)$run['id']]
        );
        return ['success' => $passed, 'errors' => $passed ? [] : ((array)($external['errors'] ?? []) !== [] ? (array)$external['errors'] : ['Arelle validation did not return a complete validator identity and matching artifact hash.']), 'warnings' => (array)($external['warnings'] ?? [])];
    }

    /** @return list<mixed> */
    private function externalDiagnosticsForStorage(array $external, string $kind): array
    {
        $diagnostics = $external[$kind . '_diagnostics'] ?? null;
        if (is_array($diagnostics) && $diagnostics !== []) {
            return array_values($diagnostics);
        }

        $messages = $external[$kind === 'error' ? 'errors' : 'warnings'] ?? [];
        return is_array($messages) ? array_values($messages) : [];
    }

    private function renderMappedDocument(
        IxbrlGeneratorService $generator,
        array $model,
        array $mappings,
        string $schemaRef,
        string $evidenceArtifactId = ''
    ): array
    {
        $run = (array)$model['run'];
        $report = $this->buildReportModel($model, $mappings);
        $contexts = [];
        $facts = [];
        $namespaces = [];
        foreach ((array)$report['mappings'] as $mapping) {
            if (!array_key_exists('source_value', $mapping)) {
                throw new \RuntimeException('A computation mapping was not resolved from the frozen filing model.');
            }
            $value = $mapping['source_value'];
            $concept = (string)$mapping['taxonomy_concept'];
            [$prefix] = explode(':', $concept, 2);
            $contextProfile = $this->contextProfile($mapping);
            $contextDefinition = $this->contextDefinition($contextProfile, $prefix, $model);
            $mappedDimensions = json_decode((string)($mapping['dimensions_json'] ?? ''), true);
            $mappedDimensions = is_array($mappedDimensions) ? $mappedDimensions : [];
            foreach ($mappedDimensions as $dimension => $member) {
                if (!is_string($dimension) || !is_string($member)) {
                    throw new \RuntimeException('A computation mapping contains invalid explicit dimensions.');
                }
                if (isset($contextDefinition['dimensions'][$dimension])
                    && (string)$contextDefinition['dimensions'][$dimension] !== $member) {
                    throw new \RuntimeException('A computation mapping cannot override a reviewed HMRC context dimension.');
                }
                $contextDefinition['dimensions'][$dimension] = $member;
            }
            $contextPeriod = $this->contextPeriod($mapping, $model);
            $contextId = 'ct_' . substr(hash('sha256', (string)$mapping['period_type'] . '|'
                . (string)\eel_accounts\Support\Utf8::json($contextDefinition, JSON_UNESCAPED_SLASHES) . '|'
                . (string)$contextPeriod['start_date'] . '|' . (string)$contextPeriod['end_date']), 0, 12);
            if (!isset($contexts[$contextId])) {
                $contexts[$contextId] = [
                    'id' => $contextId,
                    'identifier' => (string)$model['model']['identity']['company_number'],
                    'start_date' => (string)$contextPeriod['start_date'],
                    'end_date' => (string)$contextPeriod['end_date'],
                ] + $contextDefinition;
                if ((string)$mapping['period_type'] === 'instant') {
                    unset($contexts[$contextId]['start_date'], $contexts[$contextId]['end_date']);
                    $contexts[$contextId]['instant'] = (string)$contextPeriod['end_date'];
                }
            }
            $namespaces[$prefix] = (string)$mapping['namespace_uri'];
            $numeric = in_array((string)$mapping['value_type'], ['numeric', 'integer'], true);
            if ($numeric && $value !== null) {
                $value = (float)$value * (float)$mapping['sign_multiplier'];
            }
            $canonicalKey = (string)$mapping['canonical_key'];
            if (isset($facts[$canonicalKey])) {
                throw new \RuntimeException('A supported computation fact is mapped more than once: ' . $canonicalKey . '.');
            }
            $fact = [
                'qname' => $concept,
                'context_ref' => $contextId,
                'value' => $value === '' ? null : $value,
                'numeric' => $numeric,
                'unit_ref' => ($mapping['unit_ref'] ?? null) ?: 'GBP',
                'decimals' => ($mapping['decimals_value'] ?? null) ?: ($numeric ? '2' : '0'),
            ];
            $valueType = (string)$mapping['value_type'];
            if ($value !== null && $value !== '') {
                if ($numeric) {
                    $fact['format'] = 'ixt:numdotdecimal';
                    $fact['display_value'] = number_format(
                        abs((float)$value),
                        max(0, (int)$fact['decimals']),
                        '.',
                        ','
                    );
                } elseif ($valueType === 'date') {
                    $fact['format'] = 'ixt:datedaymonthyearen';
                    $fact['display_value'] = $this->longDate((string)$value);
                }
            }
            $facts[$canonicalKey] = [
                'canonical_key' => $canonicalKey,
                'label' => $this->presentationLabel($canonicalKey, $value),
                'value' => $value,
                'numeric' => $numeric,
                'html' => $generator->renderFact($fact),
            ];
        }
        if ($facts === []) {
            throw new \RuntimeException('The active profile produced no Inline XBRL facts.');
        }
        $body = $this->renderReportBody($generator, $model, $report, $facts);
        if (!str_starts_with($schemaRef, 'http://www.hmrc.gov.uk/')) {
            throw new \RuntimeException('The verified HMRC computation-taxonomy schema reference is invalid.');
        }
        return ['schema_ref' => $schemaRef, 'xhtml' => $generator->renderDocument([
            'title' => (string)$report['document_title'],
            'namespaces' => ['ixt' => 'http://www.xbrl.org/inlineXBRL/transformation/2015-02-26'] + $namespaces,
            'schema_refs' => [$schemaRef],
            'contexts' => array_values($contexts),
            'units' => [['id' => 'GBP', 'measure' => 'iso4217:GBP']],
            'metadata' => $evidenceArtifactId !== '' ? ['eel-evidence-artifact-id' => $evidenceArtifactId] : [],
            'stylesheet' => $this->stylesheet(),
            'body' => $body,
        ])];
    }

    private function renderReportBody(
        IxbrlGeneratorService $generator,
        array $filing,
        array $report,
        array $facts
    ): string {
        $model = (array)$filing['model'];
        $summary = (array)$model['computation']['summary'];
        $identity = (array)$model['identity'];
        $accountingPeriod = (array)$model['accounting_period'];
        $allocation = (array)($summary['accounting_allocation_basis'] ?? []);
        $html = '<div class="ct-report"><div class="ct-header keep-together">'
            . '<h1>' . $generator->escape((string)$identity['company_name']) . '</h1>'
            . '<p class="report-title">Corporation Tax computation for the period ended '
            . $generator->escape((string)$report['period_end_display']) . '</p>'
            . '<p class="report-subtitle">For the period ' . $generator->escape((string)$report['period_start_display'])
            . ' to ' . $generator->escape((string)$report['period_end_display']) . '</p></div>';

        $html .= '<div class="ct-section identity-section keep-together"><h2>Company and period details</h2>'
            . '<table class="identity-table"><tbody>'
            . $this->textRow($generator, 'Company', $this->factHtml($facts, 'identity.company_name'))
            . $this->textRow($generator, 'Company number', $generator->escape((string)$identity['company_number']))
            . $this->textRow($generator, 'Unique Taxpayer Reference', $this->factHtml($facts, 'filing_identity.utr'))
            . $this->textRow($generator, 'Accounting framework', $generator->escape((string)$report['framework_label']))
            . $this->textRow(
                $generator,
                'CT period',
                $this->factHtml($facts, 'ct_period.start_date') . ' to ' . $this->factHtml($facts, 'ct_period.end_date')
            )
            . '</tbody></table></div>';

        if (!empty($allocation['time_apportioned'])) {
            $accountingDays = (int)($allocation['accounting_period_days'] ?? 0);
            $ctDays = (int)($allocation['ct_period_days'] ?? 0);
            if ($accountingDays <= 0 || $ctDays <= 0 || $ctDays > $accountingDays) {
                throw new \RuntimeException('The frozen accounting-period apportionment has invalid inclusive day counts.');
            }
            $html .= '<div class="ct-section apportionment-section keep-together"><h2>Accounting-period apportionment</h2>'
                . '<p>The statutory accounting period from '
                . $generator->escape($this->longDate((string)$accountingPeriod['start_date'])) . ' to '
                . $generator->escape($this->longDate((string)$accountingPeriod['end_date'])) . ' spans '
                . $accountingDays . ' days and is divided into '
                . (int)($allocation['ct_period_count'] ?? 1) . ' Corporation Tax accounting periods. '
                . 'This computation covers ' . $ctDays . ' of those ' . $accountingDays . ' days.</p>'
                . '<table><tbody>'
                . $this->textRow($generator, 'Apportionment fraction', $ctDays . ' / ' . $accountingDays . ' days')
                . '</tbody></table></div>';
        }

        $capitalAllowances = $this->money($summary, 'capital_allowances');
        $taxableBeforeLosses = $this->money($summary, 'taxable_before_losses');
        $adjusted = $taxableBeforeLosses + $capitalAllowances;
        $allocatedValues = (array)($allocation['allocated_values'] ?? []);
        if (isset($allocatedValues['adjusted_result_before_capital_allowances'])
            && is_numeric($allocatedValues['adjusted_result_before_capital_allowances'])) {
            $adjusted = round((float)$allocatedValues['adjusted_result_before_capital_allowances'], 2);
        }
        if (abs($adjusted - round($taxableBeforeLosses + $capitalAllowances, 2)) > 0.009) {
            throw new \RuntimeException('The frozen adjusted result does not reconcile to capital allowances and the trading result.');
        }
        $profileRows = (array)($report['accounts_adjustment_rows'] ?? []);
        if ($profileRows !== []) {
            $tradingRows = $this->reportMoneyRows($generator, $facts, $profileRows);
        } else {
            $tradingRows = $this->factMoneyRow($generator, $facts, 'computation.summary.accounting_profit')
                . $this->factMoneyRow($generator, $facts, 'computation.summary.disallowable_add_backs')
                . $this->factMoneyRow($generator, $facts, 'computation.summary.capital_add_backs')
                . $this->factMoneyRow($generator, $facts, 'computation.summary.depreciation_add_back');
        }
        if ($profileRows === []) {
            $roundingAdjustment = round((float)($allocation['apportionment_rounding_adjustment'] ?? 0), 2);
            if (abs($roundingAdjustment) >= 0.005) {
                $tradingRows .= $this->moneyRow(
                    $generator,
                    'Apportionment rounding adjustment',
                    $roundingAdjustment
                );
            }
            $tradingRows .= $this->moneyRow(
                $generator,
                'Adjusted profit or loss before capital allowances',
                $adjusted,
                false,
                'subtotal'
            )
                . $this->factMoneyRow(
                    $generator,
                    $facts,
                    'computation.summary.capital_allowances',
                    true
                )
                . $this->factMoneyRow(
                    $generator,
                    $facts,
                    'computation.summary.taxable_before_losses',
                    false,
                    'final-total'
                );
        }
        $html .= '<div class="ct-section trading-section"><h2>Trading profit or loss computation</h2>'
            . '<table class="financial-table"><thead><tr><th scope="col">Trading computation</th>'
            . '<th scope="col" class="amount">£</th></tr></thead><tbody>' . $tradingRows . '</tbody></table></div>';

        $html .= $this->renderAiaSchedule($generator, $model);
        $html .= $this->renderMainPoolSchedule($generator, $report, $facts);

        $lossRestriction = $this->lossRestrictionForReport($summary, (array)($model['ct_period'] ?? []));
        $postLosses = (array)$lossRestriction['post_2017_trading_losses'];
        $preLosses = (array)$lossRestriction['pre_2017_trading_losses'];
        foreach ([$postLosses, $preLosses] as $movement) {
            if (abs(round((float)$movement['brought_forward'] + (float)$movement['arising'] - (float)$movement['used'], 2)
                - (float)$movement['carried_forward']) > 0.009) {
                throw new \RuntimeException('The frozen trading-loss category movement does not reconcile.');
            }
        }
        $postBroughtForward = isset($facts['computation.summary.loss_restriction.post_2017_trading_losses.brought_forward'])
            ? $this->factMoneyHtml($generator, $facts, 'computation.summary.loss_restriction.post_2017_trading_losses.brought_forward')
            : (isset($facts['computation.summary.losses_brought_forward'])
                ? $this->factMoneyHtml($generator, $facts, 'computation.summary.losses_brought_forward')
                : $this->moneyHtml($generator, (float)$postLosses['brought_forward']));
        $postUsed = isset($facts['computation.summary.loss_restriction.post_2017_trading_losses.used'])
            ? $this->factMoneyHtml($generator, $facts, 'computation.summary.loss_restriction.post_2017_trading_losses.used')
            : (isset($facts['computation.summary.losses_used'])
                ? $this->factMoneyHtml($generator, $facts, 'computation.summary.losses_used')
                : $this->moneyHtml($generator, (float)$postLosses['used']));
        $postCarriedForward = isset($facts['computation.summary.loss_restriction.post_2017_trading_losses.carried_forward'])
            ? $this->factMoneyHtml($generator, $facts, 'computation.summary.loss_restriction.post_2017_trading_losses.carried_forward')
            : $this->moneyHtml($generator, (float)$postLosses['carried_forward']);
        $postArising = isset($facts['report.loss.post_2017_trading_loss_arising'])
            ? $this->factMoneyHtml($generator, $facts, 'report.loss.post_2017_trading_loss_arising')
            : $this->moneyHtml($generator, (float)$postLosses['arising']);
        $html .= '<div class="ct-section loss-section keep-together"><h2>Trading losses</h2>'
            . '<p>Post-1 April 2017 trading losses are available for relief against total profits.</p>'
            . '<table class="financial-table"><thead><tr><th scope="col">Loss category</th>'
            . '<th scope="col" class="amount">Brought forward £</th><th scope="col" class="amount">Arising £</th>'
            . '<th scope="col" class="amount">Used £</th><th scope="col" class="amount">Carried forward £</th></tr></thead><tbody>'
            . $this->lossMovementRow($generator, 'Post-1 April 2017 trading losses', $postBroughtForward, $postArising, $postUsed, $postCarriedForward)
            . $this->lossMovementRow($generator, 'Pre-1 April 2017 trading losses', $this->moneyHtml($generator, (float)$preLosses['brought_forward']), $this->moneyHtml($generator, (float)$preLosses['arising']), $this->moneyHtml($generator, (float)$preLosses['used']), $this->moneyHtml($generator, (float)$preLosses['carried_forward']))
            . '</tbody></table>'
            . $this->renderDeductionsAllowance($generator, $facts, $lossRestriction)
            . '</div>';

        $participatorKey = isset($facts['return_position.ct600a_a80'])
            ? 'return_position.ct600a_a80'
            : 'computation.summary.s455_tax';
        $payableKey = isset($facts['return_position.tax_payable'])
            ? 'return_position.tax_payable'
            : 'computation.summary.estimated_corporation_tax';
        $taxRows = $this->factMoneyRow($generator, $facts, 'computation.summary.taxable_profit')
            . $this->factMoneyRow($generator, $facts, 'computation.summary.ordinary_corporation_tax')
            . $this->factMoneyRow($generator, $facts, $participatorKey)
            . $this->factMoneyRow($generator, $facts, $payableKey, false, 'final-total');
        $html .= '<div class="ct-section liability-section keep-together"><h2>Tax liability</h2>'
            . '<table class="financial-table"><thead><tr><th scope="col">Tax liability</th>'
            . '<th scope="col" class="amount">£</th></tr></thead><tbody>' . $taxRows . '</tbody></table></div>'
            . $this->renderSection455Narrative($generator, (array)($model['ct600a'] ?? []))
            . $this->renderSupportingSchedules($generator, $filing)
            . '</div>';
        return $html;
    }

    private function renderSection455Narrative(IxbrlGeneratorService $generator, array $ct600a): string
    {
        if ((string)($ct600a['section_455_narrative'] ?? '') !== 'repaid_within_period') {
            return '';
        }
        return '<div class="ct-section s455-narrative-section keep-together"><h2>Section 455</h2>'
            . '<p>' . $generator->escape(
                'Repaid within the accounting period; no amount reportable and no Section 455 tax payable.'
            ) . '</p></div>';
    }

    /** @return array<string,mixed> */
    private function lossRestrictionForReport(array $summary, array $ctPeriod): array
    {
        $stored = (array)($summary['loss_restriction'] ?? []);
        if (isset($stored['post_2017_trading_losses'], $stored['pre_2017_trading_losses'], $stored['deduction_allowance'])) {
            return $stored;
        }

        $start = (string)($ctPeriod['start_date'] ?? '');
        $end = (string)($ctPeriod['end_date'] ?? '');
        if ($start === '' || $end === '') {
            throw new \RuntimeException('The frozen loss disclosure has no CT-period dates.');
        }
        $days = (int)(new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1;
        $postReform = $start >= '2017-04-01';
        $broughtForward = $this->money($summary, 'losses_brought_forward');
        $arising = $this->money($summary, 'loss_created_in_period');
        $used = $this->money($summary, 'losses_used');
        $carriedForward = $this->money($summary, 'losses_carried_forward');
        $movement = static fn(float $bf, float $created, float $utilised, float $cf): array => [
            'brought_forward' => $bf, 'arising' => $created, 'used' => $utilised, 'carried_forward' => $cf,
        ];
        $allowance = $postReform ? round(5000000.00 * $days / 365, 2) : 0.00;
        return [
            'post_2017_trading_losses' => $postReform
                ? $movement($broughtForward, $arising, $used, $carriedForward)
                : $movement(0, 0, 0, 0),
            'pre_2017_trading_losses' => $postReform
                ? $movement(0, 0, 0, 0)
                : $movement($broughtForward, $arising, $used, $carriedForward),
            'post_2017_relief_basis' => $postReform ? 'trading_loss_available_against_total_profits' : 'not_applicable',
            'deduction_allowance' => ['basis' => 'non_group', 'period_days' => $days, 'days_in_year' => 365, 'amount' => $allowance],
            'qualifying_profits' => round(max(0.0, $this->money($summary, 'taxable_before_losses')), 2),
            'carried_forward_loss_relief_claimed' => $used,
            'calculated_loss_restriction' => 0.00,
            'loss_restriction' => 'none',
        ];
    }

    private function lossMovementRow(
        IxbrlGeneratorService $generator,
        string $label,
        string $broughtForwardHtml,
        string $arisingHtml,
        string $usedHtml,
        string $carriedForwardHtml
    ): string {
        return '<tr><th scope="row">' . $generator->escape($label) . '</th><td class="amount">'
            . $broughtForwardHtml . '</td><td class="amount">' . $arisingHtml
            . '</td><td class="amount">' . $usedHtml . '</td><td class="amount">'
            . $carriedForwardHtml . '</td></tr>';
    }

    private function renderDeductionsAllowance(IxbrlGeneratorService $generator, array $facts, array $lossRestriction): string
    {
        if (!$this->deductionsAllowanceIsRelevant($lossRestriction)) {
            return '';
        }
        $allowance = (array)($lossRestriction['deduction_allowance'] ?? []);
        $allowanceAmount = round((float)($allowance['amount'] ?? 0), 2);
        $allowanceHtml = isset($facts['computation.summary.loss_restriction.deduction_allowance.amount'])
            ? $this->factMoneyHtml($generator, $facts, 'computation.summary.loss_restriction.deduction_allowance.amount')
            : $this->moneyHtml($generator, $allowanceAmount);
        $restriction = round((float)($lossRestriction['calculated_loss_restriction'] ?? 0), 2);
        $restrictionHtml = isset($facts['computation.summary.loss_restriction.calculated_loss_restriction'])
            ? $this->factMoneyHtml($generator, $facts, 'computation.summary.loss_restriction.calculated_loss_restriction')
            : $this->moneyHtml($generator, $restriction);
        $qualifyingProfits = round((float)($lossRestriction['qualifying_profits'] ?? 0), 2);
        $qualifyingProfitsHtml = isset($facts['report.loss.qualifying_profits'])
            ? $this->factMoneyHtml($generator, $facts, 'report.loss.qualifying_profits')
            : $this->moneyHtml($generator, $qualifyingProfits);
        $reliefClaimed = round((float)($lossRestriction['carried_forward_loss_relief_claimed'] ?? 0), 2);
        $reliefClaimedHtml = isset($facts['report.loss.carried_forward_relief_claimed'])
            ? $this->factMoneyHtml($generator, $facts, 'report.loss.carried_forward_relief_claimed')
            : $this->moneyHtml($generator, $reliefClaimed);
        $days = (int)($allowance['period_days'] ?? 0);
        $daysInYear = (int)($allowance['days_in_year'] ?? 365);
        if ($days <= 0 || $daysInYear <= 0) {
            throw new \RuntimeException('The frozen deductions allowance has invalid period days.');
        }
        $restrictionText = $restriction < 0.005 ? 'None' : $this->moneyHtml($generator, $restriction, false, '£');
        return '<div class="deductions-allowance keep-together"><h3>Deductions allowance</h3>'
            . '<p>Non-group deductions allowance, apportioned for the ' . $days . '-day CT period ('
            . $days . ' / ' . $daysInYear . ' of £5,000,000).</p><table class="financial-table"><tbody>'
            . '<tr><th scope="row">Non-group deductions allowance for the period</th><td class="amount">'
            . $allowanceHtml . '</td></tr><tr><th scope="row">Qualifying profits</th><td class="amount">'
            . $qualifyingProfitsHtml
            . '</td></tr><tr><th scope="row">Carried-forward loss relief claimed against total profits</th><td class="amount">'
            . $reliefClaimedHtml
            . '</td></tr><tr><th scope="row">Calculated loss restriction</th><td class="amount">'
            . $restrictionHtml . '</td></tr><tr class="final-total"><th scope="row">Loss restriction</th><td class="amount">'
            . ($restrictionText === 'None' ? $generator->escape($restrictionText) : $restrictionText) . '</td></tr></tbody></table></div>';
    }

    /**
     * The allowance facts remain part of the canonical CT model, but the
     * explanatory table is useful only when a brought-forward loss claim or
     * a restriction calculation actually affects the period.
     */
    private function deductionsAllowanceIsRelevant(array $lossRestriction): bool
    {
        $post2017 = (array)($lossRestriction['post_2017_trading_losses'] ?? []);
        return abs((float)($post2017['used'] ?? 0)) >= 0.005
            || abs((float)($lossRestriction['carried_forward_loss_relief_claimed'] ?? 0)) >= 0.005
            || abs((float)($lossRestriction['calculated_loss_restriction'] ?? 0)) >= 0.005
            || !in_array((string)($lossRestriction['loss_restriction'] ?? 'none'), ['', 'none', 'not_applicable'], true);
    }

    private function renderAiaSchedule(IxbrlGeneratorService $generator, array $model): string
    {
        $summary = (array)$model['computation']['summary'];
        $breakdown = (array)($summary['capital_allowance_breakdown'] ?? []);
        $calculations = array_values(array_filter(
            (array)($breakdown['asset_calculations'] ?? []),
            static fn(mixed $row): bool => is_array($row)
                && (string)($row['allowance_type'] ?? '') === 'aia'
                && (float)($row['allowance_amount'] ?? 0) >= 0.005
        ));
        $expected = round((float)($model['filing_decisions']['aia_claimed_in_trade'] ?? 0), 2);
        if ($calculations === []) {
            if ($expected >= 0.005) {
                throw new \RuntimeException('The frozen AIA claim has no asset-level calculation rows.');
            }
            return '';
        }
        $auditRows = array_values((array)($model['audit']['capital_allowances']['rows'] ?? []));
        $usedAuditRows = [];
        $rows = '';
        $expenditureTotal = 0.0;
        $claimTotal = 0.0;
        foreach ($calculations as $calculation) {
            $assetId = (int)($calculation['asset_id'] ?? 0);
            $matches = [];
            foreach ($auditRows as $index => $auditRow) {
                if (is_array($auditRow)
                    && (int)(($auditRow['metadata'] ?? [])['asset_id'] ?? 0) === $assetId) {
                    $matches[$index] = $auditRow;
                }
            }
            $componentMatches = array_filter(
                $matches,
                static fn(array $auditRow): bool =>
                    (string)(($auditRow['metadata'] ?? [])['audit_component'] ?? '') === 'aia_allocation'
            );
            if ($componentMatches !== []) {
                $matches = $componentMatches;
            } else {
                $typedMatches = array_filter(
                    $matches,
                    static fn(array $auditRow): bool =>
                        strtolower(trim((string)(($auditRow['metadata'] ?? [])['allowance_type'] ?? ''))) === 'aia'
                );
                if ($typedMatches !== []) {
                    $matches = $typedMatches;
                }
            }
            if ($assetId <= 0 || count($matches) !== 1) {
                throw new \RuntimeException('A frozen AIA calculation row cannot be reconciled uniquely to approved audit evidence.');
            }
            $auditIndex = (int)array_key_first($matches);
            if (isset($usedAuditRows[$auditIndex])) {
                throw new \RuntimeException('A frozen AIA audit row is linked to more than one calculation row.');
            }
            $usedAuditRows[$auditIndex] = true;
            $audit = (array)$matches[$auditIndex];
            $metadata = (array)($audit['metadata'] ?? []);
            $description = trim((string)($metadata['description'] ?? ''));
            $purchaseDate = trim((string)($metadata['purchase_date'] ?? $audit['source_date'] ?? ''));
            $addition = round((float)($calculation['addition_amount'] ?? 0), 2);
            $claim = round((float)($calculation['allowance_amount'] ?? 0), 2);
            if ($description === '' || $purchaseDate === '') {
                throw new \RuntimeException('A frozen AIA audit row has no approved description or qualifying expenditure date.');
            }
            $this->longDate($purchaseDate);
            foreach ([
                [(float)($metadata['addition_amount'] ?? -1), $addition],
                [(float)($metadata['allowance_amount'] ?? -1), $claim],
                [(float)($audit['tax_adjustment_amount'] ?? -1), $claim],
            ] as [$frozenAmount, $calculationAmount]) {
                if (abs(round((float)$frozenAmount, 2) - $calculationAmount) > 0.009) {
                    throw new \RuntimeException('A frozen AIA calculation amount does not agree to approved audit evidence.');
                }
            }
            $expenditureTotal += $addition;
            $claimTotal += $claim;
            $rows .= '<tr><td>' . $generator->escape($description) . '</td><td>'
                . $generator->escape($this->longDate($purchaseDate)) . '</td><td class="amount">'
                . $this->moneyHtml($generator, $addition) . '</td><td class="amount">'
                . $this->moneyHtml($generator, $claim) . '</td></tr>';
        }
        $claimTotal = round($claimTotal, 2);
        if (abs($claimTotal - $expected) > 0.009) {
            throw new \RuntimeException('The asset-level AIA schedule does not reconcile to the frozen CT600 AIA claim.');
        }
        return '<div class="ct-section aia-section"><h2>Annual Investment Allowance schedule</h2>'
            . '<table class="financial-table aia-table"><thead><tr><th scope="col">Asset description</th>'
            . '<th scope="col">Qualifying expenditure date</th><th scope="col" class="amount">Expenditure (£)</th>'
            . '<th scope="col" class="amount">AIA claimed (£)</th></tr></thead><tbody>' . $rows
            . '<tr class="final-total"><th scope="row" colspan="2">Total</th><td class="amount">'
            . $this->moneyHtml($generator, round($expenditureTotal, 2))
            . '</td><td class="amount">' . $this->moneyHtml($generator, $claimTotal)
            . '</td></tr></tbody></table></div>';
    }

    /** @param array<string,mixed> $report */
    private function renderMainPoolSchedule(IxbrlGeneratorService $generator, array $report, array $facts): string
    {
        $rows = (array)($report['main_pool_rows'] ?? []);
        if ($rows === []) {
            return '';
        }
        $body = '';
        foreach ($rows as $row) {
            $label = trim((string)($row['label'] ?? ''));
            $factKey = trim((string)($row['fact_key'] ?? ''));
            $class = trim((string)($row['class'] ?? ''));
            if ($label === '') {
                throw new \RuntimeException('The main-pool report model contains a row without a label.');
            }
            if ($factKey !== '') {
                if (!isset($facts[$factKey])) {
                    throw new \RuntimeException('The main-pool report model refers to an unavailable fact ' . $factKey . '.');
                }
                $value = $this->factMoneyHtml(
                    $generator,
                    $facts,
                    $factKey,
                    (string)($row['direction'] ?? '') === 'deduction'
                );
            } elseif ((string)($row['display_type'] ?? 'money') === 'percent') {
                if (!is_numeric($row['amount'] ?? null)) {
                    throw new \RuntimeException('The main-pool percentage row has no amount.');
                }
                $value = $generator->escape(number_format((float)$row['amount'] * 100, 2, '.', ',') . '%');
            } elseif (is_numeric($row['amount'] ?? null)) {
                $value = $this->moneyHtml($generator, round((float)$row['amount'], 2));
            } else {
                throw new \RuntimeException('The main-pool report model has an untagged row without an amount.');
            }
            $body .= '<tr' . ($class !== '' ? ' class="' . $generator->escape($class) . '"' : '') . '><th scope="row">'
                . $generator->escape($label) . '</th><td class="amount">' . $value . '</td></tr>';
        }
        return '<div class="ct-section main-pool-section keep-together"><h2>Main pool</h2>'
            . '<table class="financial-table"><thead><tr><th scope="col">Plant and machinery main pool</th>'
            . '<th scope="col" class="amount">£</th></tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    private function renderSupportingSchedules(IxbrlGeneratorService $generator, array $filing): string
    {
        $model = (array)($filing['model'] ?? []);
        $html = '';
        $ct600a = (array)($model['ct600a'] ?? []);
        if (!empty($ct600a['required'])) {
            $html .= '<div class="ct-section ct600a-section"><h2>CT600A loans and arrangements schedule</h2>';
            $html .= $this->supportingTable($generator, 'Part 1 — loans and benefits', (array)($ct600a['part1']['rows'] ?? []), 'amount');
            $html .= $this->supportingTable($generator, 'Part 2 — relief within nine months', (array)($ct600a['part2']['rows'] ?? []), null);
            $html .= $this->supportingTable($generator, 'Part 3 — relief due now', (array)($ct600a['part3']['rows'] ?? []), null);
            $html .= '<table class="financial-table"><tbody><tr><th scope="row">A75 total outstanding</th><td class="amount">'
                . $this->moneyHtml($generator, (float)($ct600a['total_loans_outstanding'] ?? 0))
                . '</td></tr><tr class="final-total"><th scope="row">A80 tax payable</th><td class="amount">'
                . $this->moneyHtml($generator, (float)($ct600a['tax_payable'] ?? 0))
                . '</td></tr></tbody></table></div>';
        }
        return $html;
    }

    private function supportingTable(IxbrlGeneratorService $generator, string $title, array $rows, ?string $amountKey): string
    {
        if ($rows === []) { return ''; }
        $body = '';
        foreach ($rows as $row) {
            $amount = $amountKey !== null ? (float)($row[$amountKey] ?? 0)
                : (float)($row['amount_repaid'] ?? 0) + (float)($row['amount_released_or_written_off'] ?? 0);
            $date = trim((string)($row['date'] ?? ''));
            $displayDate = $date !== '' ? $this->longDate($date) : '';
            $body .= '<tr><td>' . $generator->escape((string)($row['name'] ?? 'Participator')) . '</td><td>'
                . $generator->escape($displayDate) . '</td><td class="amount">'
                . $this->moneyHtml($generator, $amount) . '</td></tr>';
        }
        return '<div class="keep-together"><h3>' . $generator->escape($title)
            . '</h3><table class="financial-table"><thead><tr><th scope="col">Participator or associate</th>'
            . '<th scope="col">Date</th><th scope="col" class="amount">Amount (£)</th></tr></thead><tbody>'
            . $body . '</tbody></table></div>';
    }

    private function presentationLabel(string $canonicalKey, mixed $value = null): string
    {
        if ($canonicalKey === 'computation.summary.disposal_profit_or_loss_adjustment') {
            return (float)$value < 0
                ? 'Profit on disposal of fixed assets deducted'
                : 'Loss on disposal of fixed assets added back';
        }
        $label = self::PRESENTATION_LABELS[$canonicalKey] ?? '';
        if ($label === '') {
            throw new \RuntimeException(
                'The supported computation fact has no recognised human-readable label: ' . $canonicalKey . '.'
            );
        }
        return $label;
    }

    /**
     * Derive presentation-only semantic components from the verified frozen
     * basis. Pre-split bases retain audited 6210/4200 source rows, allowing a
     * regenerated computation to use the correct taxonomy without changing
     * its calculation or approval hashes.
     */
    private function withSemanticCapitalAdjustmentFacts(array $filing): array
    {
        $summary = (array)($filing['model']['computation']['summary'] ?? []);
        $total = round((float)($summary['capital_add_backs'] ?? 0), 2);
        $hasSplit = array_key_exists('capital_expenditure_add_backs', $summary)
            || array_key_exists('disposal_profit_or_loss_adjustment', $summary);
        $disposal = $hasSplit
            ? round((float)($summary['disposal_profit_or_loss_adjustment'] ?? 0), 2)
            : 0.0;
        if (!$hasSplit) {
            foreach ((array)($filing['model']['audit']['depreciation_capital']['rows'] ?? []) as $row) {
                $code = trim((string)($row['nominal_code'] ?? $row['metadata']['nominal_code'] ?? ''));
                if (!in_array($code, ['6210', '4200'], true)) {
                    continue;
                }
                $disposal = round($disposal + (float)($row['tax_adjustment_amount'] ?? 0), 2);
            }
        }
        $capitalExpenditure = $hasSplit
            ? round((float)($summary['capital_expenditure_add_backs'] ?? ($total - $disposal)), 2)
            : round($total - $disposal, 2);

        $filing['model']['computation']['summary']['capital_expenditure_add_backs'] = $capitalExpenditure;
        $filing['model']['computation']['summary']['disposal_profit_or_loss_adjustment'] = $disposal;
        $filing['facts']['computation.summary.capital_expenditure_add_backs'] = $capitalExpenditure;
        $filing['facts']['computation.summary.disposal_profit_or_loss_adjustment'] = $disposal;

        return $filing;
    }

    private function factHtml(array $facts, string $canonicalKey): string
    {
        if (!isset($facts[$canonicalKey])) {
            throw new \RuntimeException('The computation report is missing required fact ' . $canonicalKey . '.');
        }
        return (string)$facts[$canonicalKey]['html'];
    }

    /**
     * Formats a visible CT monetary amount without altering a nested Inline
     * XBRL fact. Parentheses are actual XHTML text, so copied, non-CSS and
     * assistive-technology views retain the negative presentation.
     */
    private function monetaryDisplayHtml(
        IxbrlGeneratorService $generator,
        float $amount,
        ?string $factHtml = null,
        bool $deduction = false,
        string $currencyPrefix = ''
    ): string {
        $amount = round($amount, 2);
        $display = $factHtml ?? $generator->escape(number_format(abs($amount), 2, '.', ','));
        if ($currencyPrefix !== '') {
            $display = $generator->escape($currencyPrefix) . $display;
        }
        if ($amount < -0.004 || ($deduction && $amount > 0.004)) {
            return '<span class="accounting-negative">(' . $display . ')</span>';
        }
        return $display;
    }

    private function moneyHtml(
        IxbrlGeneratorService $generator,
        float $amount,
        bool $deduction = false,
        string $currencyPrefix = ''
    ): string {
        return $this->monetaryDisplayHtml($generator, $amount, null, $deduction, $currencyPrefix);
    }

    private function factMoneyHtml(
        IxbrlGeneratorService $generator,
        array $facts,
        string $canonicalKey,
        bool $deduction = false
    ): string {
        if (!isset($facts[$canonicalKey]) || empty($facts[$canonicalKey]['numeric'])) {
            throw new \RuntimeException('The computation report is missing required monetary fact ' . $canonicalKey . '.');
        }
        $fact = (array)$facts[$canonicalKey];
        return $this->monetaryDisplayHtml($generator, (float)$fact['value'], (string)$fact['html'], $deduction);
    }

    private function factMoneyRow(
        IxbrlGeneratorService $generator,
        array $facts,
        string $canonicalKey,
        bool $deduction = false,
        string $class = ''
    ): string {
        if (!isset($facts[$canonicalKey]) || empty($facts[$canonicalKey]['numeric'])) {
            throw new \RuntimeException('The computation report is missing required monetary fact ' . $canonicalKey . '.');
        }
        $fact = (array)$facts[$canonicalKey];
        $display = $this->factMoneyHtml($generator, $facts, $canonicalKey, $deduction);
        return '<tr' . ($class !== '' ? ' class="' . $generator->escape($class) . '"' : '') . '><th scope="row">'
            . $generator->escape((string)$fact['label']) . '</th><td class="amount">' . $display . '</td></tr>';
    }

    /** @param list<array<string,mixed>> $rows */
    private function reportMoneyRows(IxbrlGeneratorService $generator, array $facts, array $rows): string
    {
        $html = '';
        foreach ($rows as $row) {
            $label = trim((string)($row['label'] ?? ''));
            $class = trim((string)($row['class'] ?? ''));
            $factKey = trim((string)($row['fact_key'] ?? ''));
            if ($label === '') {
                throw new \RuntimeException('The computation report profile contains a row without a visible label.');
            }
            if ($factKey !== '') {
                if (!isset($facts[$factKey])) {
                    throw new \RuntimeException('The computation report profile refers to an unavailable fact ' . $factKey . '.');
                }
                $html .= '<tr' . ($class !== '' ? ' class="' . $generator->escape($class) . '"' : '') . '><th scope="row">'
                    . $generator->escape($label) . '</th><td class="amount">'
                    . $this->factMoneyHtml($generator, $facts, $factKey, (string)($row['direction'] ?? '') === 'deduction')
                    . '</td></tr>';
                continue;
            }
            if (!is_numeric($row['amount'] ?? null)) {
                throw new \RuntimeException('The computation report profile contains an untagged row without an amount.');
            }
            $html .= $this->moneyRow(
                $generator,
                $label,
                round((float)$row['amount'], 2),
                (string)($row['direction'] ?? '') === 'deduction',
                $class
            );
        }
        return $html;
    }

    private function moneyRow(
        IxbrlGeneratorService $generator,
        string $label,
        float $value,
        bool $deduction = false,
        string $class = ''
    ): string {
        $display = $this->moneyHtml($generator, $value, $deduction);
        return '<tr' . ($class !== '' ? ' class="' . $generator->escape($class) . '"' : '') . '><th scope="row">'
            . $generator->escape($label) . '</th><td class="amount">' . $display . '</td></tr>';
    }

    private function textRow(IxbrlGeneratorService $generator, string $label, string $html): string
    {
        return '<tr><th scope="row">' . $generator->escape($label) . '</th><td>' . $html . '</td></tr>';
    }

    private function money(array $summary, string $key): float
    {
        if (!array_key_exists($key, $summary) || !is_numeric($summary[$key])) {
            throw new \RuntimeException('The frozen computation report is missing monetary value ' . $key . '.');
        }
        return round((float)$summary[$key], 2);
    }

    private function longDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new \RuntimeException('The frozen computation report contains an invalid canonical date.');
        }
        return $date->format('j F Y');
    }

    private function stylesheet(): string
    {
        return <<<'CSS'
@page { size: A4 portrait; margin: 18mm 16mm 18mm 16mm; }
html { font-family: Arial, Helvetica, sans-serif; color: #20252b; background: #fff; font-size: 10pt; line-height: 1.35; }
body { margin: 0; padding: 0; }
.ct-report { width: 100%; max-width: 178mm; margin: 0 auto; }
.ct-header { margin: 0 0 8mm; padding: 0 0 5mm; border-bottom: 2px solid #273444; }
h1 { margin: 0 0 2mm; font-size: 17pt; line-height: 1.15; letter-spacing: .01em; }
.report-title { margin: 0; font-size: 13pt; font-weight: 700; }
.report-subtitle { margin: 1.5mm 0 0; color: #4c5661; }
.ct-section { margin: 0 0 7mm; break-inside: avoid; page-break-inside: avoid; }
h2, h3 { break-after: avoid; page-break-after: avoid; }
h2 { margin: 0 0 2.5mm; font-size: 11.5pt; color: #273444; }
h3 { margin: 3mm 0 2mm; font-size: 10.5pt; }
p { margin: 0 0 3mm; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; break-inside: avoid; page-break-inside: avoid; }
thead { display: table-header-group; }
tr { break-inside: avoid; page-break-inside: avoid; }
th, td { padding: 1.8mm 2mm; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
th { text-align: left; font-weight: 600; }
thead th { border-bottom: 1px solid #7b858f; color: #39434d; }
.identity-table th { width: 34%; color: #4c5661; }
.financial-table th:first-child { width: auto; }
.financial-table .amount { width: 31mm; }
.amount { text-align: right; font-variant-numeric: tabular-nums lining-nums; }
.accounting-negative, td.amount { white-space: nowrap; }
.subtotal th, .subtotal td { border-top: 1px solid #68727c; font-weight: 700; }
.final-total th, .final-total td { border-top: 3px double #273444; border-bottom: 1px solid #273444; font-weight: 700; }
.aia-table th:nth-child(2), .aia-table td:nth-child(2) { width: 35mm; }
.aia-table th:nth-child(3), .aia-table td:nth-child(3),
.aia-table th:nth-child(4), .aia-table td:nth-child(4) { width: 27mm; }
.keep-together { break-inside: avoid; page-break-inside: avoid; }
@media print {
  html, body { width: auto; max-width: 100%; min-height: 0; margin: 0; padding: 0; }
  .ct-report { box-sizing: border-box; width: 100%; max-width: 100%; margin: 0; }
  .ct-report table, .ct-report th, .ct-report td { box-sizing: border-box; max-width: 100%; }
  .ct-report th, .ct-report td:not(.amount) { overflow-wrap: anywhere; word-wrap: break-word; }
  .ct-report th.amount { white-space: normal; }
  .ct-section, table, tr, .keep-together { break-inside: avoid; page-break-inside: avoid; }
  h1, h2, h3 { break-after: avoid; page-break-after: avoid; }
}
CSS;
    }

    private function contextProfile(array $mapping): string
    {
        $profile = trim((string)($mapping['context_profile'] ?? ''));
        if (in_array($profile, [
            CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY,
            CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE,
            CtFilingMappingService::CONTEXT_HMRC_CT_LOSS_RESTRICTION,
        ], true)) {
            return $profile;
        }
        $localName = trim((string)($mapping['local_name'] ?? ''));
        if ($profile === 'ct_period' && in_array($localName, self::LEGACY_REVIEWED_CONCEPTS, true)) {
            return in_array($localName, self::LEGACY_TRADE_CONCEPTS, true)
                ? CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE
                : CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY;
        }
        throw new \RuntimeException('The computation mapping uses an unsupported HMRC context profile.');
    }

    private function contextDefinition(string $profile, string $prefix, array $model): array
    {
        $companyDimensions = [
            $prefix . ':BusinessTypeDimension' => $prefix . ':Company',
            $prefix . ':DetailedAnalysisDimension' => $prefix . ':Item1',
        ];
        if ($profile === CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY) {
            return [
                'dimension_container' => 'segment',
                'dimensions' => $companyDimensions,
                'typed_dimensions' => [],
            ];
        }
        if ($profile === CtFilingMappingService::CONTEXT_HMRC_CT_LOSS_RESTRICTION) {
            return [
                'dimension_container' => 'segment',
                'dimensions' => [],
                'typed_dimensions' => [],
            ];
        }
        $companyName = trim((string)($model['model']['identity']['company_name'] ?? ''));
        if ($profile !== CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE || $companyName === '') {
            throw new \RuntimeException('The reviewed UK-trade HMRC context requires the company name.');
        }
        return [
            'dimension_container' => 'segment',
            'dimensions' => [
                $prefix . ':BusinessTypeDimension' => $prefix . ':Trade',
                $prefix . ':DetailedAnalysisDimension' => $prefix . ':Item1',
                $prefix . ':LossReformDimension' => $prefix . ':Post-lossReform',
                $prefix . ':TerritoryDimension' => $prefix . ':UK',
            ],
            'typed_dimensions' => [[
                'dimension' => $prefix . ':BusinessNameDimension',
                'domain' => $prefix . ':BusinessNameDomain',
                'value' => $companyName,
            ]],
        ];
    }

    /** @return array{start_date:string,end_date:string} */
    private function contextPeriod(array $mapping, array $model): array
    {
        $role = trim((string)($mapping['context_role'] ?? 'ct_period'));
        if ($role === 'ct_period' || $role === 'ct_period_end') {
            $period = (array)($model['run'] ?? []);
            $start = (string)($period['period_start'] ?? '');
            $end = (string)($period['period_end'] ?? '');
        } elseif ($role === 'ct_period_beginning') {
            $period = (array)($model['run'] ?? []);
            $start = (string)($period['period_start'] ?? '');
            $end = $start;
        } elseif ($role === 'statutory_accounts_period') {
            $period = (array)($model['model']['accounting_period'] ?? []);
            $start = (string)($period['start_date'] ?? '');
            $end = (string)($period['end_date'] ?? '');
        } else {
            throw new \RuntimeException('The computation report profile uses an unsupported context role.');
        }
        $this->longDate($start);
        $this->longDate($end);
        return ['start_date' => $start, 'end_date' => $end];
    }

    /** Build the human-readable report solely from the verified frozen model and its resolved mappings. */
    public function buildReportModel(array $model, array $mappings): array
    {
        if (empty($model['available']) && !isset($model['model']['identity'], $model['run'])) {
            throw new \RuntimeException('A verified frozen CT-period filing model is required for the computation report.');
        }
        $frozen = (array)($model['model'] ?? []);
        $identity = (array)($frozen['identity'] ?? []);
        foreach (['company_name', 'company_number'] as $key) {
            if (trim((string)($identity[$key] ?? '')) === '') {
                throw new \RuntimeException('The computation report requires its frozen company identity.');
            }
        }
        if (trim((string)($frozen['filing_identity']['utr'] ?? '')) === '') {
            throw new \RuntimeException('The computation report requires its frozen Unique Taxpayer Reference.');
        }
        $run = (array)($model['run'] ?? []);
        $start = trim((string)($run['period_start'] ?? ''));
        $end = trim((string)($run['period_end'] ?? ''));
        if ($start === '' || $end === '') {
            throw new \RuntimeException('The computation report requires its CT-period start and end dates.');
        }
        $startDisplay = $this->longDate($start);
        $endDisplay = $this->longDate($end);
        $accountingPeriod = (array)($frozen['accounting_period'] ?? []);
        $this->longDate((string)($accountingPeriod['start_date'] ?? ''));
        $this->longDate((string)($accountingPeriod['end_date'] ?? ''));
        $profileCode = trim((string)($frozen['supported_return_profile']['profile_code'] ?? ''));
        $frameworkLabel = self::SUPPORTED_PROFILE_LABELS[$profileCode] ?? '';
        if ($frameworkLabel === ''
            || empty($frozen['supported_return_profile']['supported'])
            || empty($frozen['supported_return_profile']['ordinary_trading_company_confirmed'])) {
            throw new \RuntimeException('The frozen supported-return profile has no recognised accounting-framework label.');
        }
        $summary = (array)($frozen['computation']['summary'] ?? []);
        foreach ([
            'accounting_profit', 'disallowable_add_backs', 'capital_add_backs',
            'depreciation_add_back', 'capital_allowances', 'taxable_before_losses',
            'loss_created_in_period', 'losses_brought_forward', 'losses_used',
            'losses_carried_forward', 'taxable_profit', 'ordinary_corporation_tax',
        ] as $key) {
            $this->money($summary, $key);
        }
        $profile = (new HmrcCtComputationReportProfile())->apply($model, $mappings);
        $included = array_values(array_filter((array)$profile['mappings'], static fn(array $mapping): bool => array_key_exists('source_value', $mapping)));
        usort($included, fn(array $a, array $b): int => [self::SECTION_ORDER[(string)$a['presentation_section']] ?? 999, (int)$a['sort_order'], (int)$a['id']] <=> [self::SECTION_ORDER[(string)$b['presentation_section']] ?? 999, (int)$b['sort_order'], (int)$b['id']]);
        if ($included === []) {
            throw new \RuntimeException('The active profile produced no computation report facts.');
        }
        $sections = [];
        foreach ($included as $mapping) {
            $canonicalKey = (string)$mapping['canonical_key'];
            $label = $this->presentationLabel($canonicalKey, $mapping['source_value']);
            $section = (string)$mapping['presentation_section'];
            $sections[$section][] = [
                'canonical_key' => $canonicalKey,
                'label' => $label,
                'value' => $mapping['source_value'],
            ];
        }
        $companyName = trim((string)$identity['company_name']);
        $reportTitle = 'Corporation Tax computation for the period ended ' . $endDisplay;
        return [
            'title' => $reportTitle,
            'document_title' => $companyName . ': ' . $reportTitle,
            'period_start' => $start,
            'period_end' => $end,
            'period_start_display' => $startDisplay,
            'period_end_display' => $endDisplay,
            'framework_label' => $frameworkLabel,
            'sections' => $sections,
            'mappings' => $included,
            'accounts_adjustment_rows' => (array)$profile['accounts_adjustment_rows'],
            'main_pool_rows' => (array)$profile['main_pool_rows'],
            'loss_schedule_rows' => (array)$profile['loss_schedule_rows'],
            'untagged_row_allowlist' => (array)$profile['untagged_row_allowlist'],
            'format_version' => (string)$profile['format_version'],
        ];
    }

    private function artifactErrors(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        array $model,
        ?array $package,
        ?array $profile,
        ?array $stored,
        bool $requireValidation,
        ?string $artifactHash
    ): array {
        $errors = [];
        if (empty($model['available']) || !is_array($stored)) {
            return ['No current frozen computation artifact exists for this CT period.'];
        }
        if ((int)($stored['company_id'] ?? 0) !== $companyId
            || (int)($stored['accounting_period_id'] ?? 0) !== $accountingPeriodId
            || (int)($stored['ct_period_id'] ?? 0) !== $ctPeriodId) {
            $errors[] = 'The computation artifact identity does not match the requested CT period.';
        }
        if (!hash_equals((string)($stored['filing_basis_version'] ?? ''), (string)($model['basis_version'] ?? ''))
            || !hash_equals((string)($stored['filing_basis_hash'] ?? ''), (string)($model['basis_hash'] ?? ''))) {
            $errors[] = 'The computation artifact filing basis is stale.';
        }
        $packageHash = is_array($package) ? (new HmrcCtComputationCatalogueService())->verifiedPackageHash($package) : null;
        if (!is_array($package) || $packageHash === null
            || (int)($stored['computation_taxonomy_package_id'] ?? 0) !== (int)($package['id'] ?? 0)
            || !hash_equals((string)($stored['computation_taxonomy_package_hash'] ?? ''), (string)$packageHash)) {
            $errors[] = 'The computation taxonomy package is stale, changed or incompatible.';
        }
        if (!is_array($profile)
            || (int)($stored['ixbrl_mapping_profile_id'] ?? 0) !== (int)($profile['id'] ?? 0)
            || preg_match('/^[a-f0-9]{64}$/i', (string)($profile['content_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/i', (string)($stored['ixbrl_mapping_hash'] ?? '')) !== 1
            || !hash_equals(
                (string)($stored['ixbrl_mapping_hash'] ?? ''),
                $this->reportProfileHash((string)($profile['content_hash'] ?? ''))
            )) {
            $errors[] = 'The computation mapping profile is stale or changed.';
        }
        $outputHash = strtolower(trim((string)($stored['output_sha256'] ?? '')));
        if ($artifactHash === null || preg_match('/^[a-f0-9]{64}$/', $outputHash) !== 1 || !hash_equals($outputHash, $artifactHash)) {
            $errors[] = 'The computation artifact file is missing or has changed.';
        }
        if ($requireValidation) {
            $validatedHash = strtolower(trim((string)($stored['external_validated_sha256'] ?? '')));
            if ((string)($stored['ixbrl_status'] ?? '') !== 'validated'
                || (string)($stored['validation_status'] ?? '') !== 'passed'
                || (string)($stored['external_validation_status'] ?? '') !== 'passed'
                || trim((string)($stored['external_validator'] ?? '')) === ''
                || trim((string)($stored['external_validator_version'] ?? '')) === ''
                || $outputHash === '' || !hash_equals($outputHash, $validatedHash)) {
                $errors[] = 'The computation artifact has not passed current external validation.';
            }
        }
        return array_values(array_unique($errors));
    }

    private function reportProfileHash(string $mappingProfileHash): string
    {
        return hash('sha256', $mappingProfileHash . '|' . HmrcCtComputationReportProfile::VERSION);
    }

    private function failRun(int $runId, string $message): array
    {
        if ($runId > 0) {
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_computation_runs SET ixbrl_status = :status, validation_status = :validation_status,
                 validation_errors_json = :errors WHERE id = :id',
                ['status' => 'failed', 'validation_status' => 'failed', 'errors' => \eel_accounts\Support\Utf8::json([$message], JSON_UNESCAPED_SLASHES), 'id' => $runId]
            );
        }
        return ['success' => false, 'errors' => [$message]];
    }
}
