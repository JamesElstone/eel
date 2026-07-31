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
    \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\GovTalkTransmissionHistoryService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
            'formats HMRC submission counters as six digits',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'submissionCounter');
                $method->setAccessible(true);

                $harness->assertSame('000003', $method->invoke($service, 3));
                $harness->assertSame('1234567', $method->invoke($service, 1234567));
            }
        );
        $harness->check(
            \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
            'formats accounting period date ranges',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'periodRange');
                $method->setAccessible(true);

                $harness->assertSame('2024-10-01 to 2025-09-30', $method->invoke(
                    $service,
                    ['period_start' => '2024-10-01', 'period_end' => '2025-09-30']
                ));
            }
        );
    }
);
