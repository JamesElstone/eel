<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\YearEndSectionApprovalService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\YearEndSectionApprovalService $service
): void {
    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'includes question wording, versions, options, and answers in the approval basis', static function () use ($harness, $service): void {
        $basisMethod = new ReflectionMethod($service, 'approvalBasis');
        $bundle = [
            'check_code' => 'director_loan_year_end_review',
            'facts' => ['entry_count' => 3, 'potential_s455_exposure_amount' => '125.00'],
            'questions' => [[
                'id' => 'ct600a.missing_parties',
                'prompt' => 'Are any participators missing?',
                'version' => 'question-v1',
                'type' => 'choice',
                'options' => ['no' => 'No', 'yes' => 'Yes'],
                'required' => true,
                'required_value' => 'no',
            ]],
        ];
        $basis = $basisMethod->invoke($service, $bundle, ['ct600a.missing_parties' => 'no']);
        $harness->assertSame('Are any participators missing?', (string)$basis['questions'][0]['prompt']);
        $harness->assertSame('No', (string)$basis['questions'][0]['options']['no']);
        $harness->assertSame('no', (string)$basis['answers']['ct600a.missing_parties']);

        $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
        $acknowledgement = [
            'basis_version' => \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION,
            'basis_hash' => $acknowledgements->hashBasis($basis),
        ];
        $harness->assertSame('current', (string)$acknowledgements->evaluate(
            $acknowledgement,
            $basis,
            false,
            \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION
        )['state']);

        $bundle['questions'][0]['prompt'] = 'Are any participators or associates missing?';
        $changedBasis = $basisMethod->invoke($service, $bundle, ['ct600a.missing_parties' => 'no']);
        $harness->assertSame('stale', (string)$acknowledgements->evaluate(
            $acknowledgement,
            $changedBasis,
            false,
            \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION
        )['state']);
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'enforces each declared required gate before approval', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'validateAnswers');
        $questions = [[
            'id' => 'ct600a.missing_parties',
            'prompt' => 'Are any participators missing?',
            'type' => 'choice',
            'options' => ['no' => 'No', 'yes' => 'Yes'],
            'required' => true,
            'required_value' => 'no',
        ]];
        $blocked = (array)$method->invoke($service, $questions, ['ct600a.missing_parties' => 'yes']);
        $harness->assertSame(false, !empty($blocked['success']));
        $approved = (array)$method->invoke($service, $questions, ['ct600a.missing_parties' => 'no']);
        $harness->assertSame(true, !empty($approved['success']));
        $harness->assertSame('no', (string)$approved['answers']['ct600a.missing_parties']);
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'requires a Yes Companies House XML Gateway eligibility answer', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'validateAnswers');
        $questionsMethod = new ReflectionMethod($service, 'companiesHouseQuestions');
        $questions = (array)$questionsMethod->invoke($service, false);

        $blocked = (array)$method->invoke($service, $questions, ['companies_house.xml_eligibility' => 'ineligible']);
        $approved = (array)$method->invoke($service, $questions, ['companies_house.xml_eligibility' => 'eligible']);

        $harness->assertSame(false, !empty($blocked['success']));
        $harness->assertSame(true, !empty($approved['success']));
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'uses a non-empty source token for Companies House bundles', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'sourceToken');
        $token = (string)$method->invoke($service, 0, 0, 'companies_house_mismatch_acknowledgement');

        $harness->assertSame(64, strlen($token));
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'does not stale a Director Loan bundle when only the S455 evaluation clock changes', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'bundleSourceHash');
        $bundle = [
            'check_code' => 'director_loan_year_end_review',
            'facts' => ['party_facts' => [['party_id' => 7, 'terms_revision' => 2]]],
            'display' => [
                's455' => [
                    'periods' => [[
                        'evidence_cutoff' => '2026-07-26 23:59:59',
                        'basis' => ['evidence_cutoff' => '2026-07-26 23:59:59', 'exposure' => '125.00'],
                    ]],
                ],
            ],
        ];
        $before = (string)$method->invoke($service, $bundle);
        $bundle['display']['s455']['periods'][0]['evidence_cutoff'] = '2026-07-27 00:00:00';
        $bundle['display']['s455']['periods'][0]['basis']['evidence_cutoff'] = '2026-07-27 00:00:00';
        $after = (string)$method->invoke($service, $bundle);

        $harness->assertSame($before, $after);
        $bundle['facts']['party_facts'][0]['terms_revision'] = 3;
        $harness->assertSame(false, hash_equals($before, (string)$method->invoke($service, $bundle)));
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'uses the persisted filing-scope answers instead of browser-submitted duplicates', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'approvalAnswers');
        $answers = (array)$method->invoke($service, [
            'answer_source' => 'persisted_filing_scope',
            'current_answers' => ['filing_scope.ct600b' => 'no'],
        ], ['filing_scope.ct600b' => 'yes']);

        $harness->assertSame('no', (string)$answers['filing_scope.ct600b']);
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'versions canonical tax review bundles independently from pre-canonical caches', static function () use ($harness, $service): void {
        $definitionToken = new ReflectionMethod($service, 'definitionToken');
        $canonicalJson = new ReflectionMethod($service, 'canonicalJson');
        $expected = hash('sha256', (string)$canonicalJson->invoke($service, [
            'contract_version' => \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION,
            'check_code' => 'tax_readiness_acknowledgement',
            'questions' => ['provider' => 'tax_filing_scope_v3_canonical_freeze'],
        ]));

        $harness->assertSame(
            $expected,
            (string)$definitionToken->invoke($service, 'tax_readiness_acknowledgement')
        );
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'accepts a refreshed tax approval bundle when only its scope gate changed', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'taxBundleChangedOnlyByScope');
        $previous = [
            'source_token' => 'scope-v1',
            'facts' => ['total_tax' => '100.00', 'filing_scope_revision' => 1, 'filing_scope_basis_hash' => 'one'],
            'display' => ['total_tax' => '100.00', 'filing_scope_revision' => 1, 'filing_scope_basis_hash' => 'one'],
            'current_answers' => ['filing_scope.ct600b' => ''],
            'scope_ready' => false,
            'can_approve' => false,
            'approval_errors' => ['Answer every question.'],
        ];
        $scopeChanged = $previous;
        $scopeChanged['source_token'] = 'scope-v2';
        $scopeChanged['facts']['filing_scope_revision'] = 2;
        $scopeChanged['facts']['filing_scope_basis_hash'] = 'two';
        $scopeChanged['display']['filing_scope_revision'] = 2;
        $scopeChanged['display']['filing_scope_basis_hash'] = 'two';
        $scopeChanged['current_answers']['filing_scope.ct600b'] = 'no';
        $scopeChanged['scope_ready'] = true;
        $scopeChanged['can_approve'] = true;
        $scopeChanged['approval_errors'] = [];

        $harness->assertSame(true, (bool)$method->invoke($service, $previous, $scopeChanged));
        $scopeChanged['facts']['total_tax'] = '101.00';
        $harness->assertSame(false, (bool)$method->invoke($service, $previous, $scopeChanged));
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'keeps a tax approval current across equivalent decimal shapes and pool ordering', static function () use ($harness, $service): void {
        $normalise = new ReflectionMethod($service, 'normaliseTaxApprovalValue');
        $stored = [
            'freeze_manifest' => [
                'periods' => [[
                    'sequence_no' => 1,
                    'accounting_profit' => '-118.66',
                    'capital_allowance_breakdown' => [
                        'rows' => [
                            ['pool_type' => 'special_rate_pool', 'aia_claimed' => '0.000000'],
                            ['pool_type' => 'main_pool', 'aia_claimed' => '628.840000'],
                        ],
                    ],
                ]],
                'totals' => ['taxable_profit' => '0.00', 's455_tax' => '0.00'],
            ],
        ];
        $live = [
            'freeze_manifest' => [
                'periods' => [[
                    'sequence_no' => '1',
                    'accounting_profit' => '-118.660000',
                    'capital_allowance_breakdown' => [
                        'rows' => [
                            ['pool_type' => 'main_pool', 'aia_claimed' => 628.84],
                            ['pool_type' => 'special_rate_pool', 'aia_claimed' => 0],
                        ],
                    ],
                ]],
                'totals' => ['taxable_profit' => 0, 's455_tax' => '0.000000'],
            ],
        ];

        $harness->assertSame(
            $normalise->invoke($service, $stored),
            $normalise->invoke($service, $live)
        );
        $live['freeze_manifest']['totals']['s455_tax'] = '25.00';
        $harness->assertSame(
            false,
            $normalise->invoke($service, $stored) === $normalise->invoke($service, $live)
        );
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'accepts only intact legacy tax signatures after representation normalisation', static function () use ($harness, $service): void {
        $matches = new ReflectionMethod($service, 'storedTaxBasisMatches');
        $stored = [
            'contract_version' => \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION,
            'check_code' => 'tax_readiness_acknowledgement',
            'facts' => ['freeze_manifest' => ['totals' => ['s455_tax' => '0.00']]],
            'questions' => [],
            'answers' => [],
        ];
        $current = $stored;
        $current['facts']['freeze_manifest']['totals']['s455_tax'] = 0;
        $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
        $acknowledgement = [
            'basis_json' => json_encode($stored, JSON_UNESCAPED_SLASHES),
            'basis_hash' => $acknowledgements->hashBasis($stored),
        ];

        $harness->assertSame(true, (bool)$matches->invoke($service, $acknowledgement, $current));
        $acknowledgement['basis_json'] = str_replace('"0.00"', '"25.00"', (string)$acknowledgement['basis_json']);
        $harness->assertSame(false, (bool)$matches->invoke($service, $acknowledgement, $current));
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'builds the P&L bundle directly from the prepared retained earnings context', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'retainedEarningsBundle');
        $bundle = (array)$method->invoke($service, 12, 34, [
            'available' => true,
            'can_acknowledge' => true,
            'summary' => ['current_profit_loss' => 125.50],
            'journal_lines' => [['nominal_account_id' => 7, 'debit' => 125.50, 'credit' => 0]],
            'reserve_review' => ['available' => true, 'snapshot_current' => true, 'rows' => []],
            'prior_period_dependency' => ['satisfied' => true],
            'accounting_period' => ['id' => 34],
        ]);

        $harness->assertSame('retained_earnings_close_confirmation', (string)$bundle['check_code']);
        $harness->assertSame(125.50, (float)((($bundle['facts']['facts'] ?? [])['summary'] ?? [])['current_profit_loss'] ?? 0));
        $harness->assertSame(34, (int)(($bundle['display']['accounting_period'] ?? [])['id'] ?? 0));

        $source = (string)file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'YearEndSectionApprovalService.php');
        $start = strpos($source, 'private function retainedEarningsBundle(');
        $end = strpos($source, 'private function retainedEarningsDisplay(', $start !== false ? $start : 0);
        $provider = $start !== false && $end !== false ? substr($source, $start, $end - $start) : '';
        $harness->assertSame(false, str_contains($provider, 'fetchChecklist('));
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'versions the direct P&L provider so checklist-era bundles refresh', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'definitionToken');
        $pnlToken = (string)$method->invoke($service, 'retained_earnings_close_confirmation');
        $defaultToken = (string)$method->invoke($service, 'transaction_tail_review');

        $harness->assertSame(false, hash_equals($pnlToken, $defaultToken));
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'ships the persistent bundle migration and master-schema definition', static function () use ($harness): void {
        $root = dirname(__DIR__, 2);
        $schema = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql');
        $migration = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_07_24_001_year_end_section_review_bundles.sql');
        $harness->assertSame(true, str_contains($schema, 'CREATE TABLE `year_end_section_review_bundles`'));
        $harness->assertSame(true, str_contains($schema, "'2026_07_24_001_year_end_section_review_bundles.sql'"));
        $harness->assertSame(true, str_contains($migration, 'UNIQUE KEY `uq_year_end_section_review_bundle`'));
        $harness->assertSame(true, str_contains($migration, '`bundle_json` longtext NOT NULL'));
    });
});
