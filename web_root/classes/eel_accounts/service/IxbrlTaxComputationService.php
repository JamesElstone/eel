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
    private const LEGACY_TRADE_CONCEPTS = [
        'ProfitLossPerAccounts',
        'AdjustmentsMiscellaneousExpensesPerAccounts',
        'AdjustmentsCapitalExpenditure',
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
        $mappingModel = $model;
        $ct600aTax = round((float)($model['model']['ct600a']['tax_payable'] ?? 0), 2);
        $ordinaryTax = round((float)($model['model']['computation']['summary']['ordinary_corporation_tax'] ?? 0), 2);
        $mappingModel['facts']['computation.summary.s455_tax'] = $ct600aTax;
        $mappingModel['facts']['computation.summary.estimated_corporation_tax'] = round($ordinaryTax + $ct600aTax, 2);
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
        try {
            $validationResources = $catalogue->validationResources($package);
            $rendered = $this->renderMappedDocument(
                $generator,
                $model,
                (array)$mappedFacts['mappings'],
                (string)$validationResources['schema_ref']
            );
            $errors = $generator->validateStructure($rendered['xhtml'], [$rendered['schema_ref']]);
            if ($errors !== []) {
                throw new \RuntimeException(implode(' ', $errors));
            }
            $artifact = $generator->storeImmutableArtifact(
                $companyId,
                (string)$model['model']['identity']['company_number'],
                str_replace('-', '', (string)$run['period_start']),
                str_replace('-', '', (string)$run['period_end']),
                'tax',
                $runId,
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
                    'profile_hash' => (string)$profile['content_hash'],
                    'basis_version' => (string)$model['basis_version'],
                    'basis_hash' => (string)$model['basis_hash'],
                    'path' => $artifact['path'],
                    'filename' => $artifact['filename'],
                    'taxonomy_profile' => (string)$package['taxonomy_version'] . '/' . (string)$package['artifact_version'],
                    'validation_status' => 'passed',
                    'validation_errors' => json_encode([], JSON_UNESCAPED_SLASHES),
                    'external_validator' => 'arelle',
                    'external_validator_version' => $validatorVersion !== '' ? $validatorVersion : null,
                    'external_status' => $externalStatus,
                    'external_errors' => json_encode((array)($external['errors'] ?? []), JSON_UNESCAPED_SLASHES),
                    'external_warnings' => json_encode((array)($external['warnings'] ?? []), JSON_UNESCAPED_SLASHES),
                    'external_log' => ($external['log_path'] ?? null) ?: null,
                    'output_sha256' => $artifact['sha256'],
                    'validated_sha256' => ($external['validated_sha256'] ?? null) ?: null,
                    'id' => $runId,
                ]
            );
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
            ];
        } catch (\Throwable $exception) {
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
        $artifactErrors = $this->artifactErrors($companyId, $accountingPeriodId, $ctPeriodId, $model, $package, $profile, $stored, false);
        $fresh = $artifactErrors === [];
        $fileableErrors = $fresh
            ? $this->artifactErrors($companyId, $accountingPeriodId, $ctPeriodId, $model, $package, $profile, $stored, true)
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
            ['ixbrl_status' => $passed ? 'validated' : 'validation_failed', 'validation_status' => 'passed', 'validation_errors' => json_encode([], JSON_UNESCAPED_SLASHES), 'validator' => 'arelle', 'validator_version' => $validatorVersion !== '' ? $validatorVersion : null, 'external_status' => (string)($external['status'] ?? 'error'), 'external_errors' => json_encode((array)($external['errors'] ?? []), JSON_UNESCAPED_SLASHES), 'external_warnings' => json_encode((array)($external['warnings'] ?? []), JSON_UNESCAPED_SLASHES), 'external_log' => ($external['log_path'] ?? null) ?: null, 'validated_sha256' => ($external['validated_sha256'] ?? null) ?: null, 'id' => (int)$run['id']]
        );
        return ['success' => $passed, 'errors' => $passed ? [] : ((array)($external['errors'] ?? []) !== [] ? (array)$external['errors'] : ['Arelle validation did not return a complete validator identity and matching artifact hash.']), 'warnings' => (array)($external['warnings'] ?? [])];
    }

    private function renderMappedDocument(
        IxbrlGeneratorService $generator,
        array $model,
        array $mappings,
        string $schemaRef
    ): array
    {
        $run = (array)$model['run'];
        $report = $this->buildReportModel($model, $mappings);
        $contexts = [];
        $sections = [];
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
            $contextId = 'ct_' . substr(hash('sha256', (string)$mapping['period_type'] . '|'
                . (string)json_encode($contextDefinition, JSON_UNESCAPED_SLASHES)), 0, 12);
            if (!isset($contexts[$contextId])) {
                $contexts[$contextId] = [
                    'id' => $contextId,
                    'identifier' => (string)$model['model']['identity']['company_number'],
                    'start_date' => (string)$run['period_start'],
                    'end_date' => (string)$run['period_end'],
                ] + $contextDefinition;
                if ((string)$mapping['period_type'] === 'instant') {
                    unset($contexts[$contextId]['start_date'], $contexts[$contextId]['end_date']);
                    $contexts[$contextId]['instant'] = (string)$run['period_end'];
                }
            }
            $namespaces[$prefix] = (string)$mapping['namespace_uri'];
            $numeric = in_array((string)$mapping['value_type'], ['numeric', 'integer'], true);
            if ($numeric && $value !== null) {
                $value = (float)$value * (float)$mapping['sign_multiplier'];
            }
            $section = (string)$mapping['presentation_section'];
            $sections[$section][] = '<tr><th scope="row">' . $generator->escape((string)$mapping['presentation_label']) . '</th><td>'
                . $generator->renderFact([
                    'qname' => $concept,
                    'context_ref' => $contextId,
                    'value' => $value === '' ? null : $value,
                    'numeric' => $numeric,
                    'unit_ref' => ($mapping['unit_ref'] ?? null) ?: 'GBP',
                    'decimals' => ($mapping['decimals_value'] ?? null) ?: ($numeric ? '2' : '0'),
                ]) . '</td></tr>';
        }
        if ($sections === []) {
            throw new \RuntimeException('The active profile produced no Inline XBRL facts.');
        }
        $body = '<div class="ct-report"><h1>' . $generator->escape((string)$report['title']) . '</h1><p>CT period ' . $generator->escape((string)$report['period_start']) . ' to ' . $generator->escape((string)$report['period_end']) . '</p>';
        foreach ($sections as $section => $rows) {
            $body .= '<div class="ct-section"><h2>' . $generator->escape(ucwords(str_replace('_', ' ', $section))) . '</h2><table><tbody>' . implode('', $rows) . '</tbody></table></div>';
        }
        $body .= $this->renderSupportingSchedules($generator, $model);
        $body .= '</div>';
        if (!str_starts_with($schemaRef, 'http://www.hmrc.gov.uk/')) {
            throw new \RuntimeException('The verified HMRC computation-taxonomy schema reference is invalid.');
        }
        return ['schema_ref' => $schemaRef, 'xhtml' => $generator->renderDocument([
            'title' => 'Corporation Tax computation',
            'namespaces' => $namespaces,
            'schema_refs' => [$schemaRef],
            'contexts' => array_values($contexts),
            'units' => [['id' => 'GBP', 'measure' => 'iso4217:GBP']],
            'body' => $body,
        ])];
    }

    private function renderSupportingSchedules(IxbrlGeneratorService $generator, array $filing): string
    {
        $model = (array)($filing['model'] ?? []);
        $summary = (array)($model['computation']['summary'] ?? []);
        $breakdown = (array)($summary['capital_allowance_breakdown'] ?? []);
        $assets = array_values(array_filter((array)($breakdown['asset_calculations'] ?? []), static fn(mixed $row): bool =>
            is_array($row) && (string)($row['allowance_type'] ?? '') === 'aia' && (float)($row['allowance_amount'] ?? 0) >= 0.005
        ));
        $html = '';
        if ($assets !== []) {
            $total = round(array_sum(array_map(static fn(array $row): float => (float)($row['allowance_amount'] ?? 0), $assets)), 2);
            $expected = round((float)($model['filing_decisions']['aia_claimed_in_trade'] ?? 0), 2);
            if (abs($total - $expected) > 0.009) {
                throw new \RuntimeException('The asset-level AIA schedule does not reconcile to the frozen CT600 AIA claim.');
            }
            $rows = '';
            foreach ($assets as $asset) {
                $rows .= '<tr><td>' . $generator->escape((string)($asset['asset_code'] ?? $asset['asset_id'] ?? '')) . '</td>'
                    . '<td>' . $generator->escape((string)($asset['description'] ?? 'Plant and machinery')) . '</td>'
                    . '<td>' . $generator->escape((string)($asset['purchase_date'] ?? '')) . '</td>'
                    . '<td>' . $generator->escape(number_format((float)($asset['addition_amount'] ?? 0), 2, '.', ',')) . '</td>'
                    . '<td>' . $generator->escape(number_format((float)($asset['allowance_amount'] ?? 0), 2, '.', ',')) . '</td></tr>';
            }
            $html .= '<div class="ct-section"><h2>Annual Investment Allowance schedule</h2><table><thead><tr><th>Asset</th><th>Description</th><th>Acquired</th><th>Qualifying expenditure (£)</th><th>AIA claimed (£)</th></tr></thead><tbody>'
                . $rows . '<tr><th colspan="4">Total AIA claimed</th><td>' . $generator->escape(number_format($total, 2, '.', ',')) . '</td></tr></tbody></table></div>';
        }
        $ct600a = (array)($model['ct600a'] ?? []);
        if (!empty($ct600a['required'])) {
            $html .= '<div class="ct-section"><h2>CT600A loans and arrangements schedule</h2>';
            $html .= $this->supportingTable($generator, 'Part 1 — loans and benefits', (array)($ct600a['part1']['rows'] ?? []), 'amount');
            $html .= $this->supportingTable($generator, 'Part 2 — relief within nine months', (array)($ct600a['part2']['rows'] ?? []), null);
            $html .= $this->supportingTable($generator, 'Part 3 — relief due now', (array)($ct600a['part3']['rows'] ?? []), null);
            $html .= '<table><tbody><tr><th>A75 total outstanding</th><td>' . $generator->escape(number_format((float)($ct600a['total_loans_outstanding'] ?? 0), 2, '.', ','))
                . '</td></tr><tr><th>A80 tax payable</th><td>' . $generator->escape(number_format((float)($ct600a['tax_payable'] ?? 0), 2, '.', ',')) . '</td></tr></tbody></table></div>';
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
            $body .= '<tr><td>' . $generator->escape((string)($row['name'] ?? 'Participator')) . '</td><td>'
                . $generator->escape((string)($row['date'] ?? '')) . '</td><td>'
                . $generator->escape(number_format($amount, 2, '.', ',')) . '</td></tr>';
        }
        return '<h3>' . $generator->escape($title) . '</h3><table><thead><tr><th>Participator or associate</th><th>Date</th><th>Amount (£)</th></tr></thead><tbody>' . $body . '</tbody></table>';
    }

    private function contextProfile(array $mapping): string
    {
        $profile = trim((string)($mapping['context_profile'] ?? ''));
        if (in_array($profile, [
            CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY,
            CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE,
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

    /** Build the human-readable report solely from the verified frozen model and its resolved mappings. */
    public function buildReportModel(array $model, array $mappings): array
    {
        if (empty($model['available']) && !isset($model['model']['identity'], $model['run'])) {
            throw new \RuntimeException('A verified frozen CT-period filing model is required for the computation report.');
        }
        $run = (array)($model['run'] ?? []);
        $start = trim((string)($run['period_start'] ?? ''));
        $end = trim((string)($run['period_end'] ?? ''));
        if ($start === '' || $end === '') {
            throw new \RuntimeException('The computation report requires its CT-period start and end dates.');
        }
        $included = array_values(array_filter($mappings, static fn(array $mapping): bool => array_key_exists('source_value', $mapping)));
        usort($included, fn(array $a, array $b): int => [self::SECTION_ORDER[(string)$a['presentation_section']] ?? 999, (int)$a['sort_order'], (int)$a['id']] <=> [self::SECTION_ORDER[(string)$b['presentation_section']] ?? 999, (int)$b['sort_order'], (int)$b['id']]);
        if ($included === []) {
            throw new \RuntimeException('The active profile produced no computation report facts.');
        }
        $sections = [];
        foreach ($included as $mapping) {
            $section = (string)$mapping['presentation_section'];
            $sections[$section][] = [
                'canonical_key' => (string)$mapping['canonical_key'],
                'label' => (string)$mapping['presentation_label'],
                'value' => $mapping['source_value'],
            ];
        }
        return [
            'title' => 'Corporation Tax computation',
            'period_start' => $start,
            'period_end' => $end,
            'sections' => $sections,
            'mappings' => $included,
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
        bool $requireValidation
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
            || !hash_equals((string)($stored['ixbrl_mapping_hash'] ?? ''), (string)($profile['content_hash'] ?? ''))) {
            $errors[] = 'The computation mapping profile is stale or changed.';
        }
        $path = trim((string)($stored['generated_path'] ?? ''));
        $outputHash = strtolower(trim((string)($stored['output_sha256'] ?? '')));
        $actualHash = $path !== '' && is_file($path) ? hash_file('sha256', $path) : false;
        if (!is_string($actualHash) || preg_match('/^[a-f0-9]{64}$/', $outputHash) !== 1 || !hash_equals($outputHash, strtolower($actualHash))) {
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

    private function failRun(int $runId, string $message): array
    {
        if ($runId > 0) {
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_computation_runs SET ixbrl_status = :status, validation_status = :validation_status,
                 validation_errors_json = :errors WHERE id = :id',
                ['status' => 'failed', 'validation_status' => 'failed', 'errors' => json_encode([$message], JSON_UNESCAPED_SLASHES), 'id' => $runId]
            );
        }
        return ['success' => false, 'errors' => [$message]];
    }
}
