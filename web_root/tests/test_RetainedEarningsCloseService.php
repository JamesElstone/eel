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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ParticipatorLoanTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\RetainedEarningsCloseService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'posts loss close to retained earnings without changing source journals', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            retainedEarningsCloseRequireSchema($harness);
            StandardNominalTestFixture::ensureNominals(['1000', '1200', '2100', '3000', '4000', '5000']);
            $fixture = retainedEarningsCloseCreateLossFixture();
            $service = new \eel_accounts\Service\RetainedEarningsCloseService();
            $precomputedProvision = [
                'available' => false,
                'errors' => ['Precomputed provision unavailable for this fixture.'],
                'unposted_corporation_tax_adjustment' => 0.0,
            ];
            $precomputedBalanceSheet = [
                'fixed_assets' => 120.0,
                'current_assets' => 30.0,
                'creditors_within_one_year' => 20.0,
                'creditors_after_more_than_one_year' => 5.0,
                'equity_capital_reserves' => 125.0,
            ];
            $precomputedDepreciationPreview = [
                'success' => false,
                'errors' => ['Precomputed depreciation preview unavailable for this fixture.'],
            ];
            $precomputedContext = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                $precomputedProvision,
                $precomputedBalanceSheet,
                $precomputedDepreciationPreview
            );
            $harness->assertSame(true, (bool)($precomputedContext['available'] ?? false));
            $harness->assertSame($precomputedProvision, (array)($precomputedContext['corporation_tax_provision'] ?? []));
            $harness->assertSame(150.0, (float)(($precomputedContext['summary'] ?? [])['assets'] ?? 0));
            $harness->assertSame(25.0, (float)(($precomputedContext['summary'] ?? [])['liabilities'] ?? 0));
            $harness->assertSame(125.0, (float)(($precomputedContext['summary'] ?? [])['equity'] ?? 0));
            $harness->assertSame($precomputedDepreciationPreview, (array)($precomputedContext['depreciation_preview'] ?? []));
            $harness->assertSame(true, array_key_exists('journal_lines', $precomputedContext));
            $harness->assertSame(true, array_key_exists('depreciation_preview', $precomputedContext));
            $harness->assertSame(false, array_key_exists('preview_deferred', $precomputedContext));

            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($context['available'] ?? false));
            $harness->assertSame('-200.00', number_format((float)(($context['summary'] ?? [])['current_profit_loss'] ?? 0), 2, '.', ''));
            $harness->assertSame(
                (string)(($precomputedContext['summary'] ?? [])['current_profit_loss'] ?? ''),
                (string)(($context['summary'] ?? [])['current_profit_loss'] ?? '')
            );
            $harness->assertSame(
                (array)($precomputedContext['journal_lines'] ?? []),
                (array)($context['journal_lines'] ?? [])
            );
            $harness->assertSame(false, (bool)($context['acknowledged'] ?? true));

            $bypassAttempt = $service->postClose(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test',
                true
            );
            $harness->assertSame(false, (bool)($bypassAttempt['success'] ?? true));
            $harness->assertSame(
                0,
                InterfaceDB::countWhere('journal_entry_metadata', [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                ])
            );

            $acknowledged = $service->saveAcknowledgement((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], true, 'test');
            $harness->assertSame(true, (bool)($acknowledged['success'] ?? false));
            \eel_accounts\Support\RequestCache::clear();
            $reviewAfterApproval = (new \eel_accounts\Service\DividendReserveClassificationService())
                ->fetchReviewContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2024-12-31');
            $harness->assertSame(true, (bool)($reviewAfterApproval['snapshot_current'] ?? false));
            \eel_accounts\Support\RequestCache::clear();

            $posted = $service->postClose((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            if (empty($posted['success'])) {
                throw new RuntimeException(
                    'Retained earnings close failed: '
                    . implode('; ', array_map('strval', (array)($posted['errors'] ?? [])))
                );
            }

            $closeJournal = (new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_KEY
            );
            $harness->assertTrue(is_array($closeJournal));

            $retainedLine = retainedEarningsCloseLineForNominal((array)$closeJournal, (int)$fixture['retained_earnings_nominal_id']);
            $harness->assertSame('200.00', number_format((float)($retainedLine['debit'] ?? 0), 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)($retainedLine['credit'] ?? 0), 2, '.', ''));

            $profit = (new \eel_accounts\Service\YearEndMetricsService())->profitAndLossSummary(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2024-01-01',
                '2024-12-31'
            );
            $harness->assertSame('-200.00', number_format((float)($profit['profit_before_tax'] ?? 0), 2, '.', ''));

            $balanceSheet = (new \eel_accounts\Service\YearEndMetricsService())->fetchBalanceSheetMetricValues(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2024-01-01',
                '2024-12-31'
            );
            $harness->assertSame('-200.00', number_format((float)($balanceSheet['equity_capital_reserves'] ?? 0), 2, '.', ''));

            $harness->assertSame(2, retainedEarningsCloseFixtureSourceJournalCount((int)$fixture['company_id'], (string)$fixture['marker']));

            $locked = (new \eel_accounts\Service\YearEndLockService())->lockPeriod(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test'
            );
            if (empty($locked['success'])) {
                throw new RuntimeException(
                    'Year End lock failed: '
                    . implode('; ', array_map('strval', (array)($locked['errors'] ?? [])))
                );
            }
            $unlocked = (new \eel_accounts\Service\YearEndLockService())->unlockPeriod((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            if (empty($unlocked['success'])) {
                throw new RuntimeException(
                    'Year End unlock failed: '
                    . implode('; ', array_map('strval', (array)($unlocked['errors'] ?? [])))
                );
            }
            $harness->assertSame(true, (new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_KEY
            ) !== null);
            $harness->assertSame(2, retainedEarningsCloseFixtureSourceJournalCount((int)$fixture['company_id'], (string)$fixture['marker']));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'blocks acknowledgement and posting until the prior accounting period is locked', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            retainedEarningsCloseRequireSchema($harness);
            if (!InterfaceDB::tableExists('year_end_section_review_bundles')) {
                $harness->skip('year_end_section_review_bundles table is not available.');
            }
            StandardNominalTestFixture::ensureNominals(['1000', '1200', '2100', '3000', '4000', '5000']);
            $fixture = retainedEarningsCloseCreateLossFixture();
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'label' => 'Retained prior ' . (string)$fixture['marker'],
                    'period_start' => '2023-01-01',
                    'period_end' => '2023-12-31',
                ]
            );
            $priorPeriodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
                ['company_id' => (int)$fixture['company_id'], 'label' => 'Retained prior ' . (string)$fixture['marker']]
            );

            $service = new \eel_accounts\Service\RetainedEarningsCloseService();
            $sectionApprovals = new \eel_accounts\Service\YearEndSectionApprovalService();
            $blocked = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame('prior_period_unlocked', (string)(($blocked['prior_period_dependency'] ?? [])['status'] ?? ''));
            $harness->assertSame(false, (bool)($blocked['can_acknowledge'] ?? true));
            $harness->assertSame(false, (bool)($service->saveAcknowledgement((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], true, 'test')['success'] ?? true));
            $harness->assertSame(false, (bool)($service->postClose((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test', true)['success'] ?? true));

            $blockedReview = $sectionApprovals->fetchReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'retained_earnings_close_confirmation'
            );
            $blockedToken = (string)(($blockedReview['bundle'] ?? [])['source_token'] ?? '');
            $harness->assertSame('prior_period_unlocked', (string)(($blockedReview['display']['prior_period_dependency'] ?? [])['status'] ?? ''));
            $harness->assertSame(false, (bool)($blockedReview['can_approve'] ?? true));
            $harness->assertSame(64, strlen($blockedToken));

            $lockService = new \eel_accounts\Service\YearEndLockService();
            $locked = $lockService->lockPeriod((int)$fixture['company_id'], $priorPeriodId, 'test');
            $harness->assertSame(true, (bool)($locked['success'] ?? false));
            $ready = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame('prior_period_locked', (string)(($ready['prior_period_dependency'] ?? [])['status'] ?? ''));
            $harness->assertSame(true, (bool)($ready['can_acknowledge'] ?? false));

            $readyReview = $sectionApprovals->fetchReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'retained_earnings_close_confirmation'
            );
            $readyToken = (string)(($readyReview['bundle'] ?? [])['source_token'] ?? '');
            $harness->assertSame('prior_period_locked', (string)(($readyReview['display']['prior_period_dependency'] ?? [])['status'] ?? ''));
            $harness->assertSame(true, (bool)($readyReview['can_approve'] ?? false));
            $harness->assertSame(false, hash_equals($blockedToken, $readyToken));

            $unlocked = $lockService->unlockPeriod(
                (int)$fixture['company_id'],
                $priorPeriodId,
                'test',
                'Verify the following-period P&L review cache is refreshed.'
            );
            $harness->assertSame(true, (bool)($unlocked['success'] ?? false));
            $unlockedReview = $sectionApprovals->fetchReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'retained_earnings_close_confirmation'
            );
            $unlockedToken = (string)(($unlockedReview['bundle'] ?? [])['source_token'] ?? '');
            $harness->assertSame('prior_period_unlocked', (string)(($unlockedReview['display']['prior_period_dependency'] ?? [])['status'] ?? ''));
            $harness->assertSame(false, (bool)($unlockedReview['can_approve'] ?? true));
            $harness->assertSame(false, hash_equals($readyToken, $unlockedToken));

            $relocked = $lockService->lockPeriod((int)$fixture['company_id'], $priorPeriodId, 'test');
            $harness->assertSame(true, (bool)($relocked['success'] ?? false));
            $relockedReview = $sectionApprovals->fetchReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'retained_earnings_close_confirmation'
            );
            $harness->assertSame('prior_period_locked', (string)(($relockedReview['display']['prior_period_dependency'] ?? [])['status'] ?? ''));
            $harness->assertSame(true, (bool)($relockedReview['can_approve'] ?? false));
            $harness->assertSame(
                false,
                hash_equals($unlockedToken, (string)(($relockedReview['bundle'] ?? [])['source_token'] ?? ''))
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'keeps a first-period P&L review approval-ready without a prior lock', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            retainedEarningsCloseRequireSchema($harness);
            if (!InterfaceDB::tableExists('year_end_section_review_bundles')) {
                $harness->skip('year_end_section_review_bundles table is not available.');
            }
            StandardNominalTestFixture::ensureNominals(['1000', '1200', '2100', '3000', '4000', '5000']);
            $fixture = retainedEarningsCloseCreateLossFixture();

            $review = (new \eel_accounts\Service\YearEndSectionApprovalService())->fetchReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'retained_earnings_close_confirmation'
            );

            $harness->assertSame('first_period', (string)(($review['display']['prior_period_dependency'] ?? [])['status'] ?? ''));
            $harness->assertSame(true, (bool)($review['can_approve'] ?? false));
            $harness->assertSame(64, strlen((string)(($review['bundle'] ?? [])['source_token'] ?? '')));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'includes pending year-end depreciation in approval figures', static function () use ($harness): void {
        retainedEarningsCloseRequireDepreciationSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            retainedEarningsCloseEnsureNominal('1000', 'Bank', 'asset');
            retainedEarningsCloseEnsureNominal('1300', 'Tools', 'asset');
            retainedEarningsCloseEnsureNominal('1330', 'Accum Dep - Tools', 'asset');
            retainedEarningsCloseEnsureNominal('3000', 'Retained Earnings', 'equity');
            retainedEarningsCloseEnsureNominal('4000', 'Sales', 'income');
            retainedEarningsCloseEnsureNominal('6200', 'Depreciation Expense', 'expense');

            $fixture = retainedEarningsCloseCreateDepreciationFixture();
            $service = new \eel_accounts\Service\RetainedEarningsCloseService();
            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($context['available'] ?? false));
            $harness->assertSame(1, (int)(($context['depreciation_preview'] ?? [])['created'] ?? 0));
            $harness->assertSame('400.00', number_format((float)(($context['depreciation_preview'] ?? [])['total_amount'] ?? 0), 2, '.', ''));
            $harness->assertSame('600.00', number_format((float)(($context['summary'] ?? [])['current_profit_loss'] ?? 0), 2, '.', ''));

            retainedEarningsCloseSaveReserveReview($fixture);
            $acknowledged = $service->saveAcknowledgement((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], true, 'test');
            $harness->assertSame(true, (bool)($acknowledged['success'] ?? false));
            $harness->assertSame(false, (bool)($service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id'])['acknowledgement_stale'] ?? true));

            $postedDepreciation = (new \eel_accounts\Service\AssetService())->runDepreciation((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($postedDepreciation['success'] ?? false));

            $afterPosting = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(0, (int)(($afterPosting['depreciation_preview'] ?? [])['created'] ?? -1));
            $harness->assertSame('600.00', number_format((float)(($afterPosting['summary'] ?? [])['current_profit_loss'] ?? 0), 2, '.', ''));
            $harness->assertSame(false, (bool)($afterPosting['acknowledgement_stale'] ?? true));
            $postedClose = $service->postClose((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            $harness->assertSame(true, (bool)($postedClose['success'] ?? false));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'includes pending CT provision in approval figures', static function () use ($harness): void {
        $service = new \eel_accounts\Service\RetainedEarningsCloseService();
        $method = new ReflectionMethod($service, 'includePendingCorporationTaxProvisionRows');
        $method->setAccessible(true);
        $rows = [
            [
                'id' => 8500,
                'code' => '8500',
                'name' => 'Corporation Tax Expense',
                'account_type' => 'expense',
                'tax_treatment' => 'disallowable',
                'total_debit' => '100.00',
                'total_credit' => '0.00',
            ],
        ];

        $withCharge = $method->invoke($service, $rows, [
            'available' => true,
            'unposted_corporation_tax_adjustment' => 50.25,
        ]);
        $harness->assertSame('150.25', (string)$withCharge[0]['total_debit']);
        $harness->assertSame('0.00', (string)$withCharge[0]['total_credit']);
        $harness->assertSame('50.25', (string)$withCharge[0]['pending_corporation_tax_provision']);

        $withReversal = $method->invoke($service, $rows, [
            'available' => true,
            'unposted_corporation_tax_adjustment' => -25.50,
        ]);
        $harness->assertSame('100.00', (string)$withReversal[0]['total_debit']);
        $harness->assertSame('25.50', (string)$withReversal[0]['total_credit']);
        $harness->assertSame('-25.50', (string)$withReversal[0]['pending_corporation_tax_provision']);
    });

    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'blocks approval when the prepayment preview is unreliable', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            retainedEarningsCloseRequireSchema($harness);
            StandardNominalTestFixture::ensureNominals(['1000', '1200', '2100', '3000', '4000', '5000']);
            $fixture = retainedEarningsCloseCreateLossFixture();
            $service = new \eel_accounts\Service\RetainedEarningsCloseService(
                prepaymentPreviewContextFetcher: static fn(int $companyId, int $accountingPeriodId): array => [
                    'available' => true,
                    'success' => false,
                    'errors' => ['Prepayment source evidence no longer matches the approved schedule.'],
                    'adjustments' => [],
                ]
            );

            $context = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                ['available' => true, 'unposted_corporation_tax_adjustment' => 0.0],
                [
                    'fixed_assets' => 0.0,
                    'current_assets' => 0.0,
                    'creditors_within_one_year' => 0.0,
                    'creditors_after_more_than_one_year' => 0.0,
                    'equity_capital_reserves' => 0.0,
                ],
                ['success' => true, 'created' => 0, 'total_amount' => 0.0]
            );

            $harness->assertSame(false, (bool)($context['available'] ?? true));
            $harness->assertSame(false, (bool)($context['prepayment_preview_reliable'] ?? true));
            $harness->assertSame(false, (bool)($context['can_acknowledge'] ?? true));
            $harness->assertSame(false, (bool)($context['can_post'] ?? true));
            $harness->assertTrue(str_contains(implode(' ', (array)($context['errors'] ?? [])), 'no longer matches'));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\RetainedEarningsCloseService::class, 'separates share capital from the unposted profit and loss equity bridge', static function () use ($harness): void {
        InterfaceDB::beginTransaction();
        try {
            retainedEarningsCloseRequireSchema($harness);
            StandardNominalTestFixture::ensureNominals(['1000', '1200', '2100', '3000', '4000', '5000']);
            retainedEarningsCloseEnsureNominal('3010', 'Ordinary Share Capital', 'equity');
            $fixture = retainedEarningsCloseCreateLossFixture();
            retainedEarningsCloseInsertJournal(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                (string)$fixture['marker'] . '-share-capital',
                '2024-01-10',
                [
                    [
                        'nominal_account_id' => retainedEarningsCloseNominalId('1000'),
                        'debit' => '500.00',
                        'credit' => '0.00',
                        'line_description' => 'Share capital received',
                    ],
                    [
                        'nominal_account_id' => retainedEarningsCloseNominalId('3010'),
                        'debit' => '0.00',
                        'credit' => '500.00',
                        'line_description' => 'Ordinary share capital',
                    ],
                ]
            );

            $companyId = (int)$fixture['company_id'];
            $accountingPeriodId = (int)$fixture['accounting_period_id'];
            $service = new \eel_accounts\Service\RetainedEarningsCloseService();
            $context = $service->fetchContext($companyId, $accountingPeriodId);
            $summary = (array)($context['summary'] ?? []);

            $harness->assertSame('500.00', number_format((float)($summary['direct_equity_movement'] ?? 0), 2, '.', ''));
            $harness->assertSame('500.00', number_format((float)($summary['share_capital_movement'] ?? 0), 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)($summary['other_direct_equity_movement'] ?? 0), 2, '.', ''));
            $harness->assertSame('300.00', number_format((float)($summary['expected_closing_equity'] ?? 0), 2, '.', ''));
            $harness->assertSame('200.00', number_format((float)($summary['unexplained_movement_before_close'] ?? 0), 2, '.', ''));

            $beforeClose = (new \eel_accounts\Service\YearEndMetricsService())->financialStatementsSummary(
                $companyId,
                $accountingPeriodId,
                '2024-01-01',
                '2024-12-31'
            );
            $beforeBridge = (array)($beforeClose['retained_earnings'] ?? []);
            $harness->assertSame('500.00', number_format((float)($beforeBridge['share_capital_movement'] ?? 0), 2, '.', ''));
            $harness->assertSame('200.00', number_format((float)($beforeBridge['unexplained_movement'] ?? 0), 2, '.', ''));

            retainedEarningsCloseSaveReserveReview([
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]);
            $acknowledged = $service->saveAcknowledgement($companyId, $accountingPeriodId, true, 'test');
            $harness->assertSame(true, (bool)($acknowledged['success'] ?? false));
            \eel_accounts\Support\RequestCache::clear();
            $posted = $service->postClose($companyId, $accountingPeriodId, 'test');
            if (empty($posted['success'])) {
                throw new RuntimeException(
                    'Retained earnings close failed: '
                    . implode('; ', array_map('strval', (array)($posted['errors'] ?? [])))
                );
            }

            $afterClose = (new \eel_accounts\Service\YearEndMetricsService())->financialStatementsSummary(
                $companyId,
                $accountingPeriodId,
                '2024-01-01',
                '2024-12-31'
            );
            $afterBridge = (array)($afterClose['retained_earnings'] ?? []);
            $harness->assertSame('500.00', number_format((float)($afterBridge['share_capital_movement'] ?? 0), 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)($afterBridge['unexplained_movement'] ?? 0), 2, '.', ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function retainedEarningsCloseRequireSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'journal_entry_metadata', 'nominal_accounts', 'year_end_reviews', 'year_end_review_acknowledgements', 'year_end_audit_log', 'dividend_reserve_classification_rules', 'dividend_reserve_review_snapshots'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['basis_version', 'basis_hash', 'basis_json'] as $column) {
        if (!InterfaceDB::columnExists('year_end_review_acknowledgements', $column)) {
            $harness->skip($column . ' column is not available.');
        }
    }

    foreach (['as_at_date', 'brought_forward_distributable_reserves', 'dividends_declared', 'closing_distributable_reserves'] as $column) {
        if (!InterfaceDB::columnExists('dividend_reserve_review_snapshots', $column)) {
            $harness->skip($column . ' column is not available.');
        }
    }

}

function retainedEarningsCloseRequireDepreciationSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'journal_entry_metadata', 'nominal_accounts', 'year_end_reviews', 'year_end_review_acknowledgements', 'year_end_audit_log', 'asset_register', 'asset_depreciation_entries', 'dividend_reserve_classification_rules', 'dividend_reserve_review_snapshots'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['basis_version', 'basis_hash', 'basis_json'] as $column) {
        if (!InterfaceDB::columnExists('year_end_review_acknowledgements', $column)) {
            $harness->skip($column . ' column is not available.');
        }
    }

    foreach (['as_at_date', 'brought_forward_distributable_reserves', 'dividends_declared', 'closing_distributable_reserves'] as $column) {
        if (!InterfaceDB::columnExists('dividend_reserve_review_snapshots', $column)) {
            $harness->skip($column . ' column is not available.');
        }
    }
}

function retainedEarningsCloseSaveReserveReview(array $fixture): void
{
    $period = InterfaceDB::fetchOne(
        'SELECT period_end FROM accounting_periods WHERE id = :id',
        ['id' => (int)$fixture['accounting_period_id']]
    );
    $review = (new \eel_accounts\Service\DividendReserveClassificationService())
        ->fetchReviewContext(
            (int)$fixture['company_id'],
            (int)$fixture['accounting_period_id'],
            (string)($period['period_end'] ?? '')
        );
    $treatments = [];
    foreach ((array)($review['rows'] ?? []) as $row) {
        $nominalId = (int)($row['nominal_account_id'] ?? 0);
        if ($nominalId > 0) {
            $treatments[(string)$nominalId] = (string)($row['treatment'] ?? 'unknown');
        }
    }

    $result = (new \eel_accounts\Service\DividendReserveClassificationService())->saveReview(
        (int)$fixture['company_id'],
        (int)$fixture['accounting_period_id'],
        $treatments,
        'test',
        (string)($period['period_end'] ?? '')
    );
    if (empty($result['success'])) {
        throw new \RuntimeException(implode('; ', (array)($result['errors'] ?? ['Reserve review fixture could not be saved.'])));
    }
}

function retainedEarningsCloseCreateLossFixture(): array
{
    $marker = 'retained-close-' . bin2hex(random_bytes(4));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Retained Close Fixture',
            'company_number' => $marker,
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number ORDER BY id DESC LIMIT 1',
        ['company_number' => $marker]
    );
    ParticipatorLoanTestFixture::configureNominals(
        $companyId,
        StandardNominalTestFixture::id('1200'),
        StandardNominalTestFixture::id('2100')
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => '2024 retained close fixture',
            'period_start' => '2024-01-01',
            'period_end' => '2024-12-31',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'label' => '2024 retained close fixture',
        ]
    );

    retainedEarningsCloseInsertJournal($companyId, $accountingPeriodId, $marker . '-sales', '2024-03-31', [
        ['nominal_account_id' => retainedEarningsCloseNominalId('1000'), 'debit' => '1000.00', 'credit' => '0.00', 'line_description' => 'Bank receipt'],
        ['nominal_account_id' => retainedEarningsCloseNominalId('4000'), 'debit' => '0.00', 'credit' => '1000.00', 'line_description' => 'Sales'],
    ]);
    retainedEarningsCloseInsertJournal($companyId, $accountingPeriodId, $marker . '-materials', '2024-04-30', [
        ['nominal_account_id' => retainedEarningsCloseNominalId('5000'), 'debit' => '1200.00', 'credit' => '0.00', 'line_description' => 'Materials'],
        ['nominal_account_id' => retainedEarningsCloseNominalId('1000'), 'debit' => '0.00', 'credit' => '1200.00', 'line_description' => 'Bank payment'],
    ]);

    return [
        'marker' => $marker,
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'retained_earnings_nominal_id' => retainedEarningsCloseNominalId('3000'),
    ];
}

