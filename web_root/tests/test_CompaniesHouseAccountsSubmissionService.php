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
            'explains how to create a replacement after a numbered submission',
            static function () use ($harness): void {
                $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..'
                    . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts'
                    . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR
                    . 'CompaniesHouseAccountsSubmissionService.php');
                $harness->assertTrue(is_string($source));
                $harness->assertTrue(str_contains(
                    (string)$source,
                    'The currently generated Companies House iXBRL has already been submitted. '
                    . 'To send a new submission regenerate the iXBRL on the Disclosure page.'
                ));
            }
        );

        $harness->check($service::class, 'rejects a submission filing-kind lookup without a valid context', static function () use ($harness, $service): void {
            $harness->assertSame(null, $service->submissionFilingKindForContext(0, 0, 0));
        });

        $harness->check(
            $service::class,
            'keeps the authentication check independent of filing readiness and generated accounts',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'checkCompanyAuthentication'
                );
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                $harness->assertTrue(str_contains(
                    $body,
                    "->installedSchemasForOperation('company_data')"
                ));
                foreach ([
                    'fetchContext(',
                    'preparedArtifactState(',
                    'readiness(',
                    'isLocked(',
                    'revisionReadiness(',
                ] as $filingPrerequisite) {
                    $harness->assertFalse(str_contains($body, $filingPrerequisite));
                }
            }
        );

        $harness->check(
            $service::class,
            'submits Accounts without running or consuming a CompanyData check',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'submitRevision'
                );
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                $harness->assertFalse(str_contains($body, 'performCompanyDataPreflight('));
                $harness->assertFalse(str_contains($body, 'consumePreflight('));
                $harness->assertFalse(str_contains($body, 'verifiedPreflightId'));
            }
        );

        $harness->check(
            $service::class,
            'reports detailed and correctly named transmission progress',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'submitRevision'
                );
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                foreach ([
                    'Starting the Companies House ',
                    'Checking the locked Year End, filing declarations and taxonomy compatibility.',
                    'Verifying the prepared iXBRL against the current approved filing basis.',
                    'Verified prepared ',
                    'Allocated Companies House submission number ',
                    'Validated GovTalk transaction ',
                    'Archived the exact accounts iXBRL and validated GovTalk request',
                    'Recorded immutable request evidence ',
                    'Waiting for the Companies House gateway acknowledgement',
                    'Gateway response received; recording the transmission outcome.',
                    'status is pending, not yet accepted.',
                ] as $message) {
                    $harness->assertTrue(str_contains($body, $message));
                }
                $harness->assertSame(1, substr_count($body, 'preparedArtifactState('));
                $harness->assertTrue(
                    strpos($body, 'Starting the Companies House ') < strpos($body, 'preparedArtifactState(')
                );
                $harness->assertTrue(
                    strpos($body, 'Verifying the prepared iXBRL against the current approved filing basis.')
                    < strpos($body, 'preparedArtifactState(')
                );
                $harness->assertTrue(str_contains($body, "'Companies House acknowledged the ' . \$filingLabel"));
                $harness->assertFalse(str_contains(
                    $body,
                    'Companies House acknowledged the revised-accounts submission.'
                ));
            }
        );

        $harness->check(
            $service::class,
            'reports polling StatusAck and accepted-document progress',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'refreshStatus'
                );
                $harness->assertSame(3, $method->getNumberOfParameters());
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                foreach ([
                    'Starting Companies House status continuation',
                    'Requesting the latest submission status from Companies House.',
                    'Received Companies House status: ',
                    'Sending the mandatory StatusAck',
                    'Companies House acknowledged the StatusAck.',
                    'Checking whether an accepted filed document is available.',
                ] as $message) {
                    $harness->assertTrue(str_contains($body, $message));
                }
            }
        );

        $harness->check(
            $service::class,
            'reloads the latest immutable schema file inventory for each filing operation',
            static function () use ($harness, $service, $invokePrivate): void {
                $file = static fn(string $suffix, string $hash): array => [
                    'source_url' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/' . $suffix,
                    'relative_path' => 'v1-0/schema/' . $suffix,
                    'sha256' => $hash,
                ];
                $submission = ['filing_metadata_json' => json_encode([
                    'schema_validations' => [
                        ['operation'=>'accounts','preflight_id'=>null,'validated_at'=>'2026-07-29 10:00:00']
                            + $file('Egov_ch-v2-0.xsd', str_repeat('a', 64)),
                        ['operation'=>'accounts','preflight_id'=>null,'validated_at'=>'2026-07-29 10:00:00']
                            + $file('CompanyData-v3-6.xsd', str_repeat('b', 64)),
                        ['operation'=>'company_data','preflight_id'=>41,'validated_at'=>'2026-07-29 11:00:00']
                            + $file('CompanyData-v3-6.xsd', str_repeat('b', 64)),
                        ['operation'=>'company_data','preflight_id'=>42,'validated_at'=>'2026-07-29 12:00:00']
                            + $file('CompanyData-v3-6.xsd', str_repeat('c', 64)),
                    ],
                ], JSON_THROW_ON_ERROR)];

                $accounts = $invokePrivate(
                    $service,
                    'schemaInventoryFromSubmission',
                    $submission,
                    'accounts'
                );
                $companyData = $invokePrivate(
                    $service,
                    'schemaInventoryFromSubmission',
                    $submission,
                    'company_data'
                );

                $harness->assertSame(2, count($accounts['files']));
                $harness->assertSame(str_repeat('a', 64), $accounts['files'][0]['sha256']);
                $harness->assertSame(1, count($companyData['files']));
                $harness->assertSame(str_repeat('c', 64), $companyData['files'][0]['sha256']);
            }
        );

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
                    \eel_accounts\Service\IxbrlRevisedAccountsArtifactService::PRESENTATION_VERSION,
                    (string)$metadata['presentation_version']
                );
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
            'marks only the latest intact Companies House artifact for the current approval as current',
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
                        'id' => 712,
                        'company_id' => 49,
                        'accounting_period_id' => 79,
                        'ixbrl_generation_run_id' => 18,
                        'filing_type' => 'revised',
                        'environment' => 'TEST',
                        'revision_declarations' => [
                            'original_approval_date' => '2025-05-29',
                            'revision_approval_date' => '2026-07-21',
                        ],
                        'filing_metadata' => [
                            'presentation_version' =>
                                \eel_accounts\Service\IxbrlRevisedAccountsArtifactService::PRESENTATION_VERSION,
                        ],
                        'revised_artifact_path' => $path,
                        'revised_artifact_sha256' => hash_file('sha256', $path),
                        'basis_hash' => str_repeat('b', 64),
                    ];
                    $current = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 712,
                    ]);
                    $harness->assertSame('current', (string)$current['state']);
                    $harness->assertSame(true, (bool)$current['current']);
                    $harness->assertSame(2, (int)$current['fact_count']);
                    $harness->assertSame(
                        \eel_accounts\Service\IxbrlRevisedAccountsArtifactService::PRESENTATION_VERSION,
                        (string)$current['presentation_version']
                    );

                    $legacyPresentation = $submission;
                    $legacyPresentation['filing_metadata']['presentation_version'] =
                        'companies-house-revised-accounts-presentation-v1';
                    $legacy = $invokePrivate($service, 'preparedArtifactState', $legacyPresentation, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 712,
                    ]);
                    $harness->assertSame('stale', (string)$legacy['state']);
                    $harness->assertSame(false, (bool)$legacy['current']);
                    $harness->assertTrue(str_contains(
                        implode(' ', (array)$legacy['errors']),
                        'earlier presentation profile'
                    ));
                    $missingPresentation = $submission;
                    unset($missingPresentation['filing_metadata']['presentation_version']);
                    $missing = $invokePrivate($service, 'preparedArtifactState', $missingPresentation, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 712,
                    ]);
                    $harness->assertSame('stale', (string)$missing['state']);
                    $harness->assertSame(false, (bool)$missing['current']);

                    $stale = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 19,
                        'latest_submission_id' => 712,
                    ]);
                    $harness->assertSame('stale', (string)$stale['state']);
                    $harness->assertTrue(str_contains(
                        implode(' ', (array)$stale['errors']),
                        'current Disclosure Approval'
                    ));
                    $newer = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 713,
                    ]);
                    $harness->assertSame('stale', (string)$newer['state']);
                    $harness->assertTrue(str_contains(
                        implode(' ', (array)$newer['errors']),
                        'newer Companies House iXBRL'
                    ));

                    file_put_contents($path, '<html>changed</html>');
                    $tampered = $invokePrivate($service, 'preparedArtifactState', $submission, [
                        'ok' => true,
                        'run_id' => 18,
                        'latest_submission_id' => 712,
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
            'does not reopen HMRC artifacts when deciding whether prepared Companies House XML can be sent',
            static function () use ($harness): void {
                $method = new ReflectionMethod(
                    \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                    'submitRevision'
                );
                $path = $method->getFileName();
                $source = is_string($path) ? file($path) : false;
                $harness->assertTrue(is_array($source));
                $body = implode('', array_slice(
                    $source,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));
                $harness->assertTrue(str_contains($body, 'preparedArtifactState('));
                $harness->assertFalse(str_contains($body, '$this->readiness('));
                $harness->assertFalse(str_contains($body, 'IxbrlFilingArtifactService'));
            }
        );

        $harness->check(
            $service::class,
            'classifies legacy revised artifacts with invalid approval dates as invalid',
            static function () use ($harness, $service, $invokePrivate): void {
                $state = $invokePrivate($service, 'preparedArtifactState', [
                    'filing_type' => 'revised',
                    'revision_declarations' => [
                        'original_approval_date' => '2025-06-28',
                        'revision_approval_date' => '2025-06-28',
                    ],
                    'artifact_path' => 'missing-legacy-revised-artifact.xhtml',
                ]);

                $harness->assertSame('invalid', (string)$state['state']);
                $harness->assertFalse((bool)$state['current']);
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$state['errors']),
                    'must be later than the original accounts approval date'
                ));
            }
        );

        $harness->check(
            $service::class,
            'uses the frozen disclosure basis as the authoritative revised-accounts approval date',
            static function () use ($harness): void {
                $policy = new \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService();
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

                $date = $policy->resolveApprovalDate(
                    $approval,
                    ['revision_approval_date' => '2026-07-24'],
                    '2026-07-24',
                    '2025-06-28'
                );

                $harness->assertSame('2026-07-24', $date);
                $harness->assertFalse(str_starts_with((string)$date, '2026-07-17'));
            }
        );

        $harness->check(
            $service::class,
            'blocks a revised approval date that is not later than the original accounts approval date',
            static function () use ($harness): void {
                $policy = new \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService();
                $error = $policy->revisionApprovalDateError(
                    '2025-06-28',
                    '2025-06-28'
                );

                $harness->assertTrue(is_string($error));
                $harness->assertTrue(str_contains(
                    (string)$error,
                    'must be later than the original accounts approval date'
                ));
                $harness->assertSame(
                    null,
                    $policy->revisionApprovalDateError(
                        '2025-06-28',
                        '2026-07-17'
                    )
                );
            }
        );

        $harness->check(
            $service::class,
            'rejects supplied or current approval dates that conflict with the frozen basis',
            static function () use ($harness): void {
                $policy = new \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService();
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
                    $policy->resolveApprovalDate(
                        $approval,
                        ['revision_approval_date' => '2026-07-17'],
                        '2026-07-24',
                        '2025-06-28'
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
                    $policy->resolveApprovalDate(
                        $approval,
                        [],
                        '2026-07-17',
                        '2025-06-28'
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
            static function () use ($harness): void {
                $policy = new \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService();
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
                    $policy->resolveApprovalDate(
                        $approval,
                        [],
                        '2026-02-28',
                        '2025-06-28'
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

        $harness->check(
            $service::class,
            'states each changed creditor maturity class from original and revised fact models',
            static function () use ($harness, $service, $invokePrivate): void {
                $facts = static fn(float $within, float $after): array => [
                    [
                        'concept' => 'core:Creditors',
                        'context_ref' => 'current_period_end_superseded_creditors_within_one_year',
                        'value' => $within,
                    ],
                    [
                        'concept' => 'core:Creditors',
                        'context_ref' => 'current_period_end_superseded_creditors_after_one_year',
                        'value' => $after,
                    ],
                ];
                $sentence = static fn(array $facts, float $within, float $after): string => (string)$invokePrivate(
                    $service,
                    'creditorMaturityRestatementSentence',
                    $facts,
                    [
                        'creditors_within_one_year' => $within,
                        'creditors_after_more_than_one_year' => $after,
                    ]
                );

                $harness->assertSame(
                    'Creditors falling due within one year were restated from £64.00 to £279.00.',
                    $sentence($facts(64.0, 0.0), 279.0, 0.0)
                );
                $harness->assertSame(
                    'Creditors falling due after more than one year were restated from £0.00 to £1,035.63.',
                    $sentence($facts(64.0, 0.0), 64.0, 1035.63)
                );
                $harness->assertSame(
                    'Creditors falling due within one year were restated from £64.00 to £279.00, and creditors falling due after more than one year were restated from £0.00 to £1,035.63.',
                    $sentence($facts(64.0, 0.0), 279.0, 1035.63)
                );
                $harness->assertSame('', $sentence($facts(64.0, 0.0), 64.0, 0.0));
            }
        );
    }
);
