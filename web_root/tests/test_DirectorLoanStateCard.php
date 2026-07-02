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

    $harness->check(_director_loan_stateCard::class, 'declares director loan transaction helper text', static function () use ($harness, $card): void {
        $helper = $card->helper([]);

        $harness->assertSame('Shown below is the Director Loan position. Director Loan entries are categorised on the Transactions page using the row-level Director Loan button.', $helper);
    });

    $harness->check(_director_loan_stateCard::class, 'renders statement without duplicate context fields', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 47,
                'name' => 'Example Company Limited',
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
                        'name' => 'Director Loan Liability',
                    ],
                    'asset_nominal' => [
                        'code' => '1200',
                        'name' => 'Director Loan Asset',
                    ],
                    'liability_nominal' => [
                        'code' => '2100',
                        'name' => 'Director Loan Liability',
                    ],
                    'asset_receivable' => 250,
                    'liability_payable' => 324.5,
                    'net_position' => 74.5,
                    'net_position_label' => 'Company owes director',
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
                            'account_label' => 'Combined',
                        ],
                        [
                            'row_type' => 'movement',
                            'journal_date' => '2025-10-15',
                            'description' => 'Director repayment',
                            'account_label' => '1200 - Director Loan Asset',
                            'source_type' => 'manual',
                            'signed_amount' => -25.5,
                            'running_balance' => 74.5,
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(!str_contains($html, 'Example Company Limited'));
        $harness->assertTrue(!str_contains($html, '<label>Company</label>'));
        $harness->assertTrue(!str_contains($html, '<label>Accounting Period</label>'));
        $harness->assertTrue(!str_contains($html, '<input class="input"'));
        $harness->assertTrue(!str_contains($html, '01/10/2025 to 30/09/2026'));
        $harness->assertTrue(!str_contains($html, '<select'));
        $harness->assertTrue(str_contains($html, '£100.00'));
        $harness->assertTrue(str_contains($html, '£74.50'));
        $harness->assertTrue(str_contains($html, 'Director repayment'));
        $harness->assertTrue(str_contains($html, 'Director Loan Asset balance'));
        $harness->assertTrue(str_contains($html, 'Director Loan Liability balance'));
        $harness->assertTrue(str_contains($html, 'Net director loan position'));
        $harness->assertTrue(str_contains($html, 'class="summary-card"'));
        $harness->assertTrue(str_contains($html, 'class="summary-label"'));
        $harness->assertTrue(!str_contains($html, 'class="stat-card"'));
        $harness->assertTrue(str_contains($html, 'director-loan-control-helper'));
        $harness->assertTrue(str_contains($html, '<th>Account</th>'));
        $harness->assertTrue(str_contains($html, '1200 - Director Loan Asset'));
        $harness->assertTrue(str_contains($html, '2100 Director Loan Liability'));
        $harness->assertTrue(str_contains($html, 'Company owes director'));
    });

    $harness->check(_director_loan_stateCard::class, 'renders settled status from combined net position', static function () use ($harness, $card): void {
        $html = $card->render([
            'services' => [
                'directorLoanStatement' => [
                    'success' => true,
                    'accounting_period' => [],
                    'asset_nominal' => ['code' => '1200', 'name' => 'Director Loan Asset'],
                    'liability_nominal' => ['code' => '2100', 'name' => 'Director Loan Liability'],
                    'asset_receivable' => 100,
                    'liability_payable' => 100,
                    'net_position' => 0,
                    'net_position_label' => 'Settled',
                    'default_currency_symbol' => '£',
                    'has_movements_in_period' => false,
                    'statement_rows' => [],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Settled'));
        $harness->assertTrue(str_contains($html, 'No director loan movements were found for this period.'));
    });
});
