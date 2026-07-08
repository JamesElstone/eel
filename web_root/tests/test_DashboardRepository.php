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
$harness->run(\eel_accounts\Repository\DashboardRepository::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'normalises month and category filters', function () use ($harness): void {
        $repository = new \eel_accounts\Repository\DashboardRepository();

        $harness->assertSame('2026-03-01', $repository->normaliseTransactionMonthFilter('2026-03-01'));
        $harness->assertSame('', $repository->normaliseTransactionMonthFilter('2026-03-15'));
        $harness->assertSame('manual', $repository->normaliseTransactionCategoryFilter('manual'));
        $harness->assertSame('not_posted', $repository->normaliseTransactionCategoryFilter('not_posted'));
        $harness->assertSame('auto_assigned', $repository->normaliseTransactionCategoryFilter('auto_assigned'));
        $harness->assertSame('auto_unreviewed', $repository->normaliseTransactionCategoryFilter('auto_unreviewed'));
        $harness->assertSame('auto_unposted', $repository->normaliseTransactionCategoryFilter('auto_unposted'));
        $harness->assertSame('auto_confirmed', $repository->normaliseTransactionCategoryFilter('auto_confirmed'));
        $harness->assertSame('all', $repository->normaliseTransactionCategoryFilter('auto'));
        $harness->assertSame('all', $repository->normaliseTransactionCategoryFilter('unexpected'));
        $harness->assertSame('pending', $repository->normaliseAutoApprovalFilter('pending'));
        $harness->assertSame('pending', $repository->normaliseAutoApprovalFilter('unconfirmed'));
        $harness->assertSame('confirmed', $repository->normaliseAutoApprovalFilter('Correct'));
    });

    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'normalises transaction amount filters', function () use ($harness): void {
        $repository = new \eel_accounts\Repository\DashboardRepository();

        $harness->assertSame('1000.00', $repository->normaliseTransactionAmountFilter('1000'));
        $harness->assertSame('1000.00', $repository->normaliseTransactionAmountFilter("\xC2\xA31000.00"));
        $harness->assertSame('-1000.00', $repository->normaliseTransactionAmountFilter("-\xC2\xA31000"));
        $harness->assertSame('100.00', $repository->normaliseTransactionAmountFilter('100', 'in'));
        $harness->assertSame('-100.00', $repository->normaliseTransactionAmountFilter('100', 'out'));
        $harness->assertSame('100.00', $repository->normaliseTransactionAmountFilter('-100', 'in'));
        $harness->assertSame('-100.00', $repository->normaliseTransactionAmountFilter('-100', 'out'));
        $harness->assertSame('', $repository->normaliseTransactionAmountFilter(''));
        $harness->assertSame('', $repository->normaliseTransactionAmountFilter('1000.999'));
    });

    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'normalises transaction flow filters', function () use ($harness): void {
        $repository = new \eel_accounts\Repository\DashboardRepository();

        $harness->assertSame('any', $repository->normaliseTransactionFlowFilter(''));
        $harness->assertSame('any', $repository->normaliseTransactionFlowFilter('unexpected'));
        $harness->assertSame('in', $repository->normaliseTransactionFlowFilter('in'));
        $harness->assertSame('out', $repository->normaliseTransactionFlowFilter('out'));
        $harness->assertSame('in', $repository->normaliseTransactionFlowFilter(' IN '));
    });

    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'fetches imported transaction notes for month rows', function () use ($harness): void {
        if (!InterfaceDB::tableExists('companies') || !InterfaceDB::tableExists('accounting_periods') || !InterfaceDB::tableExists('company_accounts') || !InterfaceDB::tableExists('statement_uploads') || !InterfaceDB::tableExists('transactions')) {
            $harness->skip('Transaction fixture tables are not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();
        try {
            $repository = new \eel_accounts\Repository\DashboardRepository();
            $marker = 'DASHNOTE' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8));

            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active)
                 VALUES (:company_name, :company_number, 1)',
                [
                    'company_name' => 'Dashboard Notes ' . $marker,
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
                    'label' => 'FY ' . $marker,
                    'period_start' => '2022-01-01',
                    'period_end' => '2022-12-31',
                ]
            );
            $accountingPeriodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
                [
                    'company_id' => $companyId,
                    'label' => 'FY ' . $marker,
                ]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO company_accounts (company_id, account_name, account_type, is_active)
                 VALUES (:company_id, :account_name, :account_type, 1)',
                [
                    'company_id' => $companyId,
                    'account_name' => 'Fixture current account ' . $marker,
                    'account_type' => 'bank',
                ]
            );
            $currentAccountId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM company_accounts WHERE company_id = :company_id AND account_name = :account_name ORDER BY id DESC LIMIT 1',
                [
                    'company_id' => $companyId,
                    'account_name' => 'Fixture current account ' . $marker,
                ]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO company_accounts (company_id, account_name, account_type, is_active)
                 VALUES (:company_id, :account_name, :account_type, 1)',
                [
                    'company_id' => $companyId,
                    'account_name' => 'Fixture reserve account ' . $marker,
                    'account_type' => 'bank',
                ]
            );
            $reserveAccountId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM company_accounts WHERE company_id = :company_id AND account_name = :account_name ORDER BY id DESC LIMIT 1',
                [
                    'company_id' => $companyId,
                    'account_name' => 'Fixture reserve account ' . $marker,
                ]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO statement_uploads (
                    company_id,
                    accounting_period_id,
                    statement_month,
                    original_filename,
                    stored_filename,
                    file_sha256,
                    workflow_status
                 ) VALUES (
                    :company_id,
                    :accounting_period_id,
                    :statement_month,
                    :original_filename,
                    :stored_filename,
                    :file_sha256,
                    :workflow_status
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'statement_month' => '2022-11-01',
                    'original_filename' => $marker . '.csv',
                    'stored_filename' => $marker . '.csv',
                    'file_sha256' => hash('sha256', $marker),
                    'workflow_status' => 'committed',
                ]
            );
            $uploadId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM statement_uploads WHERE company_id = :company_id AND original_filename = :filename ORDER BY id DESC LIMIT 1',
                [
                    'company_id' => $companyId,
                    'filename' => $marker . '.csv',
                ]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO transactions (
                    company_id,
                    accounting_period_id,
                    account_id,
                    statement_upload_id,
                    txn_date,
                    description,
                    reference,
                    amount,
                    currency,
                    source_account_label,
                    dedupe_hash,
                    category_status,
                    notes
                 ) VALUES (
                    :company_id,
                    :accounting_period_id,
                    :account_id,
                    :statement_upload_id,
                    :txn_date,
                    :description,
                    :reference,
                    :amount,
                    :currency,
                    :source_account_label,
                    :dedupe_hash,
                    :category_status,
                    :notes
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'account_id' => $currentAccountId,
                    'statement_upload_id' => $uploadId,
                    'txn_date' => '2022-11-02',
                    'description' => 'Dashboard note fixture',
                    'reference' => 'DIVIDEND',
                    'amount' => '-129.00',
                    'currency' => 'GBP',
                    'source_account_label' => 'Fixture current account',
                    'dedupe_hash' => hash('sha256', 'dashboard-note-' . $marker),
                    'category_status' => 'manual',
                    'notes' => 'Retain this note after AJAX refresh.',
                ]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO transactions (
                    company_id,
                    accounting_period_id,
                    account_id,
                    statement_upload_id,
                    txn_date,
                    description,
                    reference,
                    amount,
                    currency,
                    source_account_label,
                    dedupe_hash,
                    category_status,
                    notes
                 ) VALUES (
                    :company_id,
                    :accounting_period_id,
                    :account_id,
                    :statement_upload_id,
                    :txn_date,
                    :description,
                    :reference,
                    :amount,
                    :currency,
                    :source_account_label,
                    :dedupe_hash,
                    :category_status,
                    :notes
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'account_id' => $reserveAccountId,
                    'statement_upload_id' => $uploadId,
                    'txn_date' => '2022-11-03',
                    'description' => 'Dashboard account filter fixture',
                    'reference' => 'FILTER',
                    'amount' => '-42.00',
                    'currency' => 'GBP',
                    'source_account_label' => 'Fixture reserve account',
                    'dedupe_hash' => hash('sha256', 'dashboard-filter-' . $marker),
                    'category_status' => 'manual',
                    'notes' => 'Other account row.',
                ]
            );

            $rows = $repository->fetchTransactionsForMonth($companyId, $accountingPeriodId, '2022-11-01', 'all');
            $currentAccountRows = $repository->fetchTransactionsForMonth($companyId, $accountingPeriodId, '2022-11-01', 'all', 500, $currentAccountId);
            $reserveAccountRows = $repository->fetchTransactionsForMonth($companyId, $accountingPeriodId, '2022-11-01', 'all', 500, $reserveAccountId);
            $missingAccountRows = $repository->fetchTransactionsForMonth($companyId, $accountingPeriodId, '2022-11-01', 'all', 500, $reserveAccountId + $currentAccountId + 999999);

            $harness->assertSame(2, count($rows));
            $harness->assertSame(1, count($currentAccountRows));
            $harness->assertSame('Retain this note after AJAX refresh.', (string)($currentAccountRows[0]['notes'] ?? ''));
            $harness->assertSame(1, count($reserveAccountRows));
            $harness->assertSame('Other account row.', (string)($reserveAccountRows[0]['notes'] ?? ''));
            $harness->assertSame(0, count($missingAccountRows));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'maps red setup health rows into dashboard actions', function () use ($harness): void {
        $repository = new \eel_accounts\Repository\DashboardRepository();
        $method = new ReflectionMethod(\eel_accounts\Repository\DashboardRepository::class, 'setupHealthContextToActionItems');
        $method->setAccessible(true);

        $actions = $method->invoke($repository, [
            'installation_setup_health_items' => [
                [
                    'title' => 'Database connection',
                    'ok' => true,
                    'detail' => 'Connected.',
                ],
            ],
            'company_setup_health_items' => [
                [
                    'title' => 'Company',
                    'ok' => false,
                    'detail' => 'No companies found yet.',
                ],
                [
                    'title' => 'Tax years',
                    'state' => 'warn',
                    'detail' => 'Some accounting periods are missing.',
                ],
                [
                    'title' => 'Nominal accounts',
                    'state' => 'bad',
                    'detail' => 'No nominal accounts are available yet.',
                ],
            ],
        ]);

        $harness->assertCount(2, $actions);
        $harness->assertSame('Company Health: Company', $actions[0]['title'] ?? '');
        $harness->assertSame('No companies found yet.', $actions[0]['detail'] ?? '');
        $harness->assertSame('Company Health: Nominal accounts', $actions[1]['title'] ?? '');
    });

    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'keeps company requirement visible with setup health actions', function () use ($harness): void {
        $repository = new \eel_accounts\Repository\DashboardRepository();
        $method = new ReflectionMethod(\eel_accounts\Repository\DashboardRepository::class, 'finaliseActivity');
        $method->setAccessible(true);

        $activity = $method->invoke($repository, [
            [
                'title' => 'Company required',
                'detail' => 'A company must exist before dashboard activity can be calculated.',
            ],
        ], [
            [
                'title' => 'Company Health: Company',
                'detail' => 'No companies found yet.',
            ],
        ]);

        $harness->assertCount(2, $activity);
        $harness->assertSame('Company required', $activity[0]['title'] ?? '');
        $harness->assertSame('Company Health: Company', $activity[1]['title'] ?? '');
    });

    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'adds onboarding actions for missing bank accounts and uploads', function () use ($harness): void {
        $repository = new \eel_accounts\Repository\DashboardRepository();
        $method = new ReflectionMethod(\eel_accounts\Repository\DashboardRepository::class, 'appendCompanySetupActions');
        $method->setAccessible(true);
        $activity = [];

        $method->invokeArgs($repository, [&$activity, 0, 0]);

        $harness->assertCount(2, $activity);
        $harness->assertSame('Create a bank account', $activity[0]['title'] ?? '');
        $harness->assertSame('No bank accounts have been created for this company.', $activity[0]['detail'] ?? '');
        $harness->assertSame('Upload bank statement files', $activity[1]['title'] ?? '');
        $harness->assertSame('No bank statement files have been uploaded for this company yet.', $activity[1]['detail'] ?? '');
    });

    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'skips onboarding actions when bank accounts and uploads exist', function () use ($harness): void {
        $repository = new \eel_accounts\Repository\DashboardRepository();
        $method = new ReflectionMethod(\eel_accounts\Repository\DashboardRepository::class, 'appendCompanySetupActions');
        $method->setAccessible(true);
        $activity = [];

        $method->invokeArgs($repository, [&$activity, 1, 1]);

        $harness->assertCount(0, $activity);
    });

    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'adds missing transaction action when selected year has no transactions', function () use ($harness): void {
        $repository = new \eel_accounts\Repository\DashboardRepository();
        $method = new ReflectionMethod(\eel_accounts\Repository\DashboardRepository::class, 'appendMissingTransactionAction');
        $method->setAccessible(true);
        $activity = [];

        $method->invokeArgs($repository, [&$activity, 0]);

        $harness->assertCount(1, $activity);
        $harness->assertSame('Import transactions for this year', $activity[0]['title'] ?? '');
        $harness->assertSame('The selected accounting period is missing any transaction records.', $activity[0]['detail'] ?? '');
    });

    $harness->check(\eel_accounts\Repository\DashboardRepository::class, 'skips missing transaction action when selected year has transactions', function () use ($harness): void {
        $repository = new \eel_accounts\Repository\DashboardRepository();
        $method = new ReflectionMethod(\eel_accounts\Repository\DashboardRepository::class, 'appendMissingTransactionAction');
        $method->setAccessible(true);
        $activity = [];

        $method->invokeArgs($repository, [&$activity, 1]);

        $harness->assertCount(0, $activity);
    });

    $harness->check('_dashboard_action_queueCard', 'renders action queue from page context', function () use ($harness): void {
        $card = new _dashboard_action_queueCard();
        $services = $card->services();

        $harness->assertSame('dashboard_action_queue', (string)($services[0]['key'] ?? ''));
        $harness->assertSame('fetchDashboardActionQueue', (string)($services[0]['method'] ?? ''));

        $html = $card->render([
            'page' => [
                'action_queue' => [
                    [
                        'title' => 'Company Health: Company',
                        'detail' => 'No companies found yet.',
                    ],
                    [
                        'title' => 'Categorise uncategorised transactions',
                        'detail' => '3 transactions still need to be categorised against a nominal account.',
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Company Health: Company'));
        $harness->assertSame(true, str_contains($html, 'No companies found yet.'));
        $harness->assertSame(true, str_contains($html, 'status-square bad'));
        $harness->assertSame(true, str_contains($html, 'Categorise uncategorised transactions'));
        $harness->assertSame(true, str_contains($html, 'status-square warn'));
    });

    $harness->check('_dashboard_action_queueCard', 'describes the selected accounting period in helper text', function () use ($harness): void {
        $card = new _dashboard_action_queueCard();

        $helper = $card->helper([
            'accounting_period' => [
                'label' => '01/10/2025 to 30/09/2026',
            ],
        ]);

        $harness->assertSame(
            'This is a to-do list for the tax year 01/10/2025 to 30/09/2026. Check back here to see what to do next.',
            $helper
        );
    });

    $harness->check('_dashboard_action_queueCard', 'renders empty action queue with ok indicator', function () use ($harness): void {
        $card = new _dashboard_action_queueCard();
        $html = $card->render([
            'page' => [
                'action_queue' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'No queued actions'));
        $harness->assertSame(true, str_contains($html, 'status-square ok'));
    });

    $harness->check('_dashboard_recent_transactionsCard', 'paginates recent transaction rows', function () use ($harness): void {
        $card = new _dashboard_recent_transactionsCard();
        $services = $card->services();
        $transactions = [];

        $harness->assertSame('recent_transactions', (string)($services[0]['key'] ?? ''));
        $harness->assertSame('fetchRecentTransactions', (string)($services[0]['method'] ?? ''));

        for ($i = 1; $i <= 13; $i++) {
            $transactions[] = [
                'date' => '2026-04-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
                'account' => 'Current account',
                'description' => 'Transaction ' . $i,
                'category' => 'Bank fees',
                'amount' => -1 * $i,
                'status' => $i === 1 ? 'Needs review' : 'Posted',
            ];
        }

        $firstPageHtml = $card->render([
            'services' => [
                'recent_transactions' => $transactions,
            ],
        ]);

        $secondPageHtml = $card->render([
            'page' => [
                'dashboard_recent_transactions_page' => 2,
            ],
            'services' => [
                'recent_transactions' => $transactions,
            ],
        ]);

        $harness->assertSame(true, str_contains($firstPageHtml, 'Recent transactions 1-5 of 13'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'class="card-toolbar"'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'name="_table_export_prepare" value="csv"'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'Transaction 5'));
        $harness->assertSame(false, str_contains($firstPageHtml, 'Transaction 6'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'name="dashboard_recent_transactions_page" value="2"'));
        $harness->assertSame(true, str_contains($firstPageHtml, '<span class="badge warning">Needs review</span>'));

        $harness->assertSame(true, str_contains($secondPageHtml, 'Recent transactions 6-10 of 13'));
        $harness->assertSame(false, str_contains($secondPageHtml, 'Transaction 5'));
        $harness->assertSame(true, str_contains($secondPageHtml, 'Transaction 10'));
        $harness->assertSame(true, str_contains($secondPageHtml, 'name="dashboard_recent_transactions_page" value="1"'));
    });

    $harness->check('_activityCard', 'renders recent activity feed from card service data', function () use ($harness): void {
        $card = new _activityCard();
        $html = $card->render([
            'page' => [
                'page_id' => 'dashboard',
                'page_cards' => ['activity'],
            ],
            'services' => [
                'activity_rows' => [
                    [
                        'occurred_at' => '2026-04-29 12:00:00',
                        'user_display_name' => 'James',
                        'page_id' => 'dashboard',
                        'action_name' => 'Transactions',
                        'card_action_name' => 'Categorise',
                        'message_type' => 'success',
                        'message_text' => 'Transaction categorised',
                        'message_html_text' => 'Bank charge: uncategorised to Bank fees | manual',
                        'ip_address' => '127.0.0.1',
                        'request_method' => 'POST',
                        'request_uri' => '/index.php',
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Transaction categorised'));
        $harness->assertSame(true, str_contains($html, 'Bank charge: uncategorised to Bank fees | manual'));
        $harness->assertSame(true, str_contains($html, '2026-04-29 12:00:00'));
        $harness->assertSame(true, str_contains($html, 'James'));
        $harness->assertSame(true, str_contains($html, 'data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertSame(true, str_contains($html, 'name="table_key" value="activity"'));
    });

    $harness->check('_overviewCard', 'renders bank and trade account dashboard stats', function () use ($harness): void {
        $card = new _overviewCard();
        $services = $card->services();

        $harness->assertSame('dashboard_stats', (string)($services[0]['key'] ?? ''));
        $harness->assertSame('fetchDashboardStats', (string)($services[0]['method'] ?? ''));

        $html = $card->render([
            'services' => [
                'dashboard_stats' => [
                    'bank_accounts' => 2,
                    'trade_accounts' => 3,
                    'unreconciled_items' => 4,
                    'draft_journals' => 5,
                    'staged_upload_rows' => 6,
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Bank accounts'));
        $harness->assertSame(true, str_contains($html, 'Trade accounts'));
        $harness->assertSame(true, str_contains($html, 'Active trade accounts used for supplier, customer, or ledger activity.'));
    });

    $harness->check('_activityCard', 'paginates recent activity feed rows', function () use ($harness): void {
        $card = new _activityCard();
        $activity = [];

        for ($i = 1; $i <= 13; $i++) {
            $activity[] = [
                'occurred_at' => '2026-04-29 12:' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . ':00',
                'user_display_name' => 'James',
                'page_id' => 'dashboard',
                'action_name' => 'Activity ' . $i,
                'message_type' => 'success',
                'message_text' => 'Detail ' . $i,
                'ip_address' => '127.0.0.1',
                'request_method' => 'POST',
                'request_uri' => '/index.php',
            ];
        }

        $firstPageHtml = $card->render([
            'page' => [
                'page_id' => 'dashboard',
                'page_cards' => ['activity'],
            ],
            'services' => [
                'activity_rows' => $activity,
            ],
        ]);

        $secondPageHtml = $card->render([
            'page' => [
                'page_id' => 'dashboard',
                'page_cards' => ['activity'],
                'activity_page' => 2,
            ],
            'services' => [
                'activity_rows' => $activity,
            ],
        ]);

        $harness->assertSame(true, str_contains($firstPageHtml, 'Activity 1-5 of 13'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'Activity 5'));
        $harness->assertSame(false, str_contains($firstPageHtml, 'Activity 6'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'name="activity_page" value="2"'));

        $harness->assertSame(true, str_contains($secondPageHtml, 'Activity 6-10 of 13'));
        $harness->assertSame(false, str_contains($secondPageHtml, 'Activity 5'));
        $harness->assertSame(true, str_contains($secondPageHtml, 'Activity 10'));
        $harness->assertSame(true, str_contains($secondPageHtml, 'name="activity_page" value="1"'));
    });
});
