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

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'time apportions pennies by inclusive CT-period days and puts the rounding residual in the final period', static function () use ($harness, $service): void {
            $allocate = new ReflectionMethod($service, 'allocatePenceByInclusiveDays');
            $allocate->setAccessible(true);
            $result = $allocate->invoke($service, 57000, [1 => 365, 2 => 26], 391);

            $harness->assertSame([1 => 53210, 2 => 3790], $result);
            $harness->assertSame(57000, array_sum($result));

            $negative = $allocate->invoke($service, -101, [1 => 200, 2 => 191], 391);
            $harness->assertSame(-101, array_sum($negative));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'counts leap-day boundaries inclusively for CT allocation', static function () use ($harness, $service): void {
            $inclusiveDays = new ReflectionMethod($service, 'inclusiveDays');
            $inclusiveDays->setAccessible(true);

            $harness->assertSame(366, $inclusiveDays->invoke($service, '2023-03-01', '2024-02-29'));
            $harness->assertSame(1, $inclusiveDays->invoke($service, '2024-02-29', '2024-02-29'));
        });
    }
);
