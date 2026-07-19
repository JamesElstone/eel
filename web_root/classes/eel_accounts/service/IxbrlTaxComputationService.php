<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class IxbrlTaxComputationService
{
    private const SECTION_ORDER = [
        'detailed_profit_and_loss' => 10,
        'accounts_adjustments' => 20,
        'capital_allowances' => 30,
        'losses' => 40,
        'tax_liability' => 50,
    ];

    public function generateFilingExport(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $model = (new CtPeriodFilingModelService())->build($companyId, $accountingPeriodId, $ctPeriodId);
        if (empty($model['available'])) {
            return ['success' => false, 'errors' => (array)($model['errors'] ?? ['The locked filing model is unavailable.'])];
        }
        $run = (array)$model['run'];
        $runId = (int)$run['run_id'];
        $package = (new HmrcCtComputationCatalogueService())->resolveForPeriod((string)$run['period_start'], (string)$run['period_end']);
        if (!is_array($package)) {
            return $this->failRun($runId, 'No verified HMRC computation-taxonomy package with a combined CT/DPL entry point applies to this CT period.');
        }
        $profile = (new CtFilingMappingService())->activeProfile(CtFilingMappingService::TARGET_COMPUTATION, (int)$package['id']);
        if (!is_array($profile)) {
            return $this->failRun($runId, 'No active, compatible database mapping profile exists for the applicable computation taxonomy.');
        }
        $mappedFacts = (new CtFilingMappingService())->mapFrozenFacts(
            CtFilingMappingService::TARGET_COMPUTATION,
            $model,
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
            $rendered = $this->renderMappedDocument($generator, $model, $package, (array)$mappedFacts['mappings']);
            $errors = $generator->validateStructure($rendered['xhtml'], [$rendered['schema_ref']]);
            if ($errors !== []) {
                throw new \RuntimeException(implode(' ', $errors));
            }
            $artifact = $generator->storeArtifact(
                $companyId,
                (string)$model['model']['identity']['company_number'],
                str_replace('-', '', (string)$run['period_start']),
                str_replace('-', '', (string)$run['period_end']),
                'tax',
                $runId,
                $rendered['xhtml']
            );
            $external = (new IxbrlExternalValidationService())->validateArtifact((string)$artifact['path']);
            $externalStatus = (string)($external['status'] ?? 'error');
            $fileable = $externalStatus === 'passed';
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_computation_runs SET
                   ixbrl_status = :ixbrl_status,
                   computation_taxonomy_package_id = :package_id,
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
                'errors' => $fileable ? [] : (array)($external['errors'] ?? ['Arelle validation did not pass.']),
                'warnings' => (array)($external['warnings'] ?? []),
                'filename' => $artifact['filename'],
                'path' => $artifact['path'],
                'sha256' => $artifact['sha256'],
                'run_id' => $runId,
            ];
        } catch (\Throwable $exception) {
            if (is_array($artifact)) {
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
                $profile = (new CtFilingMappingService())->activeProfile(CtFilingMappingService::TARGET_COMPUTATION, (int)$package['id']);
                if (!is_array($profile)) {
                    $errors[] = 'No active compatible computation mapping profile applies.';
                }
            }
        }
        $stored = isset($run['run_id']) ? \InterfaceDB::fetchOne('SELECT * FROM corporation_tax_computation_runs WHERE id = :id', ['id' => (int)$run['run_id']]) : null;
        $fresh = is_array($stored) && !empty($model['available'])
            && hash_equals((string)($stored['filing_basis_hash'] ?? ''), (string)($model['basis_hash'] ?? ''))
            && is_array($profile) && hash_equals((string)($stored['ixbrl_mapping_hash'] ?? ''), (string)($profile['content_hash'] ?? ''))
            && is_file((string)($stored['generated_path'] ?? ''))
            && hash_equals((string)($stored['output_sha256'] ?? ''), (string)hash_file('sha256', (string)$stored['generated_path']));
        return ['ready' => $errors === [], 'errors' => array_values(array_unique($errors)), 'model' => $model, 'package' => $package, 'profile' => $profile, 'run' => $stored, 'fresh' => $fresh, 'fileable' => $fresh && (string)($stored['ixbrl_status'] ?? '') === 'validated'];
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
        $entryPoint = trim((string)(($package['combined_dpl_entry_point_path'] ?? null) ?: ($package['entry_point_path'] ?? '')));
        $schemaRef = 'file:///' . str_replace(['\\', ' '], ['/', '%20'], $entryPoint);
        $xhtml = file_get_contents($path);
        $internalErrors = is_string($xhtml) ? (new IxbrlGeneratorService())->validateStructure($xhtml, [$schemaRef]) : ['The artifact could not be read.'];
        if ($internalErrors !== []) {
            return $this->failRun((int)$run['id'], implode(' ', $internalErrors));
        }
        $external = (new IxbrlExternalValidationService())->validateArtifact($path);
        $passed = (string)($external['status'] ?? '') === 'passed';
        \InterfaceDB::prepareExecute(
            'UPDATE corporation_tax_computation_runs SET ixbrl_status = :ixbrl_status, validation_status = :validation_status,
             validation_errors_json = :validation_errors, external_validator = :validator,
             external_validation_status = :external_status, external_validation_errors_json = :external_errors,
             external_validation_warnings_json = :external_warnings, external_validation_log_path = :external_log,
             external_validated_at = CURRENT_TIMESTAMP, external_validated_sha256 = :validated_sha256 WHERE id = :id',
            ['ixbrl_status' => $passed ? 'validated' : 'validation_failed', 'validation_status' => 'passed', 'validation_errors' => json_encode([], JSON_UNESCAPED_SLASHES), 'validator' => 'arelle', 'external_status' => (string)($external['status'] ?? 'error'), 'external_errors' => json_encode((array)($external['errors'] ?? []), JSON_UNESCAPED_SLASHES), 'external_warnings' => json_encode((array)($external['warnings'] ?? []), JSON_UNESCAPED_SLASHES), 'external_log' => ($external['log_path'] ?? null) ?: null, 'validated_sha256' => ($external['validated_sha256'] ?? null) ?: null, 'id' => (int)$run['id']]
        );
        return ['success' => $passed, 'errors' => $passed ? [] : (array)($external['errors'] ?? ['Arelle validation did not pass.']), 'warnings' => (array)($external['warnings'] ?? [])];
    }

    private function renderMappedDocument(IxbrlGeneratorService $generator, array $model, array $package, array $mappings): array
    {
        $run = (array)$model['run'];
        usort($mappings, fn(array $a, array $b): int => [self::SECTION_ORDER[(string)$a['presentation_section']] ?? 999, (int)$a['sort_order'], (int)$a['id']] <=> [self::SECTION_ORDER[(string)$b['presentation_section']] ?? 999, (int)$b['sort_order'], (int)$b['id']]);
        $contexts = [];
        $sections = [];
        $namespaces = [];
        foreach ($mappings as $mapping) {
            if (!array_key_exists('source_value', $mapping)) {
                throw new \RuntimeException('A computation mapping was not resolved from the frozen filing model.');
            }
            $value = $mapping['source_value'];
            $dimensions = json_decode((string)($mapping['dimensions_json'] ?? ''), true);
            $dimensions = is_array($dimensions) ? $dimensions : [];
            $contextId = 'ct_' . substr(hash('sha256', (string)$mapping['period_type'] . '|' . json_encode($dimensions)), 0, 12);
            if (!isset($contexts[$contextId])) {
                $contexts[$contextId] = [
                    'id' => $contextId,
                    'identifier' => (string)$model['model']['identity']['company_number'],
                    'start_date' => (string)$run['period_start'],
                    'end_date' => (string)$run['period_end'],
                    'dimensions' => $dimensions,
                ];
                if ((string)$mapping['period_type'] === 'instant') {
                    unset($contexts[$contextId]['start_date'], $contexts[$contextId]['end_date']);
                    $contexts[$contextId]['instant'] = (string)$run['period_end'];
                }
            }
            $concept = (string)$mapping['taxonomy_concept'];
            [$prefix] = explode(':', $concept, 2);
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
        $body = '<main><h1>Corporation Tax computation</h1><p>CT period ' . $generator->escape((string)$run['period_start']) . ' to ' . $generator->escape((string)$run['period_end']) . '</p>';
        foreach ($sections as $section => $rows) {
            $body .= '<section><h2>' . $generator->escape(ucwords(str_replace('_', ' ', $section))) . '</h2><table><tbody>' . implode('', $rows) . '</tbody></table></section>';
        }
        $body .= '</main>';
        $entryPoint = trim((string)($package['combined_dpl_entry_point_path'] ?: $package['entry_point_path']));
        if ($entryPoint === '' || !is_file($entryPoint)) {
            throw new \RuntimeException('The configured combined CT/DPL taxonomy entry point was not found.');
        }
        $schemaRef = 'file:///' . str_replace(['\\', ' '], ['/', '%20'], $entryPoint);
        return ['schema_ref' => $schemaRef, 'xhtml' => $generator->renderDocument([
            'title' => 'Corporation Tax computation',
            'namespaces' => $namespaces,
            'schema_refs' => [$schemaRef],
            'contexts' => array_values($contexts),
            'units' => [['id' => 'GBP', 'measure' => 'iso4217:GBP']],
            'body' => $body,
        ])];
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
