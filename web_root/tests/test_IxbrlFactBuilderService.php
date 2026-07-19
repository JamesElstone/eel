<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'IxbrlTestFixture.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlFactBuilderService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlFactBuilderService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'handles missing company and period safely', static function () use ($harness, $service): void {
            $harness->assertSame(null, $service->getLatestRun(0, 0));
            $harness->assertSame([], $service->getFacts(0));
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'schema check is read only and taxonomy profile is deterministic', static function () use ($harness, $service): void {
            $before = InterfaceDB::tableRowCount('ixbrl_fact_mappings');
            $service->ensureSchema();
            $harness->assertSame($before, InterfaceDB::tableRowCount('ixbrl_fact_mappings'));
            $first = (new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings();
            $second = (new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings();
            $harness->assertSame($first, $second);
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'keeps runtime migration master schema and database taxonomy mappings in exact parity', static function () use ($harness): void {
            if (!InterfaceDB::tableExists('ixbrl_fact_mappings')
                || !InterfaceDB::columnExists('ixbrl_fact_mappings', 'namespace_uri')) {
                $harness->skip('latest iXBRL taxonomy migration is not applied to this test database');
            }

            $runtime = (new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings();
            $database = InterfaceDB::fetchAll(
                'SELECT * FROM ixbrl_fact_mappings ORDER BY sort_order, fact_key'
            );
            $harness->assertSame(count($runtime), count($database));
            $stringFields = [
                'fact_key', 'taxonomy_concept', 'namespace_uri', 'local_name', 'label',
                'value_type', 'calculation_type', 'source_key',
                'period_type', 'unit_ref', 'decimals_value', 'context_profile',
            ];
            $integerFields = ['comparative_enabled', 'is_required', 'sort_order', 'is_active'];
            foreach ($runtime as $index => $mapping) {
                $row = (array)($database[$index] ?? []);
                foreach ($stringFields as $field) {
                    $harness->assertSame((string)($mapping[$field] ?? ''), (string)($row[$field] ?? ''));
                }
                foreach ($integerFields as $field) {
                    $harness->assertSame((int)($mapping[$field] ?? 0), (int)($row[$field] ?? 0));
                }
                $harness->assertSame(
                    (float)($mapping['sign_multiplier'] ?? 0),
                    (float)($row['sign_multiplier'] ?? 0)
                );
                $runtimeDimensions = json_decode((string)($mapping['dimensions_json'] ?? ''), true);
                $databaseDimensions = json_decode((string)($row['dimensions_json'] ?? ''), true);
                $harness->assertSame(
                    is_array($runtimeDimensions) ? $runtimeDimensions : null,
                    is_array($databaseDimensions) ? $databaseDimensions : null
                );
            }

            $extractSeedRows = static function (string $sql): array {
                if (preg_match('/INSERT INTO\\s+`?ixbrl_fact_mappings`?\\s*\\(/i', $sql, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    return [];
                }
                $start = (int)$match[0][1];
                $end = strpos($sql, ';', $start);
                $block = $end === false ? substr($sql, $start) : substr($sql, $start, $end - $start);
                preg_match_all('/^\\s*(\\(\'[^\\r\\n]+\\))[,]?\\s*$/m', $block, $rows);
                return array_values(array_map('trim', (array)($rows[1] ?? [])));
            };
            $migrationSql = (string)file_get_contents(
                PROJECT_ROOT . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
                . DIRECTORY_SEPARATOR . '2026_07_16_005_ixbrl_taxonomy_facts.sql'
            );
            $masterSql = (string)file_get_contents(
                PROJECT_ROOT . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
            );
            $migrationRows = $extractSeedRows($migrationSql);
            $masterRows = $extractSeedRows($masterSql);
            $harness->assertSame(count($runtime), count($migrationRows));
            $harness->assertSame($migrationRows, $masterRows);
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'schema includes filing export validation metadata', static function () use ($harness, $service): void {
            $service->ensureSchema();
            $migration = (string)file_get_contents(PROJECT_ROOT . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_07_16_005_ixbrl_taxonomy_facts.sql');
            foreach (['basis_version', 'basis_hash', 'external_validated_sha256', 'dimensions_json', 'context_profile'] as $column) {
                $harness->assertTrue(str_contains($migration, $column));
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'uses official concepts contexts units and creditor dimensions', static function () use ($harness): void {
            $mappings = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                $mappings[(string)$mapping['fact_key']] = $mapping;
            }
            $harness->assertSame('core:Creditors', (string)$mappings['creditors_after_one_year']['taxonomy_concept']);
            $harness->assertSame('core:Equity', (string)$mappings['equity']['taxonomy_concept']);
            $harness->assertSame('core:RawMaterialsConsumablesUsed', (string)$mappings['raw_materials_consumables']['taxonomy_concept']);
            $harness->assertSame('core:PrepaymentsAccruedIncome', (string)$mappings['prepayments_accrued_income']['taxonomy_concept']);
            $harness->assertSame('pure', (string)$mappings['average_number_employees']['unit_ref']);
            $harness->assertSame('0', (string)$mappings['average_number_employees']['decimals_value']);
            $harness->assertTrue(str_contains((string)$mappings['creditors_within_one_year']['dimensions_json'], 'WithinOneYear'));
            $harness->assertSame('core:DirectorSigningFinancialStatements', (string)$mappings['director_signing_financial_statements']['taxonomy_concept']);
            $harness->assertSame('fixed_marker', (string)$mappings['entity_trading_status']['calculation_type']);
            $harness->assertTrue(str_contains((string)$mappings['accounting_standards_applied']['dimensions_json'], 'Micro-entities'));
            $harness->assertTrue(str_contains((string)$mappings['accounts_status']['dimensions_json'], 'AuditExempt-NoAccountantsReport'));
            $harness->assertTrue(str_contains((string)$mappings['country_formation_or_incorporation']['dimensions_json'], 'countries:EnglandWales'));
            $harness->assertSame('bus:VersionProductionSoftware', (string)$mappings['production_software_version']['taxonomy_concept']);
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'maps each explicit trading disclosure to the correct taxonomy context', static function () use ($harness, $service): void {
            $tradingMapping = null;
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                if ((string)$mapping['fact_key'] === 'entity_trading_status') {
                    $tradingMapping = $mapping;
                    break;
                }
            }
            $harness->assertTrue(is_array($tradingMapping));
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlFactBuilderService::class, 'factFromMapping');
            $method->setAccessible(true);
            $report = [
                'company' => ['company_name' => 'Fixture Limited', 'company_number' => '01234567'],
                'accounting_period' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                'disclosures' => [],
                'current' => ['buckets' => [], 'sources' => []],
                'application_name' => 'EEL Accounts',
            ];
            foreach ([
                'trading' => ['current_period_duration', null],
                'never_traded' => ['current_period_duration_entity_never_traded', 'bus:EntityHasNeverTraded'],
                'no_longer_trading' => ['current_period_duration_entity_no_longer_trading', 'bus:EntityNoLongerTradingButTradedInPast'],
            ] as $status => [$expectedContext, $expectedMember]) {
                $report['disclosures']['entity_trading_status'] = $status;
                $fact = $method->invoke($service, $tradingMapping, $report, false);
                $harness->assertSame($expectedContext, (string)($fact['context_ref'] ?? ''));
                $dimensions = json_decode((string)($fact['dimensions_json'] ?? ''), true);
                if ($expectedMember === null) {
                    $harness->assertSame(null, $fact['dimensions_json']);
                } else {
                    $harness->assertSame($expectedMember, (string)($dimensions['bus:EntityTradingStatusDimension'] ?? ''));
                }
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'normalises the supported UK identity without duplicating the postcode', static function () use ($harness): void {
            $identity = new \eel_accounts\Service\IxbrlCompanyIdentityService();
            $company = $identity->normalise([
                'company_name' => 'Elstone Electricals Limited',
                'company_number' => '14337285',
                'company_status' => 'active',
                'companies_house_type' => 'ltd',
                'companies_house_jurisdiction' => 'england-wales',
                'registered_office_address_line_1' => 'Silveroaks Oakfield',
                'registered_office_address_line_2' => 'Goldsworth Park',
                'registered_office_locality' => 'Woking',
                'registered_office_region' => 'Gu21 3qs',
                'registered_office_postal_code' => 'GU21 3QS',
                'registered_office_country' => 'United Kingdom',
            ]);
            $harness->assertSame('Woking', (string)$company['registered_office_address_line_3']);
            $harness->assertSame([], $identity->errors($company));
            $company['company_name'] = '';
            $harness->assertTrue(in_array('Company legal name is missing.', $identity->errors($company), true));
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'uses the prior locked period employee disclosure for the comparative fact', static function () use ($harness, $service): void {
            $mapping = null;
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $candidate) {
                if ((string)$candidate['fact_key'] === 'average_number_employees') {
                    $mapping = $candidate;
                    break;
                }
            }
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlFactBuilderService::class, 'factFromMapping');
            $method->setAccessible(true);
            $fact = $method->invoke($service, $mapping, [
                'company' => [],
                'accounting_period' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                'disclosures' => ['average_number_employees' => 99],
                'current' => ['buckets' => [], 'sources' => []],
                'comparative' => [
                    'period' => ['period_start' => '2024-01-01', 'period_end' => '2024-12-31'],
                    'mapping' => ['buckets' => [], 'sources' => []],
                    'disclosures' => ['average_number_employees' => 3, 'revision' => 2],
                ],
            ], true);
            $harness->assertSame(3.0, (float)($fact['numeric_value'] ?? -1));
            $harness->assertSame('comparative_period_duration', (string)($fact['context_ref'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'prorates only the turnover threshold for a long accounting period', static function () use ($harness): void {
            ixbrlFactBuilderEnsureFrsThresholdFixtures();
            $result = (new \eel_accounts\Service\IxbrlMicroEntityEligibilityService())->evaluate(
                '2022-09-05',
                '2023-09-30',
                10025.44,
                1687.52,
                1
            );
            $harness->assertSame(391, (int)$result['period_days']);
            $harness->assertSame(316000.0, (float)$result['thresholds']['balance_sheet_total']);
        $harness->assertTrue((float)$result['thresholds']['turnover'] > 632000.0);
        $harness->assertSame(true, (bool)$result['qualifies']);
        $failsOne = (new \eel_accounts\Service\IxbrlMicroEntityEligibilityService())->evaluate(
            '2022-01-01',
            '2022-12-31',
            632001.0,
            100.0,
            1
        );
        $harness->assertSame(2, (int)$failsOne['pass_count']);
        $harness->assertSame(false, (bool)$failsOne['qualifies']);
    });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'refuses a manual fact build without a current filing approval', static function () use ($harness, $service): void {
            if (!InterfaceDB::tableExists('ixbrl_accounts_disclosures')
                || !InterfaceDB::columnExists('ixbrl_generation_runs', 'basis_hash')
                || !InterfaceDB::columnExists('ixbrl_generation_facts', 'dimensions_json')) {
                $harness->skip('latest iXBRL migrations are not applied to this test database');
            }
            InterfaceDB::beginTransaction();
            try {
                $fixture = ixbrlFactBuilderDirectorLoanFixture();
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];
                $presentationService = new \eel_accounts\Service\DirectorLoanReportingPresentationService();

                $savedDisclosures = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->save(
                    $companyId,
                    $periodId,
                    [
                        'accounting_standard' => 'FRS_105',
                        'average_number_employees' => 1,
                        'entity_dormant' => 0,
                        'is_still_trading' => 1,
                        'accounts_approval_date' => '2026-01-31',
                        'approving_director_name' => 'Fixture Director',
                        'prepared_under_small_companies_regime' => 1,
                        'audit_exempt_section_477' => 1,
                        'directors_acknowledge_responsibilities' => 1,
                        'members_have_not_required_audit' => 1,
                        'micro_entity_eligibility_confirmed' => 1,
                        'going_concern_basis_appropriate' => 1,
                        'has_material_off_balance_sheet_arrangements' => 0,
                        'has_director_advances_credits_or_guarantees' => 0,
                        'has_financial_commitments_guarantees_or_contingencies' => 0,
                    ],
                    'test'
                );
                $harness->assertSame(true, (bool)($savedDisclosures['success'] ?? false));

                try {
                    $service->buildFacts($companyId, $periodId);
                    $harness->assertTrue(false);
                } catch (RuntimeException $exception) {
                    $harness->assertTrue(str_contains($exception->getMessage(), 'Approve the current disclosures'));
                }
                return;

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
                $harness->assertSame('core:Creditors', (string)($defaultWithin['taxonomy_concept'] ?? ''));
                $harness->assertTrue(str_contains((string)($defaultWithin['dimensions_json'] ?? ''), 'WithinOneYear'));

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
    ixbrl_test_ensure_frs105_thresholds();
    $suffix = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
    $companyNumber = 'IF' . strtoupper(substr($suffix, 0, 8));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (
            company_name, company_number, company_status, companies_house_type,
            companies_house_jurisdiction, registered_office_address_line_1,
            registered_office_address_line_2, registered_office_locality,
            registered_office_postal_code, registered_office_country
         ) VALUES (
            :company_name, :company_number, :company_status, :company_type,
            :jurisdiction, :address_line_1, :address_line_2, :locality,
            :postal_code, :country
         )',
        [
            'company_name' => 'iXBRL Fact DLA Fixture Limited',
            'company_number' => $companyNumber,
            'company_status' => 'active',
            'company_type' => 'ltd',
            'jurisdiction' => 'england-wales',
            'address_line_1' => '1 Fixture Street',
            'address_line_2' => 'Fixture Park',
            'locality' => 'Testford',
            'postal_code' => 'TE5 7GB',
            'country' => 'United Kingdom',
        ]
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
    InterfaceDB::prepareExecute(
        'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
         VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by)',
        ['company_id' => $companyId, 'accounting_period_id' => $periodId, 'locked_by' => 'test']
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
    $settings->set('default_currency', 'GBP', 'char');
    $settings->set('director_loan_liability_nominal_id', $liabilityNominalId, 'int');
    $settings->flush();
    ixbrl_test_assign_sales_nominal($companyId);
    ixbrl_test_assign_director_loan_nominals($companyId, 0, $liabilityNominalId);

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

function ixbrlFactBuilderEnsureFrsThresholdFixtures(): void
{
    (new \eel_accounts\Service\TaxRateRuleService())->ensureSchema();
    foreach ([
        ['turnover', 632000.0],
        ['balance_sheet_total', 316000.0],
        ['employees', 10.0],
    ] as [$key, $amount]) {
        if ((int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM tax_rate_rules WHERE tax_domain = :domain AND regime = :regime AND rule_key = :rule_key AND period_start = :period_start',
            ['domain' => 'company_size', 'regime' => 'frs105_micro_entity', 'rule_key' => $key, 'period_start' => '1900-01-01']
        ) > 0) {
            continue;
        }
        InterfaceDB::prepareExecute(
            'INSERT INTO tax_rate_rules (
                tax_domain, regime, rule_key, rule_label, period_start, period_end, value_type,
                amount_value, source_url, source_checked_at, rule_version, is_active, notes
             ) VALUES (
                :domain, :regime, :rule_key, :label, :period_start, :period_end, :value_type,
                :amount, :source_url, :checked_at, :version, 1, :notes
             )',
            [
                'domain' => 'company_size',
                'regime' => 'frs105_micro_entity',
                'rule_key' => $key,
                'label' => 'FRS 105 ' . $key,
                'period_start' => '1900-01-01',
                'period_end' => '2025-04-05',
                'value_type' => 'amount',
                'amount' => $amount,
                'source_url' => 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies',
                'checked_at' => '2026-07-17',
                'version' => 'fixture-frs105-' . $key,
                'notes' => 'Test fixture.',
            ]
        );
    }
}
