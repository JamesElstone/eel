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
$harness->run(_director_loan_stateCard::class, static function (GeneratedServiceClassTestHarness $harness, _director_loan_stateCard $card): void {
    $harness->check(_director_loan_stateCard::class, 'uses selected company context for statement service', static function () use ($harness, $card): void {
        $services = $card->services();
        $statementService = (array)($services[0] ?? []);
        $params = (array)($statementService['params'] ?? []);

        $harness->assertSame(\eel_accounts\Service\DirectorLoanService::class, $statementService['service'] ?? null);
        $harness->assertSame('fetchStatement', $statementService['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
    });

    $harness->check(_director_loan_stateCard::class, 'renders statement without duplicate accounting period field', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 47,
                'name' => 'Elstone Electricals Limited',
                'accounting_period_id' => 74,
            ],
            'services' => [
                'directorLoanStatement' => [
                    'success' => true,
                    'accounting_period' => [
                        'id' => 74,
                        'label' => '01/10/2025 to 30/09/2026',
                    ],
                    'director_loan_nominal' => [
                        'code' => '2100',
                        'name' => 'Director Loan',
                    ],
                    'opening_balance' => 100,
                    'movement_in_period' => -25.5,
                    'closing_balance' => 74.5,
                    'balance_direction_label' => 'Company owes director',
                    'default_currency_symbol' => '£',
                    'has_movements_in_period' => true,
                    'statement_rows' => [
                        [
                            'row_type' => 'opening_balance',
                            'journal_date' => '2025-10-01',
                            'description' => 'Balance brought forward',
                            'source_type' => null,
                            'signed_amount' => null,
                            'running_balance' => 100,
                        ],
                        [
                            'row_type' => 'movement',
                            'journal_date' => '2025-10-15',
                            'description' => 'Director repayment',
                            'source_type' => 'manual',
                            'signed_amount' => -25.5,
                            'running_balance' => 74.5,
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Elstone Electricals Limited'));
        $harness->assertTrue(!str_contains($html, '<label>Accounting Period</label>'));
        $harness->assertTrue(!str_contains($html, '01/10/2025 to 30/09/2026'));
        $harness->assertTrue(!str_contains($html, '<select'));
        $harness->assertTrue(str_contains($html, '£100.00'));
        $harness->assertTrue(str_contains($html, '£74.50'));
        $harness->assertTrue(str_contains($html, 'Director repayment'));
    });
});
