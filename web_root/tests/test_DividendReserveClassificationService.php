<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\DividendReserveClassificationService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\DividendReserveClassificationService $service): void {
        $harness->check(\eel_accounts\Service\DividendReserveClassificationService::class, 'exposes expected reserve treatments', static function () use ($harness, $service): void {
            $treatments = $service->treatments();

            $harness->assertTrue(in_array(\eel_accounts\Service\DividendReserveClassificationService::TREATMENT_REALISED_PROFIT, $treatments, true));
            $harness->assertTrue(in_array(\eel_accounts\Service\DividendReserveClassificationService::TREATMENT_UNREALISED_GAIN, $treatments, true));
            $harness->assertTrue(in_array(\eel_accounts\Service\DividendReserveClassificationService::TREATMENT_DIVIDEND_DISTRIBUTION, $treatments, true));
        });

        $harness->check(\eel_accounts\Service\DividendReserveClassificationService::class, 'requires company and period context', static function () use ($harness, $service): void {
            $context = $service->fetchReviewContext(0, 0);

            $harness->assertSame(false, (bool)($context['available'] ?? true));
            $harness->assertTrue(str_contains((string)(($context['errors'] ?? [])[0] ?? ''), 'Select a company'));
        });
    }
);
