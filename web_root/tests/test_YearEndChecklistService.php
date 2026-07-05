<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\YearEndChecklistService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'locking a period posts asset depreciation first', static function () use ($harness): void {
        yearEndChecklistServiceRequireDepreciationLockSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = yearEndChecklistServiceCreateDepreciationLockFixture();
            $result = (new \eel_accounts\Service\YearEndChecklistService())->lockPeriod(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test'
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(1, (int)(($result['depreciation'] ?? [])['created'] ?? 0));
            $harness->assertSame(1, InterfaceDB::countWhere('asset_depreciation_entries', [
                'asset_id' => (int)$fixture['asset_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
            ]));
            $harness->assertSame(1, InterfaceDB::countWhere('year_end_reviews', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'is_locked' => 1,
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'director loan offset before lock is gated by acknowledgement', static function () use ($harness): void {
        yearEndChecklistServiceRequireDirectorLoanOffsetLockSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = yearEndChecklistServiceCreateDirectorLoanOffsetFixture();
            $service = new \eel_accounts\Service\YearEndChecklistService();
            $method = new ReflectionMethod($service, 'applyDirectorLoanOffsetBeforeLock');
            $method->setAccessible(true);

            $blocked = $method->invoke(
                $service,
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                ['review' => []],
                'test'
            );

            $harness->assertSame(false, (bool)($blocked['success'] ?? true));
            $harness->assertSame(true, str_contains((string)(($blocked['errors'] ?? [])[0] ?? ''), 'acknowledgement'));
            $harness->assertSame(0, InterfaceDB::countWhere('journal_entry_metadata', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'journal_key' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
            ]));

            $posted = $method->invoke(
                $service,
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                ['review' => ['director_loan_closing_acknowledged_at' => '2026-07-03 12:00:00']],
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

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'review acknowledgement clears advisory warning checks only', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $method = new ReflectionMethod($service, 'applyReviewAcknowledgement');
        $method->setAccessible(true);

        $warning = [
            'check_code' => 'filing_basis_reminder',
            'status' => 'warning',
            'metric_value' => '',
            'detail_text' => 'App numbers remain working figures.',
        ];
        $acknowledged = $method->invoke($service, $warning, [
            'filing_basis_reminder' => [
                'acknowledged_at' => '2026-07-03 12:00:00',
                'acknowledged_by' => 'test',
                'note' => null,
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

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'expense position metric labels unpaid and owed balances', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $method = new ReflectionMethod($service, 'expensePositionMetric');
        $method->setAccessible(true);
        $settings = ['default_currency_symbol' => '&#163;'];

        $harness->assertSame('UNPAID £ 125.00', $method->invoke($service, $settings, 125.0));
        $harness->assertSame('OWED -£ 42.50', $method->invoke($service, $settings, -42.5));
        $harness->assertSame('£ 0.00', $method->invoke($service, $settings, 0.0));
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
        yearEndChecklistServiceRequirePostedSourceWorkSchema($harness);
        if (yearEndChecklistServiceNominalId('6070') <= 0) {
            $harness->skip('Nominal 6070 is not available.');
        }

        InterfaceDB::beginTransaction();
        try {
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
            $warningChecklist = $service->fetchChecklist((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], false);
            $warningCheck = yearEndChecklistServiceFindCheck((array)$warningChecklist, 'fixed_asset_review_placeholder');

            $harness->assertSame('warning', (string)($warningCheck['status'] ?? ''));
            $harness->assertSame('1', (string)($warningCheck['metric_value'] ?? ''));
            $harness->assertSame('?page=assets&show_card=not_an_asset', (string)($warningCheck['action_url'] ?? ''));

            InterfaceDB::prepareExecute(
                'UPDATE transactions
                 SET amount = :amount
                 WHERE id = :id',
                [
                    'amount' => '-500.00',
                    'id' => (int)$fixture['transaction_id'],
                ]
            );
            $passChecklist = $service->fetchChecklist((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], false);
            $passCheck = yearEndChecklistServiceFindCheck((array)$passChecklist, 'fixed_asset_review_placeholder');

            $harness->assertSame('pass', (string)($passCheck['status'] ?? ''));
            $harness->assertSame('0', (string)($passCheck['metric_value'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'posted source work summary tracks unposted transactions expenses and assets', static function () use ($harness): void {
        yearEndChecklistServiceRequirePostedSourceWorkSchema($harness);

        InterfaceDB::beginTransaction();
        try {
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
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'nominal_accounts', 'asset_register', 'asset_depreciation_entries', 'year_end_reviews', 'year_end_check_results', 'year_end_audit_log', 'capital_allowance_pool_runs', 'capital_allowance_asset_calculations', 'tax_loss_carryforwards'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['1000', '1300', '1330', '4000', '6200'] as $code) {
        if (yearEndChecklistServiceNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }
}

function yearEndChecklistServiceRequireDirectorLoanOffsetLockSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'nominal_accounts', 'journal_entry_metadata'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['1200', '2100'] as $code) {
        if (yearEndChecklistServiceNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
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
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalId,
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
