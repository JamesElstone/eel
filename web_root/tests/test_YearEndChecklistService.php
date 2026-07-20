<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\YearEndChecklistService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\AssetService::class, 'depreciation preview reports pending year-end depreciation', static function () use ($harness): void {
        yearEndChecklistServiceRequireDepreciationLockTables($harness);

        InterfaceDB::beginTransaction();
        try {
            yearEndChecklistServiceEnsureNominal('1000', 'Bank', 'asset');
            yearEndChecklistServiceEnsureNominal('1300', 'Tools', 'asset');
            yearEndChecklistServiceEnsureNominal('1330', 'Accum Dep - Tools', 'asset');
            yearEndChecklistServiceEnsureNominal('4000', 'Sales', 'income');
            yearEndChecklistServiceEnsureNominal('6200', 'Depreciation Expense', 'expense');

            $fixture = yearEndChecklistServiceCreateDepreciationLockFixture();
            $result = (new \eel_accounts\Service\AssetService())->previewDepreciationRun(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(1, (int)($result['created'] ?? 0));
            $harness->assertTrue((float)($result['total_amount'] ?? 0) > 0);
            $harness->assertSame(0, InterfaceDB::countWhere('asset_depreciation_entries', [
                'asset_id' => (int)$fixture['asset_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'preflight accepts retained earnings approval including pending depreciation', static function () use ($harness): void {
        yearEndChecklistServiceRequireDepreciationLockTables($harness);

        InterfaceDB::beginTransaction();
        try {
            yearEndChecklistServiceEnsureNominal('1000', 'Bank', 'asset');
            yearEndChecklistServiceEnsureNominal('1300', 'Tools', 'asset');
            yearEndChecklistServiceEnsureNominal('1330', 'Accum Dep - Tools', 'asset');
            yearEndChecklistServiceEnsureNominal('3000', 'Retained Earnings', 'equity');
            yearEndChecklistServiceEnsureNominal('4000', 'Sales', 'income');
            yearEndChecklistServiceEnsureNominal('6200', 'Depreciation Expense', 'expense');

            $fixture = yearEndChecklistServiceCreateDepreciationLockFixture();
            $companyId = (int)$fixture['company_id'];
            $accountingPeriodId = (int)$fixture['accounting_period_id'];

            $acknowledged = (new \eel_accounts\Service\YearEndChecklistService())
                ->saveRetainedEarningsCloseAcknowledgement($companyId, $accountingPeriodId, true, 'test');
            $harness->assertSame(true, (bool)($acknowledged['success'] ?? false));
            $retainedEarningsContext = (new \eel_accounts\Service\RetainedEarningsCloseService())->fetchContext($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($retainedEarningsContext['acknowledged'] ?? false));
            $harness->assertSame(false, (bool)($retainedEarningsContext['acknowledgement_stale'] ?? true));
            $harness->assertSame(true, (bool)(($retainedEarningsContext['reserve_review'] ?? [])['snapshot_current'] ?? false));
            $harness->assertSame('800.00', number_format((float)(($retainedEarningsContext['summary'] ?? [])['current_profit_loss'] ?? 0), 2, '.', ''));

            $service = new \eel_accounts\Service\YearEndChecklistService();
            $preflight = new ReflectionMethod($service, 'preflightLockPeriod');
            $preflight->setAccessible(true);
            $preflightResult = $preflight->invoke($service, $companyId, $accountingPeriodId, [
                'overall_status' => 'ready_for_review',
                'retained_earnings_close' => [
                    'acknowledged' => true,
                    'acknowledgement_stale' => false,
                ],
            ]);
            $harness->assertSame(true, (bool)($preflightResult['success'] ?? false));

            $harness->assertSame(0, InterfaceDB::countWhere('asset_depreciation_entries', [
                'asset_id' => (int)$fixture['asset_id'],
                'accounting_period_id' => $accountingPeriodId,
            ]));
            $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM journals
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND source_type = :source_type',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'source_type' => 'asset_depreciation',
                ]
            ));
            $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM journal_entry_metadata
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND journal_tag IN (:retained_tag, :director_offset_tag)',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'retained_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                    'director_offset_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                ]
            ));
            $harness->assertSame(0, InterfaceDB::countWhere('corporation_tax_computation_runs', [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]));
            $harness->assertSame(0, InterfaceDB::countWhere('year_end_reviews', [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'is_locked' => 1,
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'director loan reclassification before lock requires the current factual confirmation', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            yearEndChecklistServiceRequireDirectorLoanOffsetLockTables($harness);
            StandardNominalTestFixture::ensureNominals(['1200', '2100']);
            $fixture = yearEndChecklistServiceCreateDirectorLoanOffsetFixture();
            $service = new \eel_accounts\Service\YearEndChecklistService();
            $method = new ReflectionMethod($service, 'applyDirectorLoanOffsetBeforeLock');
            $method->setAccessible(true);

            $unconfirmed = $method->invoke(
                $service,
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                ['review' => []],
                'test'
            );

            $harness->assertSame(false, (bool)($unconfirmed['success'] ?? true));
            $harness->assertSame(true, str_contains(implode(' ', (array)($unconfirmed['errors'] ?? [])), 'factual Director Loan Year End Review'));
            $harness->assertSame(0, InterfaceDB::countWhere('journal_entry_metadata', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'journal_key' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
            ]));

            yearEndChecklistServiceApproveDirectorLoanOffset($fixture);
            $posted = $method->invoke(
                $service,
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                ['checks_flat' => [[
                    'check_code' => 'director_loan_year_end_review',
                    'acknowledgement_current' => true,
                ]]],
                'test'
            );

            $harness->assertSame(true, (bool)($posted['success'] ?? false));
            $harness->assertSame(1, InterfaceDB::countWhere('journal_entry_metadata', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'journal_key' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'stale director loan facts block lock until reconfirmed and then post the delta', static function () use ($harness): void {
        yearEndChecklistServiceRequireDirectorLoanOffsetLockTables($harness);

        InterfaceDB::beginTransaction();
        try {
            yearEndChecklistServiceEnsureNominal('1200', 'Director Loan Asset', 'asset');
            yearEndChecklistServiceEnsureNominal('2100', 'Director Loan Liability', 'liability');

            $fixture = yearEndChecklistServiceCreateDirectorLoanOffsetFixture();
            $companyId = (int)$fixture['company_id'];
            $accountingPeriodId = (int)$fixture['accounting_period_id'];
            $assetNominalId = yearEndChecklistServiceNominalId('1200');

            $service = new \eel_accounts\Service\DirectorLoanReconciliationService();
            yearEndChecklistServiceApproveDirectorLoanOffset($fixture);
            $first = $service->postOffset($companyId, $accountingPeriodId, 'test');
            $harness->assertSame(true, (bool)($first['success'] ?? false));

            yearEndChecklistServiceInsertDirectorLoanLineJournal($companyId, $accountingPeriodId, $assetNominalId, 100.00, 0.00, 'asset-extra', (string)$companyId);

            $stale = $service->fetchContext($companyId, $accountingPeriodId);
            $harness->assertSame(false, (bool)($stale['acknowledgement_current'] ?? true));
            $harness->assertSame(100.00, (float)($stale['pending_adjustment_amount'] ?? 0));
            $checklistService = new \eel_accounts\Service\YearEndChecklistService();
            $applyBeforeLock = new ReflectionMethod($checklistService, 'applyDirectorLoanOffsetBeforeLock');
            $applyBeforeLock->setAccessible(true);
            $lockAttempt = $applyBeforeLock->invoke(
                $checklistService,
                $companyId,
                $accountingPeriodId,
                ['checks_flat' => []],
                'test'
            );
            $harness->assertSame(false, (bool)($lockAttempt['success'] ?? true));
            yearEndChecklistServiceApproveDirectorLoanOffset($fixture);
            $postedDelta = $applyBeforeLock->invoke(
                $checklistService,
                $companyId,
                $accountingPeriodId,
                ['checks_flat' => []],
                'test'
            );
            $harness->assertSame(true, (bool)($postedDelta['success'] ?? false));
            $afterDelta = $service->fetchContext($companyId, $accountingPeriodId);
            $harness->assertSame(1100.00, (float)($afterDelta['posted_reclassification_amount'] ?? -1));
            $harness->assertSame(0.00, (float)($afterDelta['pending_adjustment_amount'] ?? -1));
            $harness->assertSame(2, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM journal_entry_metadata
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND journal_tag = :journal_tag',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                ]
            ));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'warnings and failures both prevent the Year End lock', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $method = new ReflectionMethod($service, 'canLockOverallStatus');
        $method->setAccessible(true);
        $overall = new ReflectionMethod($service, 'determineOverallStatus');
        $overall->setAccessible(true);

        $harness->assertSame(true, (bool)$method->invoke($service, 'ready_for_review'));
        $harness->assertSame(false, (bool)$method->invoke($service, 'in_progress'));
        $harness->assertSame(false, (bool)$method->invoke($service, 'needs_attention'));
        $harness->assertSame(false, (bool)$method->invoke($service, 'not_started'));
        $harness->assertSame(false, (bool)$method->invoke($service, 'locked'));
        $harness->assertSame('in_progress', (string)$overall->invoke(
            $service,
            [['status' => 'warning']],
            true,
            false
        ));
        $harness->assertSame('ready_for_review', (string)$overall->invoke(
            $service,
            [['status' => 'info']],
            true,
            false
        ));
        $harness->assertSame('needs_attention', (string)$overall->invoke(
            $service,
            [['status' => 'warning'], ['status' => 'fail']],
            true,
            false
        ));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'CT-period tax fact failures link to the selected Tax period', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $method = new ReflectionMethod($service, 'ctPeriodTaxFactChecks');
        $method->setAccessible(true);

        $checks = $method->invoke($service, [
            'periods' => [[
                'available' => true,
                'ct_period_id' => 73,
                'ct_period_display_sequence_no' => 4,
                'unknown_treatment_count' => 1,
                'unknown_treatment_amount' => 12.34,
                'other_treatment_count' => 0,
                'other_treatment_amount' => 0.0,
                'hard_gate_diagnostics' => [[
                    'code' => 'capital_allowance_fixture',
                    'category' => 'capital_allowance',
                    'amount_affecting' => true,
                    'message' => 'Resolve the capital allowance warning.',
                ]],
            ]],
        ], [
            'periods' => [[
                'ct_period_id' => 73,
                'sequence_no' => 4,
                'confirmed' => true,
            ]],
        ]);

        $harness->assertSame(1, count($checks));
        $harness->assertSame('ct_period_tax_facts_73', (string)($checks[0]['check_code'] ?? ''));
        $harness->assertSame('fail', (string)($checks[0]['status'] ?? ''));
        $harness->assertSame('corporation_tax', (string)($checks[0]['workflow_page'] ?? ''));
        $harness->assertSame('73', (string)(($checks[0]['workflow_fields'] ?? [])['ct_period_id'] ?? ''));
        $harness->assertTrue(str_contains((string)($checks[0]['detail_text'] ?? ''), 'unknown tax treatment'));
        $harness->assertTrue(!str_contains((string)($checks[0]['detail_text'] ?? ''), 's455 review is not confirmed'));
        $harness->assertTrue(str_contains((string)($checks[0]['detail_text'] ?? ''), 'capital allowance warning'));
        $harness->assertSame(false, array_key_exists('review_clearable', $checks[0]));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'posted source work checks are split by source type', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $postedChecks = new ReflectionMethod($service, 'postedSourceWorkChecks');
        $postedChecks->setAccessible(true);
        $trialBalanceMetric = new ReflectionMethod($service, 'trialBalanceMetric');
        $trialBalanceMetric->setAccessible(true);

        $checks = $postedChecks->invoke($service, [
            'unposted_transactions' => 0,
            'unposted_expense_claims' => 7,
            'unposted_assets' => 0,
        ]);

        $harness->assertSame(3, count($checks));
        $harness->assertSame('posted_transactions_integrity', (string)($checks[0]['check_code'] ?? ''));
        $harness->assertSame('Posted transactions', (string)($checks[0]['title'] ?? ''));
        $harness->assertSame('pass', (string)($checks[0]['status'] ?? ''));
        $harness->assertSame('0 transaction(s)', (string)($checks[0]['metric_value'] ?? ''));
        $harness->assertSame('posted_expense_claims_integrity', (string)($checks[1]['check_code'] ?? ''));
        $harness->assertSame('Posted expense claims', (string)($checks[1]['title'] ?? ''));
        $harness->assertSame('fail', (string)($checks[1]['status'] ?? ''));
        $harness->assertSame('7 expense claim(s)', (string)($checks[1]['metric_value'] ?? ''));
        $harness->assertSame('expense_claims', (string)($checks[1]['workflow_page'] ?? ''));
        $harness->assertSame('posted_assets_integrity', (string)($checks[2]['check_code'] ?? ''));
        $harness->assertSame('Posted assets', (string)($checks[2]['title'] ?? ''));
        $harness->assertSame('pass', (string)($checks[2]['status'] ?? ''));
        $harness->assertSame('0 asset(s)', (string)($checks[2]['metric_value'] ?? ''));
        $harness->assertSame('26 trial balance line(s)', (string)$trialBalanceMetric->invoke($service, ['line_count' => 26]));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'review acknowledgement clears advisory warning checks only', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $method = new ReflectionMethod($service, 'applyReviewAcknowledgement');
        $method->setAccessible(true);

        $warning = [
            'check_code' => 'fixed_asset_review_placeholder',
            'status' => 'warning',
            'metric_value' => '',
            'detail_text' => 'Fixed asset treatment should be reviewed.',
            'basis_data' => (new \eel_accounts\Service\YearEndAcknowledgementService())->buildBasis(
                'fixed_asset_review_placeholder',
                ['candidate_count' => 1, 'candidate_ids' => [42]]
            ),
        ];
        $basisService = new \eel_accounts\Service\YearEndAcknowledgementService();
        $acknowledged = $method->invoke($service, $warning, [
            'fixed_asset_review_placeholder' => [
                'acknowledged_at' => '2026-07-03 12:00:00',
                'acknowledged_by' => 'test',
                'note' => null,
                'basis_version' => \eel_accounts\Service\YearEndAcknowledgementService::BASIS_VERSION,
                'basis_hash' => $basisService->hashBasis((array)$warning['basis_data']),
            ],
        ]);

        $harness->assertSame('pass', (string)$acknowledged['status']);
        $harness->assertSame('Reviewed', (string)$acknowledged['metric_value']);
        $harness->assertSame(true, str_contains((string)$acknowledged['detail_text'], 'Review acknowledged'));

        $fail = $method->invoke($service, [
            'check_code' => 'lock_readiness_checklist',
            'status' => 'fail',
            'metric_value' => 'Not ready',
            'detail_text' => 'Blocking check failed.',
        ], [
            'lock_readiness_checklist' => [
                'acknowledged_at' => '2026-07-03 12:00:00',
            ],
        ]);

        $harness->assertSame('fail', (string)$fail['status']);
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'missing legacy prepayment schedules are a dedicated blocking check', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $checkMethod = new ReflectionMethod($service, 'prepaymentScheduleIntegrityCheck');
        $checkMethod->setAccessible(true);
        $currentMethod = new ReflectionMethod($service, 'prepaymentSchedulesCurrent');
        $currentMethod->setAccessible(true);

        $missing = ['available' => true, 'missing_count' => 1];
        $check = $checkMethod->invoke($service, $missing);
        $harness->assertSame('prepayment_schedule_integrity', (string)($check['check_code'] ?? ''));
        $harness->assertSame('fail', (string)($check['status'] ?? ''));
        $harness->assertSame('1 missing', (string)($check['metric_value'] ?? ''));
        $harness->assertSame('?page=prepayments&show_card=prepayments_review#prepayment-schedule-repair', (string)($check['action_url'] ?? ''));
        $harness->assertSame(false, (bool)$currentMethod->invoke($service, $missing));

        $current = ['available' => true, 'missing_count' => 0];
        $currentCheck = $checkMethod->invoke($service, $current);
        $harness->assertSame('pass', (string)($currentCheck['status'] ?? ''));
        $harness->assertSame('Current', (string)($currentCheck['metric_value'] ?? ''));
        $harness->assertSame(true, (bool)$currentMethod->invoke($service, $current));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'filing basis reminder is informational only', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $method = new ReflectionMethod($service, 'applyReviewAcknowledgement');
        $method->setAccessible(true);

        $info = [
            'check_code' => 'filing_basis_reminder',
            'status' => 'info',
            'metric_value' => '',
            'detail_text' => 'Year-end lock finalises the app ledger.',
        ];
        $acknowledged = $method->invoke($service, $info, [
            'filing_basis_reminder' => [
                'acknowledged_at' => '2026-07-03 12:00:00',
                'acknowledged_by' => 'test',
                'note' => null,
            ],
        ]);

        $harness->assertSame('info', (string)$acknowledged['status']);
        $harness->assertSame(false, isset($acknowledged['review_clearable']));
        $harness->assertSame(false, isset($acknowledged['review_acknowledgement']));
        $harness->assertSame('', (string)$acknowledged['metric_value']);
        $harness->assertSame(false, str_contains((string)$acknowledged['detail_text'], 'Review acknowledged'));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'expense position metric labels unpaid and owed balances', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $method = new ReflectionMethod($service, 'expensePositionMetric');
        $method->setAccessible(true);
        $settings = ['default_currency_symbol' => '&#163;'];

        $harness->assertSame('UNPAID £ 125.00', $method->invoke($service, $settings, 125.0));
        $harness->assertSame('OWED -£ 42.50', $method->invoke($service, $settings, -42.5));
        $harness->assertSame('£ 0.00', $method->invoke($service, $settings, 0.0));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'statement continuity detail names first statement without previous comparator', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $detail = new ReflectionMethod($service, 'statementContinuityDetail');
        $detail->setAccessible(true);
        $metric = new ReflectionMethod($service, 'statementContinuityMetric');
        $metric->setAccessible(true);
        $settings = ['default_currency_symbol' => '&#163;', 'date_format' => 'd/m/Y'];

        $text = $detail->invoke($service, [
            'issue_count' => 1,
            'issues' => [
                [
                    'type' => 'statement_continuity',
                    'status' => 'warning',
                    'account_id' => 58,
                    'account_name' => 'Example Bank - Saving Pot (20%)',
                    'upload_id' => 313,
                    'upload_filename' => 'BANK_010925_300925.csv',
                    'statement_month' => '2025-09-01',
                    'date_range_start' => '2025-09-20',
                    'date_range_end' => '2025-09-25',
                    'opening_balance' => 0.0,
                    'closing_balance' => 681.44,
                    'previous_statement_closing_balance' => null,
                    'note' => 'No previous statement exists to compare against.',
                ],
            ],
        ], $settings);

        $harness->assertSame('1 statement continuity issue', $metric->invoke($service, 1));
        $harness->assertTrue(str_contains((string)$text, 'Example Bank - Saving Pot (20%): first statement BANK_010925_300925.csv covers 20/09/2025 to 25/09/2025 and opens at £ 0.00'));
        $harness->assertTrue(str_contains((string)$text, 'no previous statement exists to compare against'));
        $harness->assertFalse(str_contains((string)$text, 'At least one bank account has running-balance'));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'accepted initial month evidence clears only its matching first statement warning', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $method = new ReflectionMethod($service, 'applyAcceptedInitialStatementConfirmations');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, [
            'issue_count' => 2,
            'issues' => [
                [
                    'type' => 'statement_continuity',
                    'account_id' => 58,
                    'upload_id' => 313,
                    'opening_balance' => 0.0,
                    'previous_statement_closing_balance' => null,
                ],
                [
                    'type' => 'running_balance',
                    'account_id' => 58,
                    'upload_id' => 313,
                ],
            ],
        ], [[
            'upload_id' => 313,
            'account_id' => 58,
            'opening_balance' => 0.0,
            'confirmation_basis' => 'incorporation_month_first_later_statement_opening_zero',
            'confirmed_month_start' => '2022-09-01',
            'confirmed_at' => '2026-07-14 12:00:00',
        ]]);

        $harness->assertSame(1, (int)($filtered['issue_count'] ?? 0));
        $harness->assertSame('running_balance', (string)(($filtered['issues'][0] ?? [])['type'] ?? ''));
        $harness->assertSame(1, (int)($filtered['accepted_initial_gap_count'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'statement continuity detail distinguishes boundary mismatch and running balance breaks', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $detail = new ReflectionMethod($service, 'statementContinuityDetail');
        $detail->setAccessible(true);
        $metric = new ReflectionMethod($service, 'statementContinuityMetric');
        $metric->setAccessible(true);
        $settings = ['default_currency_symbol' => '&#163;', 'date_format' => 'd/m/Y'];

        $text = $detail->invoke($service, [
            'issue_count' => 2,
            'issues' => [
                [
                    'type' => 'statement_continuity',
                    'status' => 'fail',
                    'account_name' => 'Example Bank - Saving Pot (20%)',
                    'upload_filename' => 'BANK_011025_311025.csv',
                    'date_range_start' => '2025-10-01',
                    'date_range_end' => '2025-10-05',
                    'opening_balance' => 600.0,
                    'previous_statement_closing_balance' => 681.44,
                    'note' => 'Opening/closing mismatch.',
                ],
                [
                    'type' => 'running_balance',
                    'status' => 'fail',
                    'account_name' => 'Example Bank - Current Account',
                    'upload_filename' => 'BANK_010925_300925.csv',
                    'balance_check_rows_tested' => 10,
                    'balance_check_rows_failed' => 2,
                    'failed_row_numbers' => [12, 15],
                ],
            ],
        ], $settings);

        $harness->assertSame('2 statement continuity issues', $metric->invoke($service, 2));
        $harness->assertTrue(str_contains((string)$text, 'and opens at £ 600.00, but the previous statement closed at £ 681.44'));
        $harness->assertTrue(str_contains((string)$text, 'opening/closing mismatch'));
        $harness->assertTrue(str_contains((string)$text, 'has 2 running-balance breaks across 10 checked row(s); first failed row(s): 12, 15'));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'workflow URLs omit selected site context ids', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $makeCheck = new ReflectionMethod($service, 'makeCheck');
        $makeCheck->setAccessible(true);
        $dashboardActionUrl = new ReflectionMethod($service, 'dashboardActionUrl');
        $dashboardActionUrl->setAccessible(true);

        $check = $makeCheck->invoke(
            $service,
            'prepayments_accruals_placeholder',
            'Prepayments and accruals review',
            'warning',
            'warning',
            'Manual review reminder.',
            '',
            '?page=journal&company_id=12&accounting_period_id=34&show_card=nominal_closing_balances'
        );

        $harness->assertSame('?page=journal&show_card=nominal_closing_balances', (string)$check['action_url']);
        $harness->assertSame('journal', (string)($check['workflow_page'] ?? ''));
        $harness->assertSame('nominal_closing_balances', (string)(($check['workflow_fields'] ?? [])['show_card'] ?? ''));
        $harness->assertSame(false, str_contains((string)$check['action_url'], 'company_id='));
        $harness->assertSame(false, str_contains((string)$check['action_url'], 'accounting_period_id='));
        $harness->assertSame('?page=year_end&show_card=year_end_checklist', (string)$dashboardActionUrl->invoke($service, 12, 34));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'auto decision warning detail and routing split review from post confirmation', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $detail = new ReflectionMethod($service, 'autoDecisionReviewDetail');
        $detail->setAccessible(true);
        $metric = new ReflectionMethod($service, 'autoDecisionReviewMetric');
        $metric->setAccessible(true);
        $actionUrl = new ReflectionMethod($service, 'autoDecisionReviewActionUrl');
        $actionUrl->setAccessible(true);

        $mixedSummary = [
            'unreviewed_count' => 3,
            'post_confirmation_pending_count' => 2,
            'total_attention_count' => 5,
        ];
        $harness->assertSame('3 unreviewed, 2 not post-confirmed', (string)$metric->invoke($service, $mixedSummary));
        $harness->assertTrue(str_contains((string)$detail->invoke($service, $mixedSummary), '3 unreviewed row decision(s)'));
        $harness->assertTrue(str_contains((string)$detail->invoke($service, $mixedSummary), '2 checked decision(s) awaiting post confirmation'));
        $harness->assertSame(
            '?page=transactions&show_card=transaction_search&transaction_search_category_status=auto&transaction_search_auto_approval_filter=pending',
            (string)$actionUrl->invoke($service, $mixedSummary)
        );

        $postPendingOnlySummary = [
            'unreviewed_count' => 0,
            'post_confirmation_pending_count' => 2,
            'total_attention_count' => 2,
        ];
        $harness->assertSame(
            '?page=transactions&show_card=transaction_search&transaction_search_category_status=auto&transaction_search_auto_approval_filter=post_pending',
            (string)$actionUrl->invoke($service, $postPendingOnlySummary)
        );

        $clearSummary = [
            'unreviewed_count' => 0,
            'post_confirmation_pending_count' => 0,
            'total_attention_count' => 0,
        ];
        $harness->assertSame('All reviewed', (string)$metric->invoke($service, $clearSummary));
        $harness->assertSame(
            'All transaction auto decisions have been reviewed and post-confirmed.',
            (string)$detail->invoke($service, $clearSummary)
        );
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'fixed asset review warns from Tools and Small Equipment threshold candidates', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            StandardNominalTestFixture::ensureNominals(['1300', '1330', '6070', '6200']);
            yearEndChecklistServiceRequirePostedSourceWorkSchema($harness);
            $fixture = yearEndChecklistServiceCreatePostedSourceWorkFixture();
            InterfaceDB::prepareExecute(
                'UPDATE transactions
                 SET amount = :amount,
                     description = :description,
                     nominal_account_id = :nominal_account_id,
                     category_status = :category_status
                 WHERE id = :id',
                [
                    'amount' => '-600.00',
                    'description' => 'Potential asset drill',
                    'nominal_account_id' => yearEndChecklistServiceNominalId('6070'),
                    'category_status' => 'manual',
                    'id' => (int)$fixture['transaction_id'],
                ]
            );
            $settingsStore = new \eel_accounts\Store\CompanySettingsStore((int)$fixture['company_id']);
            $settingsStore->set('tools_small_equipment_nominal_id', yearEndChecklistServiceNominalId('6070'), 'int');
            $settingsStore->set('potential_asset_threshold', 500, 'int');
            $settingsStore->flush();

            $service = new \eel_accounts\Service\YearEndChecklistService();
            $warningChecklist = $service->fetchChecklist((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $warningCheck = yearEndChecklistServiceFindCheck((array)$warningChecklist, 'fixed_asset_review_placeholder');

            $harness->assertSame('warning', (string)($warningCheck['status'] ?? ''));
            $harness->assertSame('1', (string)($warningCheck['metric_value'] ?? ''));
            $harness->assertSame('?page=assets&show_card=not_an_asset', (string)($warningCheck['action_url'] ?? ''));
            $harness->assertSame('assets', (string)($warningCheck['workflow_page'] ?? ''));
            $harness->assertSame('not_an_asset', (string)(($warningCheck['workflow_fields'] ?? [])['show_card'] ?? ''));

            if (InterfaceDB::tableExists('year_end_review_acknowledgements')
                && InterfaceDB::columnExists('year_end_review_acknowledgements', 'basis_hash')) {
                $approval = $service->acknowledgeReviewCheck(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    'fixed_asset_review_placeholder',
                    true,
                    'Reviewed candidate.',
                    'test'
                );
                $harness->assertSame(true, (bool)($approval['success'] ?? false));
                $currentCheck = yearEndChecklistServiceFindCheck(
                    (array)$service->fetchChecklist((int)$fixture['company_id'], (int)$fixture['accounting_period_id']),
                    'fixed_asset_review_placeholder'
                );
                $harness->assertSame('pass', (string)($currentCheck['status'] ?? ''));
                $harness->assertSame('current', (string)($currentCheck['acknowledgement_state'] ?? ''));

                InterfaceDB::prepareExecute(
                    'UPDATE transactions SET amount = :amount WHERE id = :id',
                    ['amount' => '-650.00', 'id' => (int)$fixture['transaction_id']]
                );
                $staleCheck = yearEndChecklistServiceFindCheck(
                    (array)$service->fetchChecklist((int)$fixture['company_id'], (int)$fixture['accounting_period_id']),
                    'fixed_asset_review_placeholder'
                );
                $harness->assertSame('warning', (string)($staleCheck['status'] ?? ''));
                $harness->assertSame('stale', (string)($staleCheck['acknowledgement_state'] ?? ''));
                $harness->assertSame(true, str_contains((string)($staleCheck['detail_text'] ?? ''), 'Review required — underlying data changed'));

                InterfaceDB::prepareExecute(
                    'UPDATE transactions SET amount = :amount WHERE id = :id',
                    ['amount' => '-600.00', 'id' => (int)$fixture['transaction_id']]
                );
                $restoredCheck = yearEndChecklistServiceFindCheck(
                    (array)$service->fetchChecklist((int)$fixture['company_id'], (int)$fixture['accounting_period_id']),
                    'fixed_asset_review_placeholder'
                );
                $harness->assertSame('pass', (string)($restoredCheck['status'] ?? ''));
                $harness->assertSame('current', (string)($restoredCheck['acknowledgement_state'] ?? ''));
            }

            InterfaceDB::prepareExecute(
                'UPDATE transactions
                 SET amount = :amount
                 WHERE id = :id',
                [
                    'amount' => '-500.00',
                    'id' => (int)$fixture['transaction_id'],
                ]
            );
            $passChecklist = $service->fetchChecklist((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $passCheck = yearEndChecklistServiceFindCheck((array)$passChecklist, 'fixed_asset_review_placeholder');

            $harness->assertSame('pass', (string)($passCheck['status'] ?? ''));
            $harness->assertSame('0', (string)($passCheck['metric_value'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'transaction coverage accepts valid transfers and ready splits with null header nominal', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            StandardNominalTestFixture::ensureNominals(['6200']);
            yearEndChecklistServiceRequireTransactionCoverageSchema($harness);
            $fixture = yearEndChecklistServiceCreateTransactionCoverageFixture();
            $metrics = new \eel_accounts\Service\YearEndMetricsService();

            $uncategorisedCount = $metrics->uncategorisedTransactionsCount(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-01-01',
                '2026-12-31'
            );
            $harness->assertSame(1, $uncategorisedCount);

            $tiles = $metrics->buildMonthTiles(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-01-01',
                '2026-12-31'
            );
            $tilesByMonth = [];
            foreach ($tiles as $tile) {
                $tilesByMonth[(string)($tile['month_key'] ?? '')] = $tile;
            }

            $harness->assertSame('amber', (string)(($tilesByMonth['2026-01-01'] ?? [])['status'] ?? ''));
            $harness->assertSame(1, (int)(($tilesByMonth['2026-01-01'] ?? [])['uncategorised_count'] ?? 0));
            $harness->assertSame('green', (string)(($tilesByMonth['2026-02-01'] ?? [])['status'] ?? ''));
            $harness->assertSame(0, (int)(($tilesByMonth['2026-02-01'] ?? [])['uncategorised_count'] ?? -1));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'posted source work summary tracks unposted transactions expenses and assets', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            StandardNominalTestFixture::ensureNominals(['1300', '1330', '6200']);
            yearEndChecklistServiceRequirePostedSourceWorkSchema($harness);
            $fixture = yearEndChecklistServiceCreatePostedSourceWorkFixture();
            $metrics = new \eel_accounts\Service\YearEndMetricsService();

            $summary = $metrics->postedSourceWorkSummary(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-01-01',
                '2026-12-31'
            );
            $harness->assertSame(1, (int)$summary['unposted_transactions']);
            $harness->assertSame(1, (int)$summary['unposted_expense_claims']);
            $harness->assertSame(1, (int)$summary['unposted_assets']);
            $harness->assertSame(3, (int)$summary['total_unposted']);

            $transactionJournalId = yearEndChecklistServiceInsertPostedJournal(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'bank_csv',
                'transaction:' . (int)$fixture['transaction_id'],
                '2026-03-15',
                'Posted transaction fixture'
            );
            $expenseJournalId = yearEndChecklistServiceInsertPostedJournal(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'expense_register',
                (string)$fixture['claim_reference_code'],
                '2026-03-31',
                'Posted expense fixture'
            );
            $assetJournalId = yearEndChecklistServiceInsertPostedJournal(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'asset_register',
                'asset:' . (string)$fixture['asset_code'] . ':opening',
                '2026-04-01',
                'Posted asset fixture'
            );

            InterfaceDB::prepareExecute(
                'UPDATE expense_claims
                 SET status = :status,
                     posted_journal_id = :posted_journal_id
                 WHERE id = :id',
                [
                    'status' => 'posted',
                    'posted_journal_id' => $expenseJournalId,
                    'id' => (int)$fixture['expense_claim_id'],
                ]
            );
            InterfaceDB::prepareExecute(
                'UPDATE asset_register
                 SET linked_journal_id = :linked_journal_id
                 WHERE id = :id',
                [
                    'linked_journal_id' => $assetJournalId,
                    'id' => (int)$fixture['asset_id'],
                ]
            );

            $postedSummary = $metrics->postedSourceWorkSummary(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-01-01',
                '2026-12-31'
            );
            $harness->assertSame(0, (int)$postedSummary['unposted_transactions']);
            $harness->assertSame(0, (int)$postedSummary['unposted_expense_claims']);
            $harness->assertSame(0, (int)$postedSummary['unposted_assets']);
            $harness->assertSame(0, (int)$postedSummary['total_unposted']);
            $harness->assertTrue($transactionJournalId > 0);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function yearEndChecklistServiceFindCheck(array $checklist, string $checkCode): array
{
    foreach ((array)($checklist['checks_flat'] ?? []) as $check) {
        if (is_array($check) && (string)($check['check_code'] ?? '') === $checkCode) {
            return $check;
        }
    }

    throw new RuntimeException('Unable to find year-end check: ' . $checkCode);
}

function yearEndChecklistServiceRequireDepreciationLockSchema(GeneratedServiceClassTestHarness $harness): void
{
    yearEndChecklistServiceRequireDepreciationLockTables($harness);

    foreach (['1000', '1300', '1330', '4000', '6200'] as $code) {
        if (yearEndChecklistServiceNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }
}

function yearEndChecklistServiceRequireDepreciationLockTables(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'journal_entry_metadata', 'nominal_accounts', 'asset_register', 'asset_depreciation_entries', 'year_end_reviews', 'year_end_review_acknowledgements', 'year_end_audit_log', 'capital_allowance_pool_runs', 'capital_allowance_asset_calculations', 'tax_loss_carryforwards'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
    foreach (['basis_version', 'basis_hash', 'basis_json'] as $column) {
        if (!InterfaceDB::columnExists('year_end_review_acknowledgements', $column)) {
            $harness->skip($column . ' column is not available.');
        }
    }
}

function yearEndChecklistServiceRequireDirectorLoanOffsetLockSchema(GeneratedServiceClassTestHarness $harness): void
{
    yearEndChecklistServiceRequireDirectorLoanOffsetLockTables($harness);

    foreach (['1200', '2100'] as $code) {
        if (yearEndChecklistServiceNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }
}

function yearEndChecklistServiceRequireDirectorLoanOffsetLockTables(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'nominal_accounts', 'journal_entry_metadata'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

function yearEndChecklistServiceRequirePostedSourceWorkSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'company_settings', 'statement_uploads', 'transactions', 'expense_claimants', 'expense_claims', 'expense_claim_lines', 'asset_register', 'journals', 'nominal_accounts'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['1300', '1330', '6200'] as $code) {
        if (yearEndChecklistServiceNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }
}

function yearEndChecklistServiceRequireTransactionCoverageSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'company_accounts', 'statement_uploads', 'transactions', 'transaction_splits', 'transaction_split_lines', 'nominal_accounts'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    if (yearEndChecklistServiceNominalId('6200') <= 0) {
        $harness->skip('Nominal 6200 is not available.');
    }
}

function yearEndChecklistServiceCreateDepreciationLockFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('61' . $marker);
    $accountingPeriodId = (int)('62' . $marker);
    $assetId = (int)('63' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Year End Depreciation Fixture ' . $marker,
            'company_number' => 'YED' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'YED FY ' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => 'year-end-depreciation-fixture-' . $marker,
            'journal_date' => '2025-12-31',
            'description' => 'Year end depreciation fixture ' . $marker,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => 'year-end-depreciation-fixture-' . $marker,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 1200.00, 0.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => yearEndChecklistServiceNominalId('1000'),
            'line_description' => 'Fixture bank debit',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 0.00, 1200.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => yearEndChecklistServiceNominalId('4000'),
            'line_description' => 'Fixture sales credit',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            id,
            company_id,
            asset_code,
            description,
            category,
            nominal_account_id,
            accum_dep_nominal_id,
            purchase_date,
            cost,
            useful_life_years,
            depreciation_method,
            residual_value,
            status
         ) VALUES (
            :id,
            :company_id,
            :asset_code,
            :description,
            :category,
            :nominal_account_id,
            :accum_dep_nominal_id,
            :purchase_date,
            :cost,
            :useful_life_years,
            :depreciation_method,
            :residual_value,
            :status
         )',
        [
            'id' => $assetId,
            'company_id' => $companyId,
            'asset_code' => 'YED-' . $marker,
            'description' => 'Year end depreciation fixture asset',
            'category' => 'tools_equipment',
            'nominal_account_id' => yearEndChecklistServiceNominalId('1300'),
            'accum_dep_nominal_id' => yearEndChecklistServiceNominalId('1330'),
            'purchase_date' => '2025-01-01',
            'cost' => 1200.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'asset_id' => $assetId,
    ];
}

function yearEndChecklistServiceCreateTransactionCoverageFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('74' . $marker);
    $accountingPeriodId = (int)('75' . $marker);
    $sourceAccountId = (int)('76' . $marker);
    $transferAccountId = (int)('77' . $marker);
    $uploadId = (int)('78' . $marker);
    $uncategorisedTransactionId = (int)('79' . $marker);
    $transferTransactionId = (int)('80' . $marker);
    $splitTransactionId = (int)('81' . $marker);
    $nominalId = yearEndChecklistServiceNominalId('6200');

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Year End Coverage Fixture ' . $marker,
            'company_number' => 'YEC' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'YEC FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    foreach ([
        [$sourceAccountId, 'Coverage Source Account'],
        [$transferAccountId, 'Coverage Transfer Account'],
    ] as $account) {
        InterfaceDB::prepareExecute(
            'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
             VALUES (:id, :company_id, :account_name, :account_type, :nominal_account_id, 1)',
            [
                'id' => (int)$account[0],
                'company_id' => $companyId,
                'account_name' => (string)$account[1] . ' ' . $marker,
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                'nominal_account_id' => $nominalId,
            ]
        );
    }
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            id,
            company_id,
            accounting_period_id,
            account_id,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256,
            workflow_status
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :account_id,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256,
            :workflow_status
         )',
        [
            'id' => $uploadId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_id' => $sourceAccountId,
            'statement_month' => '2026-02-01',
            'original_filename' => 'coverage-' . $marker . '.csv',
            'stored_filename' => 'coverage-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'coverage-upload-' . $marker),
            'workflow_status' => 'committed',
        ]
    );

    yearEndChecklistServiceInsertCoverageTransaction(
        $uncategorisedTransactionId,
        $companyId,
        $accountingPeriodId,
        $uploadId,
        $sourceAccountId,
        '2026-01-15',
        'Coverage uncategorised fixture',
        '-10.00',
        'uncategorised',
        null,
        null,
        0,
        $marker . '-uncategorised'
    );
    yearEndChecklistServiceInsertCoverageTransaction(
        $transferTransactionId,
        $companyId,
        $accountingPeriodId,
        $uploadId,
        $sourceAccountId,
        '2026-02-10',
        'Coverage transfer fixture',
        '-50.00',
        'manual',
        null,
        $transferAccountId,
        1,
        $marker . '-transfer'
    );
    yearEndChecklistServiceInsertCoverageTransaction(
        $splitTransactionId,
        $companyId,
        $accountingPeriodId,
        $uploadId,
        $sourceAccountId,
        '2026-02-20',
        'Coverage split fixture',
        '-120.00',
        'manual',
        null,
        null,
        0,
        $marker . '-split'
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO transaction_splits (transaction_id)
         VALUES (:transaction_id)',
        ['transaction_id' => $splitTransactionId]
    );
    $splitId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM transaction_splits WHERE transaction_id = :transaction_id LIMIT 1',
        ['transaction_id' => $splitTransactionId]
    );
    foreach ([[1, '60.00'], [2, '60.00']] as $line) {
        InterfaceDB::prepareExecute(
            'INSERT INTO transaction_split_lines (split_id, line_number, amount, nominal_account_id, is_deferred)
             VALUES (:split_id, :line_number, :amount, :nominal_account_id, 0)',
            [
                'split_id' => $splitId,
                'line_number' => (int)$line[0],
                'amount' => (string)$line[1],
                'nominal_account_id' => $nominalId,
            ]
        );
    }

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ];
}

function yearEndChecklistServiceInsertCoverageTransaction(
    int $transactionId,
    int $companyId,
    int $accountingPeriodId,
    int $uploadId,
    int $accountId,
    string $date,
    string $description,
    string $amount,
    string $categoryStatus,
    ?int $nominalAccountId,
    ?int $transferAccountId,
    int $isInternalTransfer,
    string $dedupeSeed
): void {
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id,
            company_id,
            accounting_period_id,
            statement_upload_id,
            account_id,
            txn_date,
            description,
            amount,
            dedupe_hash,
            nominal_account_id,
            transfer_account_id,
            is_internal_transfer,
            category_status
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :account_id,
            :txn_date,
            :description,
            :amount,
            :dedupe_hash,
            :nominal_account_id,
            :transfer_account_id,
            :is_internal_transfer,
            :category_status
         )',
        [
            'id' => $transactionId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'account_id' => $accountId,
            'txn_date' => $date,
            'description' => $description,
            'amount' => $amount,
            'dedupe_hash' => hash('sha256', 'year-end-coverage-' . $dedupeSeed),
            'nominal_account_id' => $nominalAccountId,
            'transfer_account_id' => $transferAccountId,
            'is_internal_transfer' => $isInternalTransfer,
            'category_status' => $categoryStatus,
        ]
    );
}

function yearEndChecklistServiceCreateDirectorLoanOffsetFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('64' . $marker);
    $accountingPeriodId = (int)('65' . $marker);
    $assetNominalId = yearEndChecklistServiceNominalId('1200');
    $liabilityNominalId = yearEndChecklistServiceNominalId('2100');

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Year End Director Loan Fixture ' . $marker,
            'company_number' => 'YDL' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO company_directors (
            company_id, source, external_key, full_name, officer_role, appointed_on, is_active
         ) VALUES (
            :company_id, :source, :external_key, :full_name, :officer_role, :appointed_on, 1
         )',
        [
            'company_id' => $companyId,
            'source' => 'companies_house',
            'external_key' => 'year-end-checklist:' . $marker,
            'full_name' => 'Primary Director',
            'officer_role' => 'director',
            'appointed_on' => '2020-01-01',
        ]
    );
    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('director_loan_asset_nominal_id', $assetNominalId, 'int');
    $settings->set('director_loan_liability_nominal_id', $liabilityNominalId, 'int');
    $settings->flush();
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'YDL FY ' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );

    yearEndChecklistServiceInsertDirectorLoanLineJournal($companyId, $accountingPeriodId, $assetNominalId, 1000.00, 0.00, 'asset', $marker);
    yearEndChecklistServiceInsertDirectorLoanLineJournal($companyId, $accountingPeriodId, $liabilityNominalId, 0.00, 1500.00, 'liability', $marker);

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ];
}

function yearEndChecklistServiceApproveDirectorLoanOffset(array $fixture): void
{
    $companyId = (int)$fixture['company_id'];
    $accountingPeriodId = (int)$fixture['accounting_period_id'];
    $result = (new \eel_accounts\Service\DirectorLoanReconciliationService())->saveYearEndReview(
        $companyId,
        $accountingPeriodId,
        true,
        'test'
    );
    if (empty($result['success'])) {
        throw new RuntimeException(implode(' ', (array)($result['errors'] ?? ['Unable to confirm Director Loan facts.'])));
    }
}

function yearEndChecklistServiceCreatePostedSourceWorkFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('66' . $marker);
    $accountingPeriodId = (int)('67' . $marker);
    $uploadId = (int)('68' . $marker);
    $transactionId = (int)('69' . $marker);
    $claimantId = (int)('70' . $marker);
    $expenseClaimId = (int)('71' . $marker);
    $expenseLineId = (int)('72' . $marker);
    $assetId = (int)('73' . $marker);
    $claimReferenceCode = 'YPS-' . substr($marker, 0, 6);
    $assetCode = 'YPS-A-' . $marker;

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Year End Posted Source Fixture ' . $marker,
            'company_number' => 'YPS' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'YPS FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            id,
            company_id,
            accounting_period_id,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256,
            workflow_status
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256,
            :workflow_status
         )',
        [
            'id' => $uploadId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_month' => '2026-03-01',
            'original_filename' => 'posted-source-' . $marker . '.csv',
            'stored_filename' => 'posted-source-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'posted-source-upload-' . $marker),
            'workflow_status' => 'committed',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id,
            company_id,
            accounting_period_id,
            statement_upload_id,
            txn_date,
            description,
            amount,
            dedupe_hash,
            nominal_account_id,
            category_status
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :txn_date,
            :description,
            :amount,
            :dedupe_hash,
            :nominal_account_id,
            :category_status
         )',
        [
            'id' => $transactionId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'txn_date' => '2026-03-15',
            'description' => 'Posted source transaction fixture',
            'amount' => '-25.00',
            'dedupe_hash' => hash('sha256', 'posted-source-transaction-' . $marker),
            'nominal_account_id' => yearEndChecklistServiceNominalId('6200'),
            'category_status' => 'manual',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claimants (id, company_id, claimant_name, is_active)
         VALUES (:id, :company_id, :claimant_name, 1)',
        [
            'id' => $claimantId,
            'company_id' => $companyId,
            'claimant_name' => 'Posted Source Claimant ' . $marker,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claims (
            id,
            company_id,
            accounting_period_id,
            claimant_id,
            claim_year,
            claim_month,
            period_start,
            period_end,
            claim_reference_code,
            status
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :claimant_id,
            :claim_year,
            :claim_month,
            :period_start,
            :period_end,
            :claim_reference_code,
            :status
         )',
        [
            'id' => $expenseClaimId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'claimant_id' => $claimantId,
            'claim_year' => 2026,
            'claim_month' => 3,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'claim_reference_code' => $claimReferenceCode,
            'status' => 'draft',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claim_lines (
            id,
            expense_claim_id,
            line_number,
            expense_date,
            description,
            amount,
            nominal_account_id
         ) VALUES (
            :id,
            :expense_claim_id,
            :line_number,
            :expense_date,
            :description,
            :amount,
            :nominal_account_id
         )',
        [
            'id' => $expenseLineId,
            'expense_claim_id' => $expenseClaimId,
            'line_number' => 1,
            'expense_date' => '2026-03-20',
            'description' => 'Posted source expense fixture',
            'amount' => '10.00',
            'nominal_account_id' => yearEndChecklistServiceNominalId('6200'),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            id,
            company_id,
            asset_code,
            description,
            category,
            nominal_account_id,
            accum_dep_nominal_id,
            purchase_date,
            cost,
            useful_life_years,
            depreciation_method,
            residual_value,
            status
         ) VALUES (
            :id,
            :company_id,
            :asset_code,
            :description,
            :category,
            :nominal_account_id,
            :accum_dep_nominal_id,
            :purchase_date,
            :cost,
            :useful_life_years,
            :depreciation_method,
            :residual_value,
            :status
         )',
        [
            'id' => $assetId,
            'company_id' => $companyId,
            'asset_code' => $assetCode,
            'description' => 'Posted source asset fixture',
            'category' => 'tools_equipment',
            'nominal_account_id' => yearEndChecklistServiceNominalId('1300'),
            'accum_dep_nominal_id' => yearEndChecklistServiceNominalId('1330'),
            'purchase_date' => '2026-04-01',
            'cost' => '300.00',
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'residual_value' => '0.00',
            'status' => 'active',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'transaction_id' => $transactionId,
        'expense_claim_id' => $expenseClaimId,
        'claim_reference_code' => $claimReferenceCode,
        'asset_id' => $assetId,
        'asset_code' => $assetCode,
    ];
}

function yearEndChecklistServiceInsertPostedJournal(
    int $companyId,
    int $accountingPeriodId,
    string $sourceType,
    string $sourceRef,
    string $journalDate,
    string $description
): int {
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'journal_date' => $journalDate,
            'description' => $description,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id
           AND source_type = :source_type
           AND source_ref = :source_ref
         LIMIT 1',
        [
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
        ]
    );
}

function yearEndChecklistServiceInsertDirectorLoanLineJournal(int $companyId, int $accountingPeriodId, int $nominalId, float $debit, float $credit, string $key, string $marker): void
{
    $sourceRef = 'year-end-director-loan-fixture-' . $marker . '-' . $key;
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => '2025-12-31',
            'description' => 'Year end director loan fixture ' . $key,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => $sourceRef,
        ]
    );
    $directorId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM company_directors WHERE company_id = :company_id ORDER BY id LIMIT 1',
        ['company_id' => $companyId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, director_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :director_id, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalId,
            'director_id' => $directorId,
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'line_description' => 'Year end director loan fixture',
        ]
    );
}

function yearEndChecklistServiceNominalId(string $code): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}

function yearEndChecklistServiceEnsureNominal(string $code, string $name, string $accountType): int
{
    $existingId = yearEndChecklistServiceNominalId($code);
    if ($existingId > 0) {
        return $existingId;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, is_active)
         VALUES (:code, :name, :account_type, 1)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
        ]
    );

    return yearEndChecklistServiceNominalId($code);
}