function retainedEarningsCloseCreateDepreciationFixture(): array
{
    $marker = 'retained-dep-' . bin2hex(random_bytes(4));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Retained Dep Fixture',
            'company_number' => $marker,
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number ORDER BY id DESC LIMIT 1',
        ['company_number' => $marker]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => '2025 retained dep fixture',
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'label' => '2025 retained dep fixture',
        ]
    );

    retainedEarningsCloseInsertJournal($companyId, $accountingPeriodId, $marker . '-sales', '2025-03-31', [
        ['nominal_account_id' => retainedEarningsCloseNominalId('1000'), 'debit' => '1000.00', 'credit' => '0.00', 'line_description' => 'Bank receipt'],
        ['nominal_account_id' => retainedEarningsCloseNominalId('4000'), 'debit' => '0.00', 'credit' => '1000.00', 'line_description' => 'Sales'],
    ]);

    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            company_id,
            asset_code,
            description,
            category,
            nominal_account_id,
            accum_dep_nominal_id,
            purchase_date,
            available_for_use_date,
            available_for_use_evidence,
            cost,
            useful_life_years,
            depreciation_method,
            residual_value,
            status
         ) VALUES (
            :company_id,
            :asset_code,
            :description,
            :category,
            :nominal_account_id,
            :accum_dep_nominal_id,
            :purchase_date,
            :available_for_use_date,
            :available_for_use_evidence,
            :cost,
            :useful_life_years,
            :depreciation_method,
            :residual_value,
            :status
         )',
        [
            'company_id' => $companyId,
            'asset_code' => 'RDEP-' . substr($marker, -8),
            'description' => 'Retained earnings pending depreciation fixture asset',
            'category' => 'tools_equipment',
            'nominal_account_id' => retainedEarningsCloseNominalId('1300'),
            'accum_dep_nominal_id' => retainedEarningsCloseNominalId('1330'),
            'purchase_date' => '2025-01-01',
            'available_for_use_date' => '2025-01-01',
            'available_for_use_evidence' => 'Test fixture commissioning record dated 1 January 2025.',
            'cost' => 1200.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );

    return [
        'marker' => $marker,
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ];
}

