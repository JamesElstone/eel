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
    \eel_accounts\Service\CorporationTaxComputationService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CorporationTaxComputationService $service): void {
        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'keeps brought-forward losses visible when dividend capacity creates a further loss', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'dividendCapacityLossCalculation');
            $method->setAccessible(true);
            $result = $method->invoke($service, -7594.69, ['brought_forward' => 349.09]);

            $harness->assertSame('349.09', number_format((float)$result['losses_brought_forward'], 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)$result['losses_used'], 2, '.', ''));
            $harness->assertSame('7594.69', number_format((float)$result['loss_created'], 2, '.', ''));
            $harness->assertSame('7943.78', number_format((float)$result['losses_carried_forward'], 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)$result['taxable_profit'], 2, '.', ''));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'keeps expected pre-lock persistence state out of tax warnings', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'withComputationPersistenceState');
            $method->setAccessible(true);
            $result = $method->invoke($service, 0, 0, [
                'available' => true,
                'warnings' => ['A genuine pre-close tax issue.'],
                'confidence_status' => 'review_required',
                'confidence_label' => 'Review required',
            ]);

            $harness->assertSame('not_persisted', (string)($result['computation_persistence']['status'] ?? ''));
            $harness->assertSame(['A genuine pre-close tax issue.'], (array)($result['warnings'] ?? []));
            $harness->assertSame('review_required', (string)($result['confidence_status'] ?? ''));
            $harness->assertSame('Review required', (string)($result['confidence_label'] ?? ''));
        });
    }
);
