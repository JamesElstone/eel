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
$harness->run(DashboardRepository::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(DashboardRepository::class, 'normalises month and category filters', function () use ($harness): void {
        $repository = new DashboardRepository();

        $harness->assertSame('2026-03-01', $repository->normaliseTransactionMonthFilter('2026-03-01'));
        $harness->assertSame('', $repository->normaliseTransactionMonthFilter('2026-03-15'));
        $harness->assertSame('manual', $repository->normaliseTransactionCategoryFilter('manual'));
        $harness->assertSame('all', $repository->normaliseTransactionCategoryFilter('unexpected'));
    });

    $harness->check(DashboardRepository::class, 'maps red setup health rows into dashboard actions', function () use ($harness): void {
        $repository = new DashboardRepository();
        $method = new ReflectionMethod(DashboardRepository::class, 'setupHealthContextToActionItems');
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
                    'detail' => 'Some tax periods are missing.',
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

    $harness->check(DashboardRepository::class, 'keeps company requirement visible with setup health actions', function () use ($harness): void {
        $repository = new DashboardRepository();
        $method = new ReflectionMethod(DashboardRepository::class, 'finaliseActivity');
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

    $harness->check(DashboardRepository::class, 'adds onboarding actions for missing bank accounts and uploads', function () use ($harness): void {
        $repository = new DashboardRepository();
        $method = new ReflectionMethod(DashboardRepository::class, 'appendCompanySetupActions');
        $method->setAccessible(true);
        $activity = [];

        $method->invokeArgs($repository, [&$activity, 0, 0]);

        $harness->assertCount(2, $activity);
        $harness->assertSame('Create a bank account', $activity[0]['title'] ?? '');
        $harness->assertSame('No bank accounts have been created for this company.', $activity[0]['detail'] ?? '');
        $harness->assertSame('Upload bank statement files', $activity[1]['title'] ?? '');
        $harness->assertSame('No bank statement files have been uploaded for this company yet.', $activity[1]['detail'] ?? '');
    });

    $harness->check(DashboardRepository::class, 'skips onboarding actions when bank accounts and uploads exist', function () use ($harness): void {
        $repository = new DashboardRepository();
        $method = new ReflectionMethod(DashboardRepository::class, 'appendCompanySetupActions');
        $method->setAccessible(true);
        $activity = [];

        $method->invokeArgs($repository, [&$activity, 1, 1]);

        $harness->assertCount(0, $activity);
    });

    $harness->check(DashboardRepository::class, 'adds missing transaction action when selected year has no transactions', function () use ($harness): void {
        $repository = new DashboardRepository();
        $method = new ReflectionMethod(DashboardRepository::class, 'appendMissingTransactionAction');
        $method->setAccessible(true);
        $activity = [];

        $method->invokeArgs($repository, [&$activity, 0]);

        $harness->assertCount(1, $activity);
        $harness->assertSame('Import transactions for this year', $activity[0]['title'] ?? '');
        $harness->assertSame('The selected tax year is missing any transaction records.', $activity[0]['detail'] ?? '');
    });

    $harness->check(DashboardRepository::class, 'skips missing transaction action when selected year has transactions', function () use ($harness): void {
        $repository = new DashboardRepository();
        $method = new ReflectionMethod(DashboardRepository::class, 'appendMissingTransactionAction');
        $method->setAccessible(true);
        $activity = [];

        $method->invokeArgs($repository, [&$activity, 1]);

        $harness->assertCount(0, $activity);
    });

    $harness->check('_dashboard_action_queueCard', 'renders action queue from page context', function () use ($harness): void {
        $card = new _dashboard_action_queueCard();
        $html = $card->render([
            'page' => [
                'action_queue' => [
                    [
                        'title' => 'Company Health: Company',
                        'detail' => 'No companies found yet.',
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Company Health: Company'));
        $harness->assertSame(true, str_contains($html, 'No companies found yet.'));
    });

    $harness->check('_dashboard_recent_transactionsCard', 'paginates recent transaction rows', function () use ($harness): void {
        $card = new _dashboard_recent_transactionsCard();
        $transactions = [];

        for ($i = 1; $i <= 13; $i++) {
            $transactions[] = [
                'date' => '2026-04-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
                'account' => 'Current account',
                'description' => 'Transaction ' . $i,
                'category' => 'Bank fees',
                'amount' => -1 * $i,
                'status' => 'Posted',
            ];
        }

        $firstPageHtml = $card->render([
            'services' => [
                'dashboard_data' => [
                    'recent_transactions' => $transactions,
                ],
            ],
        ]);

        $secondPageHtml = $card->render([
            'page' => [
                'dashboard_recent_transactions_page' => 2,
            ],
            'services' => [
                'dashboard_data' => [
                    'recent_transactions' => $transactions,
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($firstPageHtml, 'Recent transactions 1-5 of 13'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'Transaction 5'));
        $harness->assertSame(false, str_contains($firstPageHtml, 'Transaction 6'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'name="dashboard_recent_transactions_page" value="2"'));

        $harness->assertSame(true, str_contains($secondPageHtml, 'Recent transactions 6-10 of 13'));
        $harness->assertSame(false, str_contains($secondPageHtml, 'Transaction 5'));
        $harness->assertSame(true, str_contains($secondPageHtml, 'Transaction 10'));
        $harness->assertSame(true, str_contains($secondPageHtml, 'name="dashboard_recent_transactions_page" value="1"'));
    });

    $harness->check('_activityCard', 'renders recent activity feed from card service data', function () use ($harness): void {
        $card = new _activityCard();
        $html = $card->render([
            'services' => [
                'activity_feed' => [
                    [
                        'title' => 'Transaction categorised',
                        'detail' => 'Bank charge: uncategorised to Bank fees | manual',
                        'occurred_at' => '2026-04-29 12:00:00',
                        'meta' => 'James',
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Transaction categorised'));
        $harness->assertSame(true, str_contains($html, 'Bank charge: uncategorised to Bank fees | manual'));
        $harness->assertSame(true, str_contains($html, '2026-04-29 12:00:00 | James'));
        $harness->assertSame(true, str_contains($html, 'data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, 'name="card_action" value="Activity"'));
        $harness->assertSame(true, str_contains($html, 'name="activity_window" value="1_day"'));
        $harness->assertSame(true, str_contains($html, 'name="activity_window" value="7_days"'));
        $harness->assertSame(true, str_contains($html, 'name="activity_window" value="this_month"'));
    });

    $harness->check('_activityCard', 'paginates recent activity feed rows', function () use ($harness): void {
        $card = new _activityCard();
        $activity = [];

        for ($i = 1; $i <= 13; $i++) {
            $activity[] = [
                'title' => 'Activity ' . $i,
                'detail' => 'Detail ' . $i,
                'occurred_at' => '2026-04-29 12:' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . ':00',
                'meta' => 'James',
            ];
        }

        $firstPageHtml = $card->render([
            'page' => [
                'activity_window' => 'this_month',
            ],
            'services' => [
                'activity_feed' => $activity,
            ],
        ]);

        $secondPageHtml = $card->render([
            'page' => [
                'activity_window' => 'this_month',
                'activity_page' => 2,
            ],
            'services' => [
                'activity_feed' => $activity,
            ],
        ]);

        $harness->assertSame(true, str_contains($firstPageHtml, 'Activity 1-5 of 13'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'Activity 5'));
        $harness->assertSame(false, str_contains($firstPageHtml, 'Activity 6'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'name="activity_window" value="this_month"'));
        $harness->assertSame(true, str_contains($firstPageHtml, 'name="activity_page" value="2"'));

        $harness->assertSame(true, str_contains($secondPageHtml, 'Activity 6-10 of 13'));
        $harness->assertSame(false, str_contains($secondPageHtml, 'Activity 5'));
        $harness->assertSame(true, str_contains($secondPageHtml, 'Activity 10'));
        $harness->assertSame(true, str_contains($secondPageHtml, 'name="activity_page" value="1"'));
    });
});
