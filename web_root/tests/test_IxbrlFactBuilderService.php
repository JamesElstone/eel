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

            $quote = static fn(mixed $value): string => $value === null
                ? 'NULL'
                : "'" . str_replace("'", "''", (string)$value) . "'";
            $seedRow = static function (array $mapping) use ($quote): string {
                return '(' . implode(',', [
                    $quote($mapping['fact_key']),
                    $quote($mapping['taxonomy_concept']),
                    $quote($mapping['namespace_uri']),
                    $quote($mapping['local_name']),
                    $quote($mapping['label']),
                    $quote($mapping['value_type']),
                    $quote($mapping['calculation_type']),
                    $quote($mapping['source_key']),
                    (string)(float)$mapping['sign_multiplier'],
                    $quote($mapping['period_type']),
                    $quote($mapping['unit_ref']),
                    $quote($mapping['decimals_value']),
                    $quote($mapping['context_profile']),
                    $quote($mapping['dimensions_json']),
                    (string)(int)$mapping['comparative_enabled'],
                    (string)(int)$mapping['is_required'],
                    (string)(int)$mapping['sort_order'],
                    (string)(int)$mapping['is_active'],
                ]) . ')';
            };
            $correctionSql = (string)file_get_contents(
                PROJECT_ROOT . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
                . DIRECTORY_SEPARATOR . '2026_07_26_001_ixbrl_frs105_taxonomy_profile.sql'
            );
            $masterSql = (string)file_get_contents(
                PROJECT_ROOT . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
            );
            foreach ($runtime as $mapping) {
                $harness->assertTrue(str_contains($masterSql, $seedRow($mapping)));
            }
            foreach ([
                'director_loan_statement',
                'director_loan_numeric',
                'PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
                'AccountsTypeDimension',
                'AdvancesCreditsRepaidInPeriodDirectors',
            ] as $requiredCorrection) {
                $harness->assertTrue(str_contains($correctionSql, $requiredCorrection));
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'schema includes filing export validation metadata', static function () use ($harness, $service): void {
            $service->ensureSchema();
            $migration = (string)file_get_contents(PROJECT_ROOT . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_07_16_005_ixbrl_taxonomy_facts.sql');
            foreach (['basis_version', 'basis_hash', 'external_validated_sha256', 'dimensions_json', 'context_profile'] as $column) {
                $harness->assertTrue(str_contains($migration, $column));
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'builds conditional Companies House election and Directors Report provenance facts', static function () use ($harness, $service): void {
            $mappings = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                $mappings[(string)$mapping['fact_key']] = $mapping;
            }
            $build = new ReflectionMethod($service, 'factFromMapping');
            $build->setAccessible(true);
            $directorReport = [
                'review_notes' => 'Opening narrative.',
                'review_notes_hash' => str_repeat('a', 64),
                'confirmation_sentences' => ['One.', 'Two!'],
                'source_acknowledgements' => [[
                    'check_code' => 'director_loan',
                    'basis_hash' => str_repeat('b', 64),
                    'note' => 'One. Two!',
                ]],
            ];
            $report = [
                'company' => [],
                'accounting_period' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                'disclosures' => [
                    'directors_report_exempt_section_415a' => 0,
                    'profit_loss_not_delivered_section_444' => 1,
                    'accounts_approval_date' => '2026-02-01',
                    'approving_director_name' => 'Fixture Director',
                ],
                'current' => ['buckets' => [], 'sources' => []],
                'director_report' => $directorReport,
            ];

            $election = $build->invoke($service, $mappings['profit_loss_not_delivered_statement'], $report, false);
            $statement = $build->invoke($service, $mappings['directors_report_small_companies_statement'], $report, false);
            $signingDate = $build->invoke($service, $mappings['directors_report_signing_date'], $report, false);
            $signingMarker = $build->invoke($service, $mappings['director_signing_directors_report'], $report, false);
            $harness->assertTrue(str_contains((string)$election['text_value'], 'section 444(5A)'));
            $harness->assertSame($directorReport, $statement['source']['director_report']);
            $harness->assertSame('2026-02-01', (string)$signingDate['date_value']);
            $harness->assertSame('', (string)$signingMarker['text_value']);

            $report['disclosures']['directors_report_exempt_section_415a'] = 1;
            $harness->assertSame(null, $build->invoke($service, $mappings['directors_report_small_companies_statement'], $report, false));
            $harness->assertSame(null, $build->invoke($service, $mappings['directors_report_signing_date'], $report, false));
            $harness->assertSame(null, $build->invoke($service, $mappings['director_signing_directors_report'], $report, false));
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'uses official concepts contexts units and creditor dimensions', static function () use ($harness): void {
            $mappings = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                $mappings[(string)$mapping['fact_key']] = $mapping;
            }
            $harness->assertSame('core:Creditors', (string)$mappings['creditors_after_one_year']['taxonomy_concept']);
            $harness->assertSame('core:Equity', (string)$mappings['equity']['taxonomy_concept']);
            $harness->assertSame('core:RawMaterialsConsumablesUsed', (string)$mappings['raw_materials_consumables']['taxonomy_concept']);
            $harness->assertSame('core:GrossProfitLoss', (string)$mappings['gross_profit_loss']['taxonomy_concept']);
            $harness->assertSame('core:OperatingProfitLoss', (string)$mappings['operating_profit_loss']['taxonomy_concept']);
            $harness->assertSame('core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal', (string)$mappings['prepayments_accrued_income']['taxonomy_concept']);
            $harness->assertSame('pure', (string)$mappings['average_number_employees']['unit_ref']);
            $harness->assertSame('0', (string)$mappings['average_number_employees']['decimals_value']);
            $harness->assertSame(
                'bus:DescriptionPrincipalActivities',
                (string)$mappings['principal_activity_description']['taxonomy_concept']
            );
            $harness->assertSame(false, (bool)$mappings['principal_activity_description']['comparative_enabled']);
            $harness->assertTrue(str_contains((string)$mappings['creditors_within_one_year']['dimensions_json'], 'WithinOneYear'));
            $harness->assertSame('core:DirectorSigningFinancialStatements', (string)$mappings['director_signing_financial_statements']['taxonomy_concept']);
            $harness->assertSame('fixed_marker', (string)$mappings['entity_trading_status']['calculation_type']);
            $harness->assertTrue(str_contains((string)$mappings['accounts_type']['dimensions_json'], 'bus:FullAccounts'));
            $harness->assertTrue(str_contains((string)$mappings['accounting_standards_applied']['dimensions_json'], 'Micro-entities'));
            $harness->assertTrue(str_contains((string)$mappings['accounts_status']['dimensions_json'], 'AuditExempt-NoAccountantsReport'));
            $harness->assertTrue(str_contains((string)$mappings['country_formation_or_incorporation']['dimensions_json'], 'countries:EnglandWales'));
            $harness->assertSame('bus:VersionProductionSoftware', (string)$mappings['production_software_version']['taxonomy_concept']);
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'builds the principal activity statement as a current duration fact only', static function () use ($harness, $service): void {
            $mapping = null;
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $candidate) {
                if ((string)$candidate['fact_key'] === 'principal_activity_description') {
                    $mapping = $candidate;
                    break;
                }
            }
            $harness->assertTrue(is_array($mapping));
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlFactBuilderService::class, 'factFromMapping');
            $method->setAccessible(true);
            $fact = $method->invoke($service, $mapping, [
                'company' => [],
                'accounting_period' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                'disclosures' => [
                    'principal_activity_statement' => 'The principal activity of the company during the period was Electrical installation.',
                    'revision' => 3,
                ],
                'current' => ['buckets' => [], 'sources' => []],
            ], false);

            $harness->assertSame('bus:DescriptionPrincipalActivities', (string)($fact['taxonomy_concept'] ?? ''));
            $harness->assertSame('current_period_duration', (string)($fact['context_ref'] ?? ''));
            $harness->assertSame(
                'The principal activity of the company during the period was Electrical installation.',
                (string)($fact['text_value'] ?? '')
            );
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

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'builds report dates accounts type and standard zero rows with the selected contexts', static function () use ($harness, $service): void {
            $mappings = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                $mappings[(string)$mapping['fact_key']] = $mapping;
            }
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlFactBuilderService::class, 'factFromMapping');
            $method->setAccessible(true);
            $report = [
                'company' => [],
                'accounting_period' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                'disclosures' => ['accounts_approval_date' => '2026-01-31'],
                'director_loan_disclosure' => ['disclosures' => []],
                'current' => ['buckets' => [], 'sources' => []],
            ];

            $periodStart = $method->invoke($service, $mappings['period_start'], $report, false);
            $periodEnd = $method->invoke($service, $mappings['period_end'], $report, false);
            $approval = $method->invoke($service, $mappings['accounts_approval_date'], $report, false);
            $accountsType = $method->invoke($service, $mappings['accounts_type'], $report, false);
            $harness->assertSame('2025-01-01', (string)$periodStart['date_value']);
            $harness->assertSame('2026-01-31', (string)$approval['date_value']);
            foreach ([$periodStart, $periodEnd, $approval] as $fact) {
                $harness->assertSame('current_period_end', (string)$fact['context_ref']);
            }
            $harness->assertSame('', (string)$accountsType['text_value']);
            $harness->assertSame('current_period_duration_accounts_type', (string)$accountsType['context_ref']);
            $harness->assertSame(
                ['bus:AccountsTypeDimension' => 'bus:FullAccounts'],
                json_decode((string)$accountsType['dimensions_json'], true)
            );

            foreach ([
                'called_up_share_capital_not_paid',
                'provisions_for_liabilities',
                'accruals_deferred_income',
            ] as $factKey) {
                $fact = $method->invoke($service, $mappings[$factKey], $report, false);
                $harness->assertSame(0.0, (float)$fact['numeric_value']);
                $harness->assertSame('current_period_end', (string)$fact['context_ref']);
                $harness->assertSame('GBP', (string)$fact['unit_ref']);
                $harness->assertSame('2', (string)$fact['decimals_value']);
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'builds the selected officer snapshot beside an empty director signing marker', static function () use ($harness, $service): void {
            $mappings = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                $mappings[(string)$mapping['fact_key']] = $mapping;
            }
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlFactBuilderService::class, 'factFromMapping');
            $method->setAccessible(true);
            $report = [
                'company' => [],
                'accounting_period' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                'disclosures' => [
                    'approving_director_name' => 'Selected Director Snapshot',
                    'revision' => 4,
                ],
                'director_loan_disclosure' => ['disclosures' => []],
                'current' => ['buckets' => [], 'sources' => []],
            ];

            $name = $method->invoke($service, $mappings['approving_director_name'], $report, false);
            $marker = $method->invoke(
                $service,
                $mappings['director_signing_financial_statements'],
                $report,
                false
            );
            $harness->assertSame('Selected Director Snapshot', (string)$name['text_value']);
            $harness->assertSame('', (string)$marker['text_value']);
            $harness->assertSame(
                'current_period_duration_director_1',
                (string)$name['context_ref']
            );
            $harness->assertSame((string)$name['context_ref'], (string)$marker['context_ref']);
            $harness->assertSame(
                ['bus:EntityOfficersDimension' => 'bus:Director1'],
                json_decode((string)$name['dimensions_json'], true)
            );
            $harness->assertSame(
                json_decode((string)$name['dimensions_json'], true),
                json_decode((string)$marker['dimensions_json'], true)
            );
            $harness->assertSame(4, (int)$name['source']['disclosure_revision']);
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'builds only explicit director advances cash repayments and closing advance totals', static function () use ($harness, $service): void {
            $mappings = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                $mappings[(string)$mapping['fact_key']] = $mapping;
            }
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlFactBuilderService::class, 'factFromMapping');
            $method->setAccessible(true);
            $report = [
                'company' => [],
                'accounting_period' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                'disclosures' => [],
                'current' => ['buckets' => [], 'sources' => []],
                'director_loan_disclosure' => [
                    'has_company_to_director_exposure' => true,
                    'total_advances' => 1349.0,
                    'total_repayments' => 140.0,
                    'total_cash_repayments' => 1089.0,
                    'party_facts' => [
                        ['party_id' => 10, 'linked_director_id' => 1, 'terms_source' => 'locked_snapshot', 'terms_revision' => 4],
                        ['party_id' => 20, 'linked_director_id' => 2, 'terms_source' => 'locked_snapshot', 'terms_revision' => 2],
                    ],
                    'disclosures' => [
                        [
                            'party_id' => 10,
                            'director_id' => 1,
                            'linked_director_id' => 1,
                            'is_director' => true,
                            'director_name' => 'Director One',
                            'advances' => 200.0,
                            'cash_repayments' => 50.0,
                            'closing_company_to_director_balance' => 120.0,
                        ],
                        [
                            'party_id' => 20,
                            'director_id' => 2,
                            'linked_director_id' => 2,
                            'is_director' => true,
                            'director_name' => 'Director Two',
                            'advances' => 150.0,
                            'cash_repayments' => 40.0,
                            'closing_company_to_director_balance' => 80.0,
                        ],
                        [
                            'party_id' => 30,
                            'linked_director_id' => null,
                            'is_director' => false,
                            'director_name' => 'Shareholder only',
                            'advances' => 999.0,
                            'cash_repayments' => 999.0,
                            'closing_company_to_director_balance' => 999.0,
                        ],
                    ],
                ],
                'director_loan_year_end_approval' => [
                    'basis_version' => 'year_end_section_v2',
                    'basis_hash' => str_repeat('a', 64),
                ],
            ];

            $advances = $method->invoke($service, $mappings['director_advances_made'], $report, false);
            $cashRepayments = $method->invoke($service, $mappings['director_cash_repayments'], $report, false);
            $closing = $method->invoke($service, $mappings['director_closing_advance'], $report, false);
            $harness->assertSame(350.0, (float)$advances['numeric_value']);
            $harness->assertSame(90.0, (float)$cashRepayments['numeric_value']);
            $harness->assertSame(200.0, (float)$closing['numeric_value']);
            $harness->assertSame('current_period_duration', (string)$cashRepayments['context_ref']);
            $harness->assertSame('current_period_end', (string)$closing['context_ref']);
            $harness->assertCount(2, (array)$cashRepayments['source']['source_rows']);
            $harness->assertSame(10, (int)$cashRepayments['source']['source_rows'][0]['party_id']);
            $harness->assertSame(
                4,
                (int)$cashRepayments['source']['source_rows'][0]['party_fact']['terms_revision']
            );
            $harness->assertSame(
                str_repeat('a', 64),
                (string)$cashRepayments['source']['director_loan_year_end_approval']['basis_hash']
            );

            unset($report['director_loan_disclosure']['total_cash_repayments']);
            $harness->assertSame(
                0.0,
                (float)$method->invoke($service, $mappings['director_cash_repayments'], $report, false)['numeric_value']
            );
        });

        $harness->check(\eel_accounts\Service\IxbrlFactBuilderService::class, 'emits zero numeric director facts for evidence-only zero-advance rows', static function () use ($harness, $service): void {
            $mappings = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                $mappings[(string)$mapping['fact_key']] = $mapping;
            }
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlFactBuilderService::class, 'factFromMapping');
            $method->setAccessible(true);
            $report = [
                'company' => [],
                'accounting_period' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                'disclosures' => [],
                'current' => ['buckets' => [], 'sources' => []],
                'director_loan_disclosure' => [
                    'has_company_to_director_exposure' => false,
                    'disclosures' => [[
                        'party_id' => 10,
                        'director_id' => 1,
                        'linked_director_id' => 1,
                        'is_director' => true,
                        'section_413_required' => false,
                        'advances' => 0.0,
                        'cash_repayments' => 0.0,
                        'closing_company_to_director_balance' => 0.0,
                        'closing_company_liability' => 7288.26,
                    ]],
                ],
                'director_loan_year_end_approval' => [],
            ];

            $harness->assertSame(0.0, (float)$method->invoke($service, $mappings['director_advances_made'], $report, false)['numeric_value']);
            $harness->assertSame(0.0, (float)$method->invoke($service, $mappings['director_cash_repayments'], $report, false)['numeric_value']);
            $harness->assertSame(0.0, (float)$method->invoke($service, $mappings['director_closing_advance'], $report, false)['numeric_value']);
            $harness->assertSame(
                'The company made no advances or credits (including loans) to directors during the period.',
                (string)$method->invoke($service, $mappings['no_director_advances_or_credits'], $report, false)['text_value']
            );
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
                $approvingDirector = ixbrl_test_ensure_approving_director(
                    $companyId,
                    '2026-01-31'
                );
                $principalActivitySicCode = ixbrl_test_assign_principal_activity($companyId);

                $savedDisclosures = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->save(
                    $companyId,
                    $periodId,
                    [
                        'accounting_standard' => 'FRS_105',
                        'average_number_employees' => 1,
                        'principal_activity_sic_code' => $principalActivitySicCode,
                        'entity_dormant' => 0,
                        'is_still_trading' => 1,
                        'accounts_approval_date' => '2026-01-31',
                        'approving_director_id' => (int)$approvingDirector['id'],
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
    $settings->set('participator_loan_liability_nominal_id', $liabilityNominalId, 'int');
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
