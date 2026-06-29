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
$harness->run(_banking_reconciliationCard::class, static function (GeneratedServiceClassTestHarness $harness, _banking_reconciliationCard $card): void {
    $harness->check(_banking_reconciliationCard::class, 'renders account labels for each reconciliation panel', static function () use ($harness, $card): void {
        $html = $card->render([
            'services' => [
                'accounting_period' => [
                    'label' => '01/10/2025 to 30/09/2026',
                ],
                'reconciliationPanels' => [
                    [
                        'account' => [
                            'account_name' => 'Current Account',
                            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                            'institution_name' => 'Anna Money',
                            'account_identifier' => '',
                        ],
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                        'statement_continuity_status' => 'pass',
                        'running_balance_status' => 'pass',
                        'ledger_reconciliation_status' => 'warning',
                        'uploads' => [
                            [
                                'statement_month' => '2025-10-01',
                                'upload' => [
                                    'original_filename' => '2025-10-ANNA_011025_311025.csv',
                                ],
                                'opening_balance' => 911.03,
                                'closing_balance' => 390.24,
                                'previous_statement_closing_balance' => 911.03,
                                'continuity_status' => 'pass',
                                'continuity_note' => 'Opening balance matches the previous statement closing balance.',
                                'running_balance_status' => 'pass',
                                'running_balance_note' => '53 rows tested, 0 breaks',
                            ],
                        ],
                        'ledger_summary' => [
                            'statement_closing_balance' => 390.24,
                            'ledger_balance' => null,
                            'difference' => null,
                            'note' => 'No posted ledger activity hits the configured Bank nominal by the statement closing date.',
                            'scope_note' => 'Ledger reconciliation is still company-bank-wide because journal posting currently uses one generic Bank nominal.',
                        ],
                    ],
                    [
                        'account' => [
                            'account_name' => 'Anna Money - Saving Pot (20%)',
                            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                            'institution_name' => 'Anna Money',
                            'account_identifier' => 'POT(20%)',
                        ],
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                        'statement_continuity_status' => 'not_available',
                        'running_balance_status' => 'not_available',
                        'ledger_reconciliation_status' => 'not_available',
                        'uploads' => [],
                        'ledger_summary' => [
                            'note' => 'No statement closing balance is available yet for this bank account.',
                            'scope_note' => 'Ledger reconciliation is still company-bank-wide because journal posting currently uses one generic Bank nominal.',
                        ],
                    ],
                    [
                        'account' => [
                            'account_name' => 'TLC Direct',
                            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
                            'institution_name' => 'TLC Direct Limited',
                            'account_identifier' => 'E57808',
                        ],
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
                        'ledger_reconciliation_status' => 'pass',
                        'uploads' => [],
                        'trade_summary' => [
                            'status' => 'pass',
                            'line_count' => 2,
                            'debit_total' => 120.00,
                            'credit_total' => 120.00,
                            'net_balance' => 0.00,
                            'balance_label' => 'Nil',
                            'last_journal_date' => '2026-05-20',
                            'note' => 'Posted ledger lines tagged to this trade account produce a nil or creditor balance.',
                            'scope_note' => 'Supplier statement matching is not implemented yet; this is a ledger-tagged trade account check.',
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '<section class="indexed-section">'));
        $harness->assertTrue(str_contains($html, '<h3 class="indexed-section-title">Current Account</h3>'));
        $harness->assertTrue(str_contains($html, '<div class="indexed-section-helper">Anna Money</div>'));
        $harness->assertTrue(str_contains($html, '<h3 class="indexed-section-title">Anna Money - Saving Pot (20%)</h3>'));
        $harness->assertTrue(str_contains($html, '<div class="indexed-section-helper">Anna Money · POT(20%)</div>'));
        $harness->assertTrue(str_contains($html, 'No bank statement uploads are available for Anna Money - Saving Pot (20%) in the selected accounting period.'));
        $harness->assertTrue(str_contains($html, '<h3 class="indexed-section-title">TLC Direct</h3>'));
        $harness->assertTrue(str_contains($html, 'Trade Ledger Check'));
        $harness->assertTrue(str_contains($html, 'Supplier statement matching is not implemented yet; this is a ledger-tagged trade account check.'));
    });

    $harness->check(_banking_reconciliationCard::class, 'renders bank upload checks with framework tables', static function () use ($harness, $card): void {
        $uploads = [];
        for ($month = 1; $month <= 13; $month++) {
            $statementMonth = (new DateTimeImmutable('2026-01-01'))->modify('+' . ($month - 1) . ' months')->format('Y-m-d');
            $uploads[] = [
                'statement_month' => $statementMonth,
                'upload' => [
                    'original_filename' => 'statement-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '.csv',
                ],
                'opening_balance' => 100 + $month,
                'closing_balance' => 200 + $month,
                'previous_statement_closing_balance' => 100 + $month,
                'continuity_status' => 'pass',
                'continuity_note' => 'Opening balance matches the previous statement closing balance.',
                'running_balance_status' => 'pass',
                'running_balance_note' => '53 rows tested, 0 breaks',
            ];
        }

        $context = [
            'page' => [
                'page_cards' => ['banking_reconciliation'],
            ],
            'services' => [
                'accounting_period' => [
                    'label' => '01/10/2025 to 30/09/2026',
                ],
                'reconciliationPanels' => [
                    [
                        'account' => [
                            'id' => 47,
                            'account_name' => 'Current Account',
                            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                            'institution_name' => 'Anna Money',
                        ],
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                        'statement_continuity_status' => 'pass',
                        'running_balance_status' => 'pass',
                        'ledger_reconciliation_status' => 'warning',
                        'uploads' => $uploads,
                        'ledger_summary' => [],
                    ],
                    [
                        'account' => [
                            'id' => 48,
                            'account_name' => 'TLC Direct',
                            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
                            'institution_name' => 'TLC Direct Limited',
                        ],
                        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
                        'ledger_reconciliation_status' => 'pass',
                        'trade_summary' => [],
                    ],
                ],
            ],
        ];

        $html = $card->render($context);
        $tables = $card->tables($context);

        $harness->assertTrue(str_contains($html, '<div class="card-toolbar">'));
        $harness->assertTrue(str_contains($html, 'table_key" value="banking_reconciliation_uploads_account_47"'));
        $harness->assertTrue(str_contains($html, '<div class="table-scroll panel-soft"><table>'));
        $harness->assertTrue(str_contains($html, '<div class="panel-soft">
                <h4 class="card-title">Ledger Reconciliation</h4>'));
        $harness->assertTrue(str_contains($html, 'statement-12.csv'));
        $harness->assertTrue(!str_contains($html, 'statement-13.csv'));
        $harness->assertTrue(str_contains($html, 'Statement uploads 1-12 of 13'));
        $harness->assertTrue(str_contains($html, 'name="banking_reconciliation_page" value="2"'));
        $harness->assertCount(1, $tables);
        $harness->assertTrue($tables[0] instanceof TableFramework);

        $csv = $tables[0]->exportCsv();
        $harness->assertTrue(str_contains($csv, '2026-01-01 | statement-01.csv'));
        $harness->assertTrue(str_contains($csv, '2027-01-01 | statement-13.csv'));
        $harness->assertTrue(str_contains($csv, 'Pass | Opening balance matches the previous statement closing balance.'));
        $harness->assertTrue(!str_contains($csv, 'Open Upload'));
    });
});
