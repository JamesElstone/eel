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
$harness->run(\eel_accounts\Service\VatRegistrationFactoryService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\VatRegistrationFactoryService $factory): void {
    $harness->check(\eel_accounts\Service\VatRegistrationFactoryService::class, 'creates a VAT registration service from config', function () use ($harness): void {
        $harness->assertTrue(
            \eel_accounts\Service\VatRegistrationFactoryService::createFromConfig(
                ['hmrc' => ['vat' => ['mode' => 'TEST', 'test_base_url' => 'https://example.test']]],
                'LIVE'
            ) instanceof \eel_accounts\Service\VatRegistrationService
        );
    });

    $harness->check(\eel_accounts\Service\VatRegistrationFactoryService::class, 'uses selected runtime HMRC mode for VAT validation', function () use ($harness): void {
        $service = \eel_accounts\Service\VatRegistrationFactoryService::createFromConfig([
            'runtime' => ['hmrc_mode' => 'LIVE'],
            'hmrc' => [
                'vat' => [
                    'mode' => 'TEST',
                    'test_base_url' => 'https://test.example',
                    'live_base_url' => 'https://live.example',
                ],
            ],
        ]);

        $validatorsProperty = new ReflectionProperty($service, 'validators');
        $validatorsProperty->setAccessible(true);
        $validators = $validatorsProperty->getValue($service);
        $configProperty = new ReflectionProperty($validators[0], 'config');
        $configProperty->setAccessible(true);
        $hmrcConfig = $configProperty->getValue($validators[0]);

        $harness->assertSame('LIVE', (string)($hmrcConfig['mode'] ?? ''));
    });
});
