<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcSubmissionPackageService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcSubmissionPackageService $service): void {
        $harness->check(\eel_accounts\Service\HmrcSubmissionPackageService::class, 'missing computations iXBRL blocks submission', static function () use ($harness, $service): void {
            $result = $service->locateComputationsIxbrl(0, 0);
            $harness->assertSame(false, $result['ok']);
            $harness->assertTrue(count($result['errors']) > 0);
        });

        $harness->check(\eel_accounts\Service\HmrcSubmissionPackageService::class, 'accepts only the current artifact with matching generated and validated hashes', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            $fixture = hmrcAccountsIxbrlFixture();
            try {
                $result = $service->locateAccountsIxbrl($fixture['company_id'], $fixture['period_id']);

                $harness->assertSame(true, (bool)($result['ok'] ?? false));
                $harness->assertSame('ready', (string)($result['state'] ?? ''));
                $harness->assertSame($fixture['hash'], (string)($result['hash'] ?? ''));
                $harness->assertSame($fixture['run_id'], (int)($result['run_id'] ?? 0));
            } finally {
                @unlink($fixture['path']);
                InterfaceDB::rollBack();
            }
        });

        $harness->check(\eel_accounts\Service\HmrcSubmissionPackageService::class, 'reports tampering when the current file hash differs', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            $fixture = hmrcAccountsIxbrlFixture();
            try {
                file_put_contents($fixture['path'], 'changed after validation');
                $result = $service->locateAccountsIxbrl($fixture['company_id'], $fixture['period_id']);

                $harness->assertSame(false, (bool)($result['ok'] ?? true));
                $harness->assertSame('tampered', (string)($result['state'] ?? ''));
            } finally {
                @unlink($fixture['path']);
                InterfaceDB::rollBack();
            }
        });

        $harness->check(\eel_accounts\Service\HmrcSubmissionPackageService::class, 'does not fall back when a newer run is not generated', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            $fixture = hmrcAccountsIxbrlFixture();
            try {
                InterfaceDB::prepareExecute(
                    'INSERT INTO ixbrl_generation_runs (company_id, accounting_period_id, status)
                     VALUES (:company_id, :period_id, :status)',
                    [
                        'company_id' => $fixture['company_id'],
                        'period_id' => $fixture['period_id'],
                        'status' => 'ready',
                    ]
                );
                $newestRunId = (int)InterfaceDB::fetchColumn(
                    'SELECT MAX(id) FROM ixbrl_generation_runs
                     WHERE company_id = :company_id AND accounting_period_id = :period_id',
                    ['company_id' => $fixture['company_id'], 'period_id' => $fixture['period_id']]
                );
                $result = $service->locateAccountsIxbrl($fixture['company_id'], $fixture['period_id']);

                $harness->assertSame(false, (bool)($result['ok'] ?? true));
                $harness->assertSame('missing', (string)($result['state'] ?? ''));
                $harness->assertSame($newestRunId, (int)($result['run_id'] ?? 0));
            } finally {
                @unlink($fixture['path']);
                InterfaceDB::rollBack();
            }
        });
    }
);

function hmrcAccountsIxbrlFixture(): array
{
    (new \eel_accounts\Service\IxbrlFactBuilderService())->ensureSchema();
    $token = bin2hex(random_bytes(5));
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
            'name' => 'HMRC iXBRL ' . $token,
            'number' => strtoupper(substr($token, 0, 8)),
            'status' => 'active',
            'company_type' => 'ltd',
            'jurisdiction' => 'england-wales',
            'address_line_1' => '1 Submission Street',
            'address_line_2' => 'Package Park',
            'locality' => 'Testford',
            'postal_code' => 'TE5 7GB',
            'country' => 'United Kingdom',
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_name = :name',
        ['name' => 'HMRC iXBRL ' . $token]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'FY-' . $token,
            'period_start' => '2024-10-01',
            'period_end' => '2025-09-30',
        ]
    );
    $periodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
        ['company_id' => $companyId, 'label' => 'FY-' . $token]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
         VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by)',
        ['company_id' => $companyId, 'accounting_period_id' => $periodId, 'locked_by' => 'test']
    );

    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('default_currency', 'GBP', 'char');
    $settings->flush();
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
            'approving_director_name' => 'Submission Director',
            'prepared_under_small_companies_regime' => 1,
            'audit_exempt_section_477' => 1,
            'directors_acknowledge_responsibilities' => 1,
            'members_have_not_required_audit' => 1,
        ],
        'test'
    );
    if (empty($savedDisclosures['success'])) {
        throw new RuntimeException(
            'Could not save the HMRC package fixture disclosures: '
            . implode(' ', array_map('strval', (array)($savedDisclosures['errors'] ?? [])))
        );
    }
    $basisHash = (string)(new \eel_accounts\Service\IxbrlAccountsReportService())
        ->build($companyId, $periodId)['basis_hash'];

    $directory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    $path = $directory . DIRECTORY_SEPARATOR . 'hmrc_ixbrl_' . $token . '.xhtml';
    file_put_contents($path, '<html xmlns="http://www.w3.org/1999/xhtml"><body>filing</body></html>');
    $hash = (string)hash_file('sha256', $path);

    InterfaceDB::prepareExecute(
        'INSERT INTO ixbrl_generation_runs (
            company_id, accounting_period_id, status,
            validation_status, external_validation_status,
            taxonomy_profile, basis_version, basis_hash,
            generated_path, generated_filename, output_sha256, external_validated_sha256
         ) VALUES (
            :company_id, :period_id, :status,
            :validation_status, :external_validation_status,
            :taxonomy_profile, :basis_version, :basis_hash,
            :generated_path, :generated_filename, :output_sha256, :external_validated_sha256
         )',
        [
            'company_id' => $companyId,
            'period_id' => $periodId,
            'status' => 'generated',
            'validation_status' => 'passed',
            'external_validation_status' => 'passed',
            'taxonomy_profile' => \eel_accounts\Service\IxbrlTaxonomyProfileService::PROFILE,
            'basis_version' => \eel_accounts\Service\IxbrlTaxonomyProfileService::BASIS_VERSION,
            'basis_hash' => $basisHash,
            'generated_path' => $path,
            'generated_filename' => basename($path),
            'output_sha256' => $hash,
            'external_validated_sha256' => $hash,
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
        'company_id' => $companyId,
        'period_id' => $periodId,
        'run_id' => $runId,
        'path' => $path,
        'hash' => $hash,
    ];
}