function retainedEarningsCloseInsertJournal(int $companyId, int $accountingPeriodId, string $sourceRef, string $date, array $lines): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $date,
            'description' => 'Retained close fixture',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => $sourceRef,
        ]
    );

    foreach ($lines as $line) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
             VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => (int)$line['nominal_account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'line_description' => $line['line_description'],
            ]
        );
    }
}

function retainedEarningsCloseLineForNominal(array $journal, int $nominalAccountId): array
{
    foreach ((array)($journal['lines'] ?? []) as $line) {
        if ((int)($line['nominal_account_id'] ?? 0) === $nominalAccountId) {
            return (array)$line;
        }
    }

    throw new RuntimeException('Unable to find journal line for nominal ' . $nominalAccountId);
}

function retainedEarningsCloseFixtureSourceJournalCount(int $companyId, string $marker): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM journals
         WHERE company_id = :company_id
           AND source_ref LIKE :source_ref',
        [
            'company_id' => $companyId,
            'source_ref' => $marker . '-%',
        ]
    );
}

function retainedEarningsCloseNominalId(string $code): int
{
    return (int)(InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code AND is_active = 1 LIMIT 1',
        ['code' => $code]
    ) ?: 0);
}

function retainedEarningsCloseEnsureNominal(string $code, string $name, string $accountType): int
{
    $existingId = retainedEarningsCloseNominalId($code);
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

    return retainedEarningsCloseNominalId($code);
}
