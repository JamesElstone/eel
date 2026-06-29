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

$harness->run(HealthAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof HealthAction) {
        throw new RuntimeException('Unexpected HealthAction instance.');
    }

    $service = new \eel_accounts\Service\SetupHealthService();

    $buildSetupHealthItems = new ReflectionMethod(\eel_accounts\Service\SetupHealthService::class, 'buildSetupHealthItems');
    $buildSetupHealthItems->setAccessible(true);

    $filterSetupHealthItems = new ReflectionMethod(\eel_accounts\Service\SetupHealthService::class, 'filterSetupHealthItems');
    $filterSetupHealthItems->setAccessible(true);
    $buildAccountingPeriodStatus = new ReflectionMethod(\eel_accounts\Service\SetupHealthService::class, 'buildAccountingPeriodStatus');
    $buildAccountingPeriodStatus->setAccessible(true);
    $buildDefaultNominalStatus = new ReflectionMethod(\eel_accounts\Service\SetupHealthService::class, 'buildDefaultNominalStatus');
    $buildDefaultNominalStatus->setAccessible(true);

    $harness->check(\eel_accounts\Service\SetupHealthService::class, 'buildSetupHealthItems returns expected status rows', function () use (
        $harness,
        $service,
        $buildSetupHealthItems
    ): void {
        $items = $buildSetupHealthItems->invoke(
            $service,
            ['connected' => true, 'message' => 'Connected using ODBC.'],
            [['id' => 1]],
            [['id' => 10]],
            [['id' => 100]],
            ['company_id' => '1', 'company_name' => 'Elstone Electricals Limited'],
            true
        );

        $harness->assertCount(8, $items);
        $harness->assertSame('Database connection', $items[0]['title'] ?? '');
        $harness->assertSame(true, $items[0]['ok'] ?? false);
        $harness->assertSame('Selected company', $items[2]['title'] ?? '');
        $harness->assertSame('Loaded: Elstone Electricals Limited', $items[2]['detail'] ?? '');
        $harness->assertSame('Default nominals', $items[5]['title'] ?? '');
        $harness->assertSame('Company Settings', $items[6]['title'] ?? '');
        $harness->assertSame('Corporation tax UTR', $items[7]['title'] ?? '');
        $harness->assertSame(false, $items[7]['ok'] ?? true);
        $harness->assertSame('No Corporation Tax UTR is saved for the selected company.', $items[7]['detail'] ?? '');

        $itemsWithUtr = $buildSetupHealthItems->invoke(
            $service,
            ['connected' => true, 'message' => 'Connected using ODBC.'],
            [['id' => 1]],
            [['id' => 10]],
            [['id' => 100]],
            ['company_id' => '1', 'company_name' => 'Elstone Electricals Limited', 'utr' => 1234567890],
            true
        );

        $harness->assertSame(true, $itemsWithUtr[7]['ok'] ?? false);
        $harness->assertSame('A UTR is saved for the selected company.', $itemsWithUtr[7]['detail'] ?? '');
    });

    $harness->check(\eel_accounts\Service\SetupHealthService::class, 'filterSetupHealthItems returns only requested titles', function () use (
        $harness,
        $service,
        $buildSetupHealthItems,
        $filterSetupHealthItems
    ): void {
        $items = $buildSetupHealthItems->invoke(
            $service,
            ['connected' => true, 'message' => 'Connected using ODBC.'],
            [],
            [],
            [],
            ['company_id' => '', 'company_name' => ''],
            false
        );

        $filtered = $filterSetupHealthItems->invoke(
            $service,
            $items,
            ['Database connection', 'Company Settings', 'Corporation tax UTR']
        );

        $harness->assertCount(3, $filtered);
        $harness->assertSame('Database connection', $filtered[0]['title'] ?? '');
        $harness->assertSame('Company Settings', $filtered[1]['title'] ?? '');
        $harness->assertSame('Corporation tax UTR', $filtered[2]['title'] ?? '');
    });

    $harness->check(\eel_accounts\Service\SetupHealthService::class, 'accounting period status distinguishes missing and gapped accounting periods', function () use (
        $harness,
        $service,
        $buildAccountingPeriodStatus
    ): void {
        $empty = $buildAccountingPeriodStatus->invoke($service, 0, []);
        $harness->assertSame('bad', $empty['state'] ?? null);
        $harness->assertSame('No accounting periods defined.', $empty['detail'] ?? null);

        $gapped = $buildAccountingPeriodStatus->invoke($service, 0, [
            ['period_start' => '2024-01-01', 'period_end' => '2024-12-31'],
            ['period_start' => '2025-02-01', 'period_end' => '2026-01-31'],
        ]);
        $harness->assertSame('warn', $gapped['state'] ?? null);

        $complete = $buildAccountingPeriodStatus->invoke($service, 0, [
            ['period_start' => '2024-01-01', 'period_end' => '2024-12-31'],
            ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
        ]);
        $harness->assertSame('ok', $complete['state'] ?? null);
    });

    $harness->check(\eel_accounts\Service\SetupHealthService::class, 'default nominal status distinguishes none, partial, and complete assignments', function () use (
        $harness,
        $service,
        $buildDefaultNominalStatus
    ): void {
        $nominalAccounts = [
            ['id' => 10],
            ['id' => 15],
            ['id' => 20],
            ['id' => 30],
            ['id' => 40],
            ['id' => 50],
        ];

        $none = $buildDefaultNominalStatus->invoke($service, [], $nominalAccounts);
        $harness->assertSame('bad', $none['state'] ?? null);

        $partial = $buildDefaultNominalStatus->invoke($service, [
            'default_bank_nominal_id' => 10,
            'default_trade_nominal_id' => 15,
            'default_expense_nominal_id' => 20,
        ], $nominalAccounts);
        $harness->assertSame('warn', $partial['state'] ?? null);

        $complete = $buildDefaultNominalStatus->invoke($service, [
            'default_bank_nominal_id' => 10,
            'default_trade_nominal_id' => 15,
            'default_expense_nominal_id' => 20,
            'director_loan_nominal_id' => 30,
            'vat_nominal_id' => 40,
            'uncategorised_nominal_id' => 50,
        ], $nominalAccounts);
        $harness->assertSame('ok', $complete['state'] ?? null);
    });

    $harness->check('HealthAction', 'settings setup health card renders action button and health rows', function () use (
        $harness
    ): void {
        $card = new _settings_setup_healthCard();
        $html = $card->render([
            'installation_setup_health_items' => [
                [
                    'title' => 'Database connection',
                    'ok' => true,
                    'detail' => 'Connected using ODBC.',
                ],
            ],
            'company_setup_health_items' => [
                [
                    'title' => 'Company Settings',
                    'ok' => false,
                    'detail' => 'No saved company settings were detected for the selected company.',
                ],
                [
                    'title' => 'Tax years',
                    'state' => 'warn',
                    'detail' => 'Some accounting periods are missing.',
                ],
                [
                    'title' => 'Corporation tax UTR',
                    'ok' => false,
                    'detail' => 'No Corporation Tax UTR is saved for the selected company.',
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'card_action'));
        $harness->assertSame(true, str_contains($html, 'value="Health"'));
        $harness->assertSame(true, str_contains($html, 'Check Setup Health'));
        $harness->assertSame(true, str_contains($html, 'Database connection'));
        $harness->assertSame(true, str_contains($html, 'Company Settings'));
        $harness->assertSame(true, str_contains($html, 'Corporation tax UTR'));
        $harness->assertSame(true, str_contains($html, 'status-square warn'));
        $harness->assertSame(true, str_contains($html, 'Warning'));
        $harness->assertSame(true, str_contains($html, 'status-square bad'));
    });
});
