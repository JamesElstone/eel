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
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\DividendReserveClassificationService $service
    ): void {
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

        $harness->check(
            \eel_accounts\Service\DividendReserveClassificationService::class,
            'merges pending prepayment expenses and reversals into reserve rows',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'includePendingPrepaymentRows');
                $method->setAccessible(true);

                $rows = $method->invoke($service, [[
                    'nominal_account_id' => 10,
                    'nominal_code' => '6000',
                    'nominal_name' => 'Insurance',
                    'account_type' => 'expense',
                    'subtype_code' => 'overhead',
                    'total_debit' => 100.00,
                    'total_credit' => 10.00,
                    'profit_effect' => -90.00,
                ]], [
                    ['nominal_account_id' => 10, 'amount' => 20.00],
                    ['nominal_account_id' => 10, 'amount' => -50.00],
                    [
                        'nominal_account_id' => 11,
                        'code' => '6010',
                        'name' => 'Premises',
                        'account_type' => 'expense',
                        'subtype_code' => 'overhead',
                        'amount' => -25.00,
                    ],
                ]);

                $harness->assertSame('120.00', number_format((float)($rows[0]['total_debit'] ?? 0), 2, '.', ''));
                $harness->assertSame('60.00', number_format((float)($rows[0]['total_credit'] ?? 0), 2, '.', ''));
                $harness->assertSame('-60.00', number_format((float)($rows[0]['profit_effect'] ?? 0), 2, '.', ''));
                $harness->assertSame('-30.00', number_format((float)($rows[0]['pending_prepayment_adjustment'] ?? 0), 2, '.', ''));
                $harness->assertSame('0.00', number_format((float)($rows[1]['total_debit'] ?? 0), 2, '.', ''));
                $harness->assertSame('25.00', number_format((float)($rows[1]['total_credit'] ?? 0), 2, '.', ''));
                $harness->assertSame('25.00', number_format((float)($rows[1]['profit_effect'] ?? 0), 2, '.', ''));
            }
        );

        $harness->check(
            \eel_accounts\Service\DividendReserveClassificationService::class,
            'changes the reserve source hash when a pending prepayment changes profit',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'sourceHash');
                $method->setAccessible(true);
                $summary = ['brought_forward_distributable_reserves' => 0.0];
                $row = [
                    'nominal_account_id' => 10,
                    'profit_effect' => -100.00,
                    'treatment' => \eel_accounts\Service\DividendReserveClassificationService::TREATMENT_REALISED_LOSS,
                ];

                $before = $method->invoke($service, 49, 80, '2024-09-30', $summary, [$row]);
                $row['profit_effect'] = -75.00;
                $after = $method->invoke($service, 49, 80, '2024-09-30', $summary, [$row]);

                $harness->assertTrue(is_string($before) && strlen($before) === 64);
                $harness->assertTrue(is_string($after) && strlen($after) === 64);
                $harness->assertTrue($before !== $after);
            }
        );
    }
);
