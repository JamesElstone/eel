<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompaniesHouseAccountsSubmissionService $service): void {
        $invokePrivate = static function (
            object $target,
            string $methodName,
            mixed ...$arguments
        ): mixed {
            $method = new ReflectionMethod($target, $methodName);
            $method->setAccessible(true);

            return $method->invoke($target, ...$arguments);
        };

        $harness->check($service::class, 'returns an empty context for an invalid selection', static function () use ($harness, $service): void {
            $context = $service->fetchContext(0, 0);
            $harness->assertFalse((bool)($context['can_prepare'] ?? true));
            $harness->assertTrue(str_contains(implode(' ', (array)($context['blockers'] ?? [])), 'valid company'));
        });

        $harness->check(
            $service::class,
            'applies the configured taxonomy and Gateway date policy before filing',
            static function () use ($harness, $invokePrivate): void {
                $compatibility = new \eel_accounts\Service\IxbrlTaxonomyCompatibilityService([
                    'companies_house_gateway_available_from' => '2027-01-01',
                ]);
                $guarded = new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService(
                    taxonomyCompatibilityService: $compatibility
                );
                $assessment = $invokePrivate(
                    $guarded,
                    'taxonomyCompatibilityForPeriod',
                    ['period_start' => '2022-09-05', 'period_end' => '2023-09-30'],
                    '2026-07-26'
                );

                $harness->assertSame(false, (bool)($assessment['compatible'] ?? true));
                $harness->assertTrue(str_contains(
                    implode(' ', (array)($assessment['errors'] ?? [])),
                    '2027-01-01'
                ));
                $harness->assertSame(false, (bool)($assessment['gateway_response_confirmed'] ?? true));
            }
        );

        $harness->check(
            $service::class,
            'preserves complete Arelle diagnostics in revised artifact evidence metadata',
            static function () use ($harness, $service, $invokePrivate): void {
                $validation = [
                    'status' => 'passed',
                    'version' => 'Arelle 2.43.0',
                    'validated_at' => '2026-07-26T12:00:00Z',
                    'validated_sha256' => str_repeat('a', 64),
                    'errors' => [],
                    'warnings' => ['A reviewable taxonomy warning.'],
                    'log_path' => 'logs/arelle/example.log',
                ];
                $metadata = $invokePrivate(
                    $service,
                    'revisedArtifactEvidenceMetadata',
                    987,
                    $validation,
                    73
                );

                $harness->assertSame(987, (int)$metadata['base_run_id']);
                $harness->assertSame(73, (int)$metadata['fact_count']);
                $harness->assertSame(
                    $validation,
                    (array)$metadata['arelle_validation']
                );
                $harness->assertSame(
                    ['A reviewable taxonomy warning.'],
                    (array)$metadata['arelle_validation']['warnings']
                );
                $harness->assertSame(
                    'logs/arelle/example.log',
                    (string)$metadata['arelle_validation']['log_path']
                );
            }
        );

        $harness->check(
            $service::class,
            'marks a prepared artifact current only for the matching filing-ready Accounting run and intact file',
            static function () use ($harness, $service, $invokePrivate): void {
                $path = tempnam(test_tmp_directory(), 'ch-current-artifact-');
                if ($path === false) {
                    $harness->skip('Could not create a temporary revised-accounts artifact.');
                }
                file_put_contents(
                    $path,
                    '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:ix="http://www.xbrl.org/2013/inlineXBRL">'
                    . '<body><ix:nonNumeric name="bus:Name" contextRef="current">Example</ix:nonNumeric>'
                    . '<ix:nonFraction name="core:Assets" contextRef="current" unitRef="GBP">1</ix:nonFraction>'
                    . '</body></html>'
                );
                try {
                    $submission = [
                        'company_id' => 49,
                        'accounting_period_id' => 79,
                        'ixbrl_generation_run_id' => 18,
                        'revised_artifact_path' => $path,
                        'revised_artifact_sha256' => hash_file('sha256', $path),
                        'basis_hash' => str_repeat('b', 64),
                    ];
                    $current = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 18,
                    ]);
                    $harness->assertSame('current', (string)$current['state']);
                    $harness->assertSame(true, (bool)$current['current']);
                    $harness->assertSame(2, (int)$current['fact_count']);

                    $stale = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 19,
                    ]);
                    $harness->assertSame('stale', (string)$stale['state']);
                    $harness->assertTrue(str_contains(
                        implode(' ', (array)$stale['errors']),
                        'earlier Accounting iXBRL run'
                    ));

                    file_put_contents($path, '<html>changed</html>');
                    $tampered = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 18,
                    ]);
                    $harness->assertSame('tampered', (string)$tampered['state']);
                    $harness->assertSame(false, (bool)$tampered['current']);
                } finally {
                    @unlink($path);
                }
            }
        );

        $harness->check(
            $service::class,
            'uses the frozen disclosure basis as the authoritative revised-accounts approval date',
            static function () use ($harness, $service, $invokePrivate): void {
                $approval = [
                    'approved_at' => '2026-07-17 09:30:00',
                    'basis_json' => json_encode([
                        'disclosures' => [
                            'values' => [
                                'accounts_approval_date' => '2026-07-24',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];

                $date = $invokePrivate(
                    $service,
                    'authoritativeRevisionApprovalDate',
                    $approval,
                    ['revision_approval_date' => '2026-07-24'],
                    '2026-07-24'
                );

                $harness->assertSame('2026-07-24', $date);
                $harness->assertFalse(str_starts_with((string)$date, '2026-07-17'));
            }
        );

        $harness->check(
            $service::class,
            'rejects supplied or current approval dates that conflict with the frozen basis',
            static function () use ($harness, $service, $invokePrivate): void {
                $approval = [
                    'approved_at' => '2026-07-17 09:30:00',
                    'basis_json' => json_encode([
                        'disclosures' => [
                            'values' => [
                                'accounts_approval_date' => '2026-07-24',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];

                $suppliedConflict = null;
                try {
                    $invokePrivate(
                        $service,
                        'authoritativeRevisionApprovalDate',
                        $approval,
                        ['revision_approval_date' => '2026-07-17'],
                        '2026-07-24'
                    );
                } catch (RuntimeException $exception) {
                    $suppliedConflict = $exception;
                }
                $harness->assertTrue($suppliedConflict instanceof RuntimeException);
                $harness->assertTrue(str_contains(
                    mb_strtolower((string)$suppliedConflict?->getMessage()),
                    'conflicts'
                ));

                $currentConflict = null;
                try {
                    $invokePrivate(
                        $service,
                        'authoritativeRevisionApprovalDate',
                        $approval,
                        [],
                        '2026-07-17'
                    );
                } catch (RuntimeException $exception) {
                    $currentConflict = $exception;
                }
                $harness->assertTrue($currentConflict instanceof RuntimeException);
                $harness->assertTrue(str_contains(
                    mb_strtolower((string)$currentConflict?->getMessage()),
                    'conflicts'
                ));
            }
        );

        $harness->check(
            $service::class,
            'rejects an invalid approval date in the frozen filing basis',
            static function () use ($harness, $service, $invokePrivate): void {
                $approval = [
                    'approved_at' => '2026-07-24 09:30:00',
                    'basis_json' => json_encode([
                        'disclosures' => [
                            'values' => [
                                'accounts_approval_date' => '2026-02-30',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];

                $caught = null;
                try {
                    $invokePrivate(
                        $service,
                        'authoritativeRevisionApprovalDate',
                        $approval,
                        [],
                        '2026-02-28'
                    );
                } catch (RuntimeException $exception) {
                    $caught = $exception;
                }

                $harness->assertTrue($caught instanceof RuntimeException);
                $harness->assertTrue(str_contains(
                    mb_strtolower((string)$caught?->getMessage()),
                    'valid accounts approval date'
                ));
            }
        );

        $harness->check(
            $service::class,
            'builds distinct non-compliance and amendment disclosures from comparison and model data',
            static function () use ($harness, $service, $invokePrivate): void {
                $genericExplanation = 'The original accounts omitted adjustments found by the comparison';
                $comparison = [
                    'rows' => [
                        [
                            'metric_key' => 'fixed_assets',
                            'label' => 'Fixed assets',
                            'app_value' => 431.43,
                            'filed_value' => 0.0,
                            'variance' => 431.43,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'current_assets',
                            'label' => 'Current assets',
                            'app_value' => 1115.54,
                            'filed_value' => 275.0,
                            'variance' => 840.54,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'prepayments_accrued_income',
                            'label' => 'Prepayments and accrued income',
                            'app_value' => 140.55,
                            'filed_value' => 0.0,
                            'variance' => 140.55,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'creditors_within_one_year',
                            'label' => 'Creditors due within one year',
                            'app_value' => 1314.63,
                            'filed_value' => 64.0,
                            'variance' => 1250.63,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'creditors_after_more_than_one_year',
                            'label' => 'Creditors due after more than one year',
                            'app_value' => 0.0,
                            'filed_value' => 0.0,
                            'variance' => 0.0,
                            'status' => 'pass',
                        ],
                        [
                            'metric_key' => 'net_assets_liabilities',
                            'label' => 'Net assets',
                            'app_value' => 372.89,
                            'filed_value' => 211.0,
                            'variance' => 161.89,
                            'status' => 'fail',
                        ],
                        [
                            'metric_key' => 'equity_capital_reserves',
                            'label' => 'Capital and reserves',
                            'app_value' => 372.89,
                            'filed_value' => 211.0,
                            'variance' => 161.89,
                            'status' => 'fail',
                        ],
                    ],
                ];
                $model = [
                    'current' => [
                        'buckets' => [
                            'fixed_assets' => 431.43,
                            'depreciation_write_offs' => 197.41,
                            'current_assets' => 1115.54,
                            'prepayments_accrued_income' => 140.55,
                            'creditors_within_one_year' => 1314.63,
                            'creditors_after_more_than_one_year' => 0.0,
                            'net_assets_liabilities' => 372.89,
                            'equity_capital_reserves' => 372.89,
                        ],
                        'director_loan_reporting_presentation' => [
                            'applicable' => true,
                            'classification' => 'within_one_year',
                            'within_one_year' => 1035.63,
                            'after_more_than_one_year' => 0.0,
                            'party_facts' => [[
                                'reportable_liability' => 1035.63,
                                'repayable_on_demand' => true,
                                'terms' => ['repayable_on_demand' => true],
                            ]],
                        ],
                    ],
                    'director_loan_disclosure' => [
                        'has_company_to_director_exposure' => true,
                        'total_advances' => 253.0,
                        'total_cash_repayments' => 0.0,
                        'total_amounts_legally_set_off' => 253.0,
                        'total_director_funding' => 1288.63,
                        'closing_company_liability' => 1035.63,
                        'total_amounts_written_off' => 0.0,
                        'total_amounts_waived' => 0.0,
                        'disclosures' => [
                            ['director_name' => 'Test Director'],
                        ],
                    ],
                ];

                $texts = $invokePrivate(
                    $service,
                    'revisionDisclosureTexts',
                    ['variance_explanation' => $genericExplanation],
                    [
                        'non_compliance_explanation' => $genericExplanation,
                        'significant_amendments' => $genericExplanation,
                    ],
                    $comparison,
                    $model,
                    [
                        [
                            'concept' => 'core:Creditors',
                            'context_ref' => 'current_period_end_superseded_creditors_within_one_year',
                            'value' => 64.0,
                        ],
                        [
                            'concept' => 'core:Creditors',
                            'context_ref' => 'current_period_end_superseded_creditors_after_one_year',
                            'value' => 0.0,
                        ],
                    ]
                );

                $nonCompliance = (string)($texts['non_compliance_explanation'] ?? '');
                $amendments = (string)($texts['significant_amendments'] ?? '');
                $amendmentsLower = mb_strtolower($amendments);

                $harness->assertTrue($nonCompliance !== '');
                $harness->assertTrue($amendments !== '');
                $harness->assertFalse(mb_strtolower($nonCompliance) === $amendmentsLower);
                $harness->assertTrue(str_contains(mb_strtolower($nonCompliance), 'original accounts'));
                foreach ([
                    'fixed assets',
                    'depreciation',
                    'current assets',
                    'prepayments',
                    'creditors falling due within one year',
                    'participator-loan',
                    'repayable on demand',
                    'net assets',
                    'capital and reserves',
                ] as $expectedPhrase) {
                    if (!str_contains($amendmentsLower, $expectedPhrase)) {
                        throw new RuntimeException(
                            'The amendments disclosure is missing: ' . $expectedPhrase . '.'
                        );
                    }
                }
                $harness->assertTrue(str_contains($amendments, '£431.43'));
                $harness->assertTrue(str_contains($amendments, '£64.00'));
                $harness->assertTrue(str_contains($amendments, '£1,314.63'));
                $harness->assertTrue(str_contains($amendments, '£279.00'));
                $harness->assertTrue(str_contains($amendments, '£1,035.63'));
                $harness->assertTrue(str_contains($amendments, '£1,288.63'));
                $harness->assertTrue(str_contains($amendments, '£253.00'));
                $harness->assertTrue(str_contains($amendments, '£0.00'));
                $harness->assertTrue(str_contains(
                    $amendmentsLower,
                    'does not change the company’s total net assets'
                ));
                $harness->assertTrue(str_contains(
                    $nonCompliance,
                    'originally reported as £64.00'
                ));
                $harness->assertFalse(str_contains(
                    $amendments,
                    'revised from £279.00'
                ));
                $harness->assertFalse(str_contains(
                    $amendments,
                    '£1,035.63 due after more than one year'
                ));
                $harness->assertSame(
                    '(£58.54)',
                    $invokePrivate($service, 'revisionMoney', -58.54)
                );
                $harness->assertTrue(str_contains($amendments, 'Fixed assets were restated'));
            }
        );
    }
);
