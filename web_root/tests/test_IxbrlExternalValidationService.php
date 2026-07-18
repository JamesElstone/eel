<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'IxbrlTestFixture.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlExternalValidationService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlExternalValidationService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlExternalValidationService::class, 'reports error when no run exists', static function () use ($harness, $service): void {
            $result = $service->validateLatestRun(0, 0);
            $harness->assertSame('error', $result['status'] ?? '');
        });

        $harness->check(\eel_accounts\Service\IxbrlExternalValidationService::class, 'summarises missing external validation as not configured', static function () use ($harness, $service): void {
            $status = $service->externalStatusForRun([]);
            $configuration = $service->configurationStatus();
            $harness->assertSame(!empty($configuration['installed']) ? 'not_run' : 'not_configured', $status['status'] ?? '');
            $harness->assertSame(false, $status['blocking'] ?? true);
        });

        $harness->check(\eel_accounts\Service\IxbrlExternalValidationService::class, 'stores the exact stable output hash processed by Arelle', static function () use ($harness): void {
            InterfaceDB::beginTransaction();
            $fixture = ixbrlExternalValidationFixture();
            try {
                $service = new \eel_accounts\Service\IxbrlExternalValidationService(
                    $fixture['config'],
                    $fixture['validator_root']
                );
                $result = $service->validateRun($fixture['run_id']);
                $stored = InterfaceDB::fetchOne(
                    'SELECT external_validation_status, external_validated_sha256
                     FROM ixbrl_generation_runs WHERE id = :id',
                    ['id' => $fixture['run_id']]
                );

                $harness->assertSame(true, (bool)($result['ok'] ?? false));
                $harness->assertSame($fixture['hash'], (string)($result['validated_sha256'] ?? ''));
                $harness->assertSame('passed', (string)($stored['external_validation_status'] ?? ''));
                $harness->assertSame($fixture['hash'], (string)($stored['external_validated_sha256'] ?? ''));
            } finally {
                @unlink($fixture['path']);
                InterfaceDB::rollBack();
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlExternalValidationService::class, 'rejects a generated artifact that no longer matches its output hash', static function () use ($harness): void {
            InterfaceDB::beginTransaction();
            $fixture = ixbrlExternalValidationFixture();
            try {
                file_put_contents($fixture['path'], 'changed before validation');
                $service = new \eel_accounts\Service\IxbrlExternalValidationService(
                    $fixture['config'],
                    $fixture['validator_root']
                );
                $result = $service->validateRun($fixture['run_id']);
                $storedHash = InterfaceDB::fetchColumn(
                    'SELECT external_validated_sha256 FROM ixbrl_generation_runs WHERE id = :id',
                    ['id' => $fixture['run_id']]
                );

                $harness->assertSame(false, (bool)($result['ok'] ?? true));
                $harness->assertSame('error', (string)($result['status'] ?? ''));
                $harness->assertSame(null, $storedHash);
            } finally {
                @unlink($fixture['path']);
                InterfaceDB::rollBack();
            }
        });
    }
);

