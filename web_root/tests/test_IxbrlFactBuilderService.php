<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlFactBuilderService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlFactBuilderService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'handles missing company and period safely', static function () use ($harness, $service): void {
            $harness->assertSame(null, $service->getLatestRun(0, 0));
            $harness->assertSame([], $service->getFacts(0));
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'installer-safe schema creates mapping seed rows', static function () use ($harness, $service): void {
            $service->ensureSchema();
            $harness->assertTrue(InterfaceDB::countWhere('ixbrl_fact_mappings', 'fact_key', 'entity_name') > 0);
            $harness->assertTrue(InterfaceDB::countWhere('ixbrl_fact_mappings', 'fact_key', 'net_assets_liabilities') > 0);
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'schema includes filing export validation metadata', static function () use ($harness, $service): void {
            $service->ensureSchema();
            foreach (['export_type', 'taxonomy_profile', 'validation_status', 'validation_errors_json', 'external_validator', 'external_validation_status', 'external_validation_errors_json', 'external_validation_warnings_json', 'external_validation_log_path', 'external_validated_at'] as $column) {
                $harness->assertTrue(InterfaceDB::columnExists('ixbrl_generation_runs', $column));
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'repairs long-term creditor and equity mapping aliases', static function () use ($harness, $service): void {
            $service->ensureSchema();
            $creditors = InterfaceDB::fetchOne('SELECT taxonomy_concept, source_key FROM ixbrl_fact_mappings WHERE fact_key = :fact_key', ['fact_key' => 'creditors_after_one_year']);
            $equity = InterfaceDB::fetchOne('SELECT source_key FROM ixbrl_fact_mappings WHERE fact_key = :fact_key', ['fact_key' => 'equity']);

            $harness->assertSame('uk-gaap:CreditorsDueAfterMoreThanOneYear', (string)($creditors['taxonomy_concept'] ?? ''));
            $harness->assertSame('creditors_after_more_than_one_year', (string)($creditors['source_key'] ?? ''));
            $harness->assertSame('equity_capital_reserves', (string)($equity['source_key'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'records Director Loan presentation provenance and makes older facts stale after a reporting change', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            try {
                $fixture = ixbrlFactBuilderDirectorLoanFixture();
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];
                $presentationService = new \eel_accounts\Service\DirectorLoanReportingPresentationService();

                $defaultRunId = $service->buildFacts($companyId, $periodId);
                $defaultWithin = ixbrlFactBuilderFact($defaultRunId, 'creditors_within_one_year');
                $defaultAfter = ixbrlFactBuilderFact($defaultRunId, 'creditors_after_one_year');
                $defaultSource = json_decode((string)($defaultWithin['source_json'] ?? ''), true);
                $defaultProvenance = (array)($defaultSource['director_loan_reporting_presentation'] ?? []);

                $harness->assertSame(500.0, (float)($defaultWithin['numeric_value'] ?? 0));
                $harness->assertSame(0.0, (float)($defaultAfter['numeric_value'] ?? -1));
                $harness->assertSame('within_one_year', (string)($defaultProvenance['classification'] ?? ''));
                $harness->assertSame(0, (int)($defaultProvenance['revision'] ?? -1));
                $harness->assertSame('current', (string)($service->getRunFreshness($defaultRunId)['state'] ?? ''));

                $legacySource = json_encode([
                    'calculation_type' => 'derived',
                    'source_key' => 'legacy',
                ], JSON_THROW_ON_ERROR);
                InterfaceDB::prepareExecute(
                    'UPDATE ixbrl_generation_facts
                     SET source_json = :source_json
                     WHERE run_id = :run_id
                       AND fact_key IN (:within_key, :after_key)',
                    [
                        'source_json' => $legacySource,
                        'run_id' => $defaultRunId,
                        'within_key' => 'creditors_within_one_year',
                        'after_key' => 'creditors_after_one_year',
                    ]
                );
                $harness->assertSame(
                    'unverifiable',
                    (string)($service->getRunFreshness($defaultRunId)['state'] ?? '')
                );
                InterfaceDB::prepareExecute(
                    'UPDATE ixbrl_generation_facts
                     SET source_json = :source_json
                     WHERE run_id = :run_id
                       AND fact_key = :fact_key',
                    [
                        'source_json' => (string)$defaultWithin['source_json'],
                        'run_id' => $defaultRunId,
                        'fact_key' => 'creditors_within_one_year',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'UPDATE ixbrl_generation_facts
                     SET source_json = :source_json
                     WHERE run_id = :run_id
                       AND fact_key = :fact_key',
                    [
                        'source_json' => (string)$defaultAfter['source_json'],
                        'run_id' => $defaultRunId,
                        'fact_key' => 'creditors_after_one_year',
                    ]
                );
                $harness->assertSame('current', (string)($service->getRunFreshness($defaultRunId)['state'] ?? ''));

                $saved = $presentationService->save(
                    $companyId,
                    $periodId,
                    'after_more_than_one_year',
                    'test'
                );
                $harness->assertSame(true, (bool)($saved['success'] ?? false));
                $defaultFreshness = $service->getRunFreshness($defaultRunId);
                $harness->assertSame('stale', (string)($defaultFreshness['state'] ?? ''));
                $staleReadiness = (new \eel_accounts\Service\IxbrlReadinessService())
                    ->getReadiness($companyId, $periodId);
                $harness->assertSame(false, (bool)($staleReadiness['facts_current'] ?? true));
                $harness->assertSame(false, (bool)($staleReadiness['can_generate'] ?? true));
                $harness->assertSame('ready', (string)InterfaceDB::fetchColumn(
                    'SELECT status FROM ixbrl_generation_runs WHERE id = :id',
                    ['id' => $defaultRunId]
                ));

                $longTermRunId = $service->buildFacts($companyId, $periodId);
                $longTermWithin = ixbrlFactBuilderFact($longTermRunId, 'creditors_within_one_year');
                $longTermAfter = ixbrlFactBuilderFact($longTermRunId, 'creditors_after_one_year');
                $longTermSource = json_decode((string)($longTermAfter['source_json'] ?? ''), true);
                $longTermProvenance = (array)($longTermSource['director_loan_reporting_presentation'] ?? []);

                $harness->assertTrue($longTermRunId > $defaultRunId);
                $harness->assertSame(0.0, (float)($longTermWithin['numeric_value'] ?? -1));
                $harness->assertSame(500.0, (float)($longTermAfter['numeric_value'] ?? 0));
                $harness->assertSame('after_more_than_one_year', (string)($longTermProvenance['classification'] ?? ''));
                $harness->assertSame(1, (int)($longTermProvenance['revision'] ?? 0));
                $harness->assertSame('current', (string)($service->getRunFreshness($longTermRunId)['state'] ?? ''));
                $currentReadiness = (new \eel_accounts\Service\IxbrlReadinessService())
                    ->getReadiness($companyId, $periodId);
                $harness->assertSame(true, (bool)($currentReadiness['facts_current'] ?? false));

                $idempotent = $presentationService->save(
                    $companyId,
                    $periodId,
                    'after_more_than_one_year',
                    'test'
                );
                $harness->assertSame(false, (bool)($idempotent['changed'] ?? true));
                $harness->assertSame('current', (string)($service->getRunFreshness($longTermRunId)['state'] ?? ''));

                $reverted = $presentationService->save($companyId, $periodId, 'within_one_year', 'test');
                $harness->assertSame(2, (int)($reverted['revision'] ?? 0));
                $harness->assertSame('stale', (string)($service->getRunFreshness($longTermRunId)['state'] ?? ''));
                $harness->assertSame(
                    500.0,
                    (float)(ixbrlFactBuilderFact($defaultRunId, 'creditors_within_one_year')['numeric_value'] ?? 0)
                );
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);

function ixbrlFactBuilderDirectorLoanFixture(): array
{
    $suffix = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
    $companyNumber = 'IF' . strtoupper(substr($suffix, 0, 8));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number)
         VALUES (:company_name, :company_number)',
        ['company_name' => 'iXBRL Fact DLA Fixture Limited', 'company_number' => $companyNumber]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number',
        ['company_number' => $companyNumber]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'Fact DLA AP',
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    $periodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id',
        ['company_id' => $companyId]
    );

    $assetSubtypeId = ixbrlFactBuilderDlaSubtype('bank', 'Bank', 'asset');
    $liabilitySubtypeId = ixbrlFactBuilderDlaSubtype(
        'director_loan_liability',
        'Director Loan Liability',
        'liability'
    );
    $assetNominalId = ixbrlFactBuilderDlaNominal(
        'IFA' . $suffix,
        'Fact Fixture Bank',
        'asset',
        $assetSubtypeId
    );
    $liabilityNominalId = ixbrlFactBuilderDlaNominal(
        'IFL' . $suffix,
        'Fact Fixture Director Loan Liability',
        'liability',
        $liabilitySubtypeId
    );
    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('director_loan_liability_nominal_id', $liabilityNominalId, 'int');
    $settings->flush();

    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id, accounting_period_id, source_type, source_ref,
            journal_date, description, is_posted
         ) VALUES (
            :company_id, :accounting_period_id, :source_type, :source_ref,
            :journal_date, :description, 1
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'source_type' => 'manual',
            'source_ref' => 'fact-dla:' . $suffix,
            'journal_date' => '2025-12-31',
            'description' => 'Director lent cash to company',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => $companyId, 'source_ref' => 'fact-dla:' . $suffix]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 500.00, 0.00, :description)',
        ['journal_id' => $journalId, 'nominal_account_id' => $assetNominalId, 'description' => 'Cash']
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 0.00, 500.00, :description)',
        ['journal_id' => $journalId, 'nominal_account_id' => $liabilityNominalId, 'description' => 'Director Loan Liability']
    );

    return ['company_id' => $companyId, 'accounting_period_id' => $periodId];
}

function ixbrlFactBuilderDlaSubtype(string $code, string $name, string $accountType): int
{
    $id = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_account_subtypes WHERE code = :code',
        ['code' => $code]
    );
    if ($id > 0) {
        return $id;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_account_subtypes (code, name, parent_account_type)
         VALUES (:code, :name, :account_type)',
        ['code' => $code, 'name' => $name, 'account_type' => $accountType]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_account_subtypes WHERE code = :code',
        ['code' => $code]
    );
}

function ixbrlFactBuilderDlaNominal(string $code, string $name, string $accountType, int $subtypeId): int
{
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id)
         VALUES (:code, :name, :account_type, :subtype_id)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'subtype_id' => $subtypeId,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code',
        ['code' => $code]
    );
}

function ixbrlFactBuilderFact(int $runId, string $factKey): array
{
    $row = InterfaceDB::fetchOne(
        'SELECT *
         FROM ixbrl_generation_facts
         WHERE run_id = :run_id
           AND fact_key = :fact_key
         LIMIT 1',
        ['run_id' => $runId, 'fact_key' => $factKey]
    );

    return is_array($row) ? $row : [];
}