function ixbrlExternalValidationFixture(): array
{
    (new \eel_accounts\Service\IxbrlFactBuilderService())->ensureSchema();
    ixbrl_test_ensure_frs105_thresholds();
    $token = bin2hex(random_bytes(5));
    $companyName = 'External iXBRL ' . $token;
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (
            company_name, company_number, company_status, companies_house_type,
            companies_house_jurisdiction, registered_office_address_line_1,
            registered_office_address_line_2, registered_office_locality,
            registered_office_postal_code, registered_office_country
         ) VALUES (
            :name, :number, :status, :company_type,
            :jurisdiction, :address_line_1,
            :address_line_2, :locality,
            :postal_code, :country
         )',
        [
            'name' => $companyName,
            'number' => strtoupper(substr($token, 0, 8)),
            'status' => 'active',
            'company_type' => 'ltd',
            'jurisdiction' => 'england-wales',
            'address_line_1' => '1 Validation Street',
            'address_line_2' => 'Arelle Park',
            'locality' => 'Testford',
            'postal_code' => 'TE5 7GB',
            'country' => 'United Kingdom',
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_name = :name',
        ['name' => $companyName]
    );
    $periodLabel = 'FY-' . $token;
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => $periodLabel,
            'period_start' => '2024-10-01',
            'period_end' => '2025-09-30',
        ]
    );
    $periodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
        ['company_id' => $companyId, 'label' => $periodLabel]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
         VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by)',
        ['company_id' => $companyId, 'accounting_period_id' => $periodId, 'locked_by' => 'test']
    );

    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('default_currency', 'GBP', 'char');
    $settings->flush();
    ixbrl_test_assign_sales_nominal($companyId);
    ixbrl_test_assign_director_loan_nominals($companyId);
    $savedDisclosures = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->save(
        $companyId,
        $periodId,
        [
            'accounting_standard' => 'FRS_105',
            'average_number_employees' => 1,
            'entity_dormant' => 0,
            'is_still_trading' => 1,
            'micro_entity_eligibility_confirmed' => 1,
            'going_concern_basis_appropriate' => 1,
            'has_material_off_balance_sheet_arrangements' => 0,
            'has_director_advances_credits_or_guarantees' => 0,
            'has_financial_commitments_guarantees_or_contingencies' => 0,
            'accounts_approval_date' => '2025-10-31',
            'approving_director_name' => 'Validation Director',
            'prepared_under_small_companies_regime' => 1,
            'audit_exempt_section_477' => 1,
            'directors_acknowledge_responsibilities' => 1,
            'members_have_not_required_audit' => 1,
        ],
        'test'
    );
    if (empty($savedDisclosures['success'])) {
        throw new RuntimeException(
            'Could not save the external-validation fixture disclosures: '
            . implode(' ', array_map('strval', (array)($savedDisclosures['errors'] ?? [])))
        );
    }
    $basisHash = (string)(new \eel_accounts\Service\IxbrlAccountsReportService())
        ->build($companyId, $periodId)['basis_hash'];

    $fixtureRoot = test_tmp_directory() . DIRECTORY_SEPARATOR . 'external_validation_' . $token;
    $logs = $fixtureRoot . DIRECTORY_SEPARATOR . 'logs';
    $cache = $fixtureRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache';
    mkdir($logs, 0775, true);
    mkdir($cache, 0775, true);
    $path = $fixtureRoot . DIRECTORY_SEPARATOR . 'accounts.xhtml';
    file_put_contents($path, '<html xmlns="http://www.w3.org/1999/xhtml"><body>filing</body></html>');
    $hash = (string)hash_file('sha256', $path);
    $command = $fixtureRoot . DIRECTORY_SEPARATOR . 'fake_arelle.bat';
    file_put_contents($command, "@echo off\r\necho validation passed\r\nexit /b 0\r\n");
    $config = $fixtureRoot . DIRECTORY_SEPARATOR . 'arelle.config.php';
    file_put_contents($config, '<?php return ' . var_export([
        'enabled' => true,
        'arelle_cmd' => $command,
        'timeout_seconds' => 5,
        'logs_path' => $logs,
        'cache_path' => $cache,
        'packages' => [],
        'offline' => true,
        'flags' => ['--validate'],
    ], true) . ';');

    InterfaceDB::prepareExecute(
        'INSERT INTO ixbrl_generation_runs (
            company_id, accounting_period_id, status, validation_status,
            taxonomy_profile, basis_version, basis_hash,
            generated_path, generated_filename, output_sha256
         ) VALUES (
            :company_id, :period_id, :status, :validation_status,
            :taxonomy_profile, :basis_version, :basis_hash,
            :path, :filename, :output_sha256
         )',
        [
            'company_id' => $companyId,
            'period_id' => $periodId,
            'status' => 'generated',
            'validation_status' => 'passed',
            'taxonomy_profile' => \eel_accounts\Service\IxbrlTaxonomyProfileService::PROFILE,
            'basis_version' => \eel_accounts\Service\IxbrlTaxonomyProfileService::BASIS_VERSION,
            'basis_hash' => $basisHash,
            'path' => $path,
            'filename' => basename($path),
            'output_sha256' => $hash,
        ]
    );
    $runId = (int)InterfaceDB::fetchColumn(
        'SELECT MAX(id) FROM ixbrl_generation_runs
         WHERE company_id = :company_id AND accounting_period_id = :period_id',
        ['company_id' => $companyId, 'period_id' => $periodId]
    );
    $sourceJson = json_encode([
        'director_loan_reporting_presentation' => [
            'provenance_version' => 1,
            'classification' => 'within_one_year',
            'revision' => 0,
            'liability_nominal_account_id' => 0,
            'explicit' => false,
        ],
    ], JSON_THROW_ON_ERROR);
    foreach (['creditors_within_one_year', 'creditors_after_one_year'] as $factKey) {
        InterfaceDB::prepareExecute(
            'INSERT INTO ixbrl_generation_facts (
                run_id, fact_key, taxonomy_concept, label, value_type,
                numeric_value, context_ref, source_json
             ) VALUES (
                :run_id, :fact_key, :concept, :label, :value_type,
                :numeric_value, :context_ref, :source_json
             )',
            [
                'run_id' => $runId,
                'fact_key' => $factKey,
                'concept' => 'core:Creditors',
                'label' => $factKey,
                'value_type' => 'numeric',
                'numeric_value' => 0,
                'context_ref' => $factKey,
                'source_json' => $sourceJson,
            ]
        );
    }

    return [
        'run_id' => $runId,
        'path' => $path,
        'hash' => $hash,
        'config' => $config,
        'validator_root' => $fixtureRoot,
    ];
}
