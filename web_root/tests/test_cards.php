<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

final class TestCardsHarness
{
    public function run(): void
    {
        $cardFiles = $this->cardFiles();
        $this->assertTrue($cardFiles !== []);

        foreach ($cardFiles as $cardFile) {
            $cardKey = basename($cardFile, '.php');
            $className = HelperFramework::cardKeyToClassName($cardKey);

            $this->assertTrue(class_exists($className));

            $card = new $className();
            $this->assertTrue($card instanceof CardInterfaceFramework);
            $this->assertSame($cardKey, $card->key());
            $this->assertTrue(is_array($card->handle(
                RequestFramework::fromGlobals(),
                createTestPageServiceFramework(),
                [],
                ActionResultFramework::none()
            )));
            $this->assertTrue(is_array($card->services()));
            $this->assertTrue(is_array($card->invalidationFacts()));
            $this->assertTrue(is_string($card->handleError('demo', ['type' => 'error', 'message' => 'Demo'], [])));
            $this->assertTrue(is_string($card->render([])));

            test_output_line('Cards: ' . $cardKey . ' meets the shared card contract.');
        }

        $this->assertRoleAssignmentCardOwnsDashboardContext();
        $this->assertTrialBalanceStateUsesSelectedCompanyContext();
        $this->assertTrialBalanceStateRendersNestedMetrics();
        $this->assertTrialBalanceStateDoesNotShowPerfectGaugeBeforeReady();
        $this->assertTrialBalanceValidationUsesCompanyCurrency();
        $this->assertTrialBalanceLossesRendersCtPeriodTotals();
        $this->assertCompaniesHousePageIncludesSnapshotAndConfirmation();
        $this->assertCompaniesHouseSnapshotUsesSelectedCompanyContext();
        $this->assertJournalPageIncludesCutOffJournalsAdjustments();
        $this->assertYearEndConfirmationCardsLiveOnRelatedPages();
        $this->assertYearEndTransactionTailRendersBalanceColumn();
    }

    private function assertRoleAssignmentCardOwnsDashboardContext(): void
    {
        $card = new _role_assignmentCard();
        $services = $card->services();

        $this->assertSame(RoleAssignmentService::class, (string)($services[0]['service'] ?? ''));
        $this->assertSame('dashboardData', (string)($services[0]['method'] ?? ''));

        $handledContext = $card->handle(
            new RequestFramework([], ['role_id' => '42'], ['REQUEST_METHOD' => 'POST'], [], []),
            createTestPageServiceFramework(),
            ['page' => ['page_id' => 'roles']],
            ActionResultFramework::none()
        );

        $this->assertSame(42, (int)($handledContext['role_assignment']['selected_role_id'] ?? 0));

        $html = $card->render([
            'page' => [
                'page_cards' => ['role_assignment'],
                'csrf_token' => 'token',
            ],
            'services' => [
                'roles_dashboard' => [
                    'roles' => [
                        ['id' => 42, 'role_name' => 'Managers'],
                    ],
                    'selected_role_id' => 42,
                    'matrix_rows' => [
                        [
                            'card_key' => 'current_users',
                            'card_label' => 'Current Users',
                            'is_allowed' => true,
                            'is_forced' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(str_contains($html, 'Managers'));
        $this->assertTrue(str_contains($html, 'Current Users'));
        $this->assertTrue(str_contains($html, 'name="action" value="roles-select-role"'));

        test_output_line('Cards: role_assignment owns dashboard service context.');
    }

    private function assertTrialBalanceStateUsesSelectedCompanyContext(): void
    {
        $card = new _trial_balance_stateCard();

        foreach ($card->services() as $definition) {
            $params = (array)($definition['params'] ?? []);

            if (array_key_exists('companyId', $params)) {
                $this->assertSame(':company.id', $params['companyId']);
            }

            if (array_key_exists('accountingPeriodId', $params)) {
                $this->assertSame(':company.accounting_period_id', $params['accountingPeriodId']);
            }
        }

        test_output_line('Cards: trial_balance_state uses selected company context for services.');
    }

    private function assertTrialBalanceStateRendersNestedMetrics(): void
    {
        $card = new _trial_balance_stateCard();
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $html = $card->render([
                'company' => [
                    'id' => 27,
                    'accounting_period_id' => 31,
                    'name' => 'Nested Metric Limited',
                ],
                'page' => ['page_id' => 'trial_balance'],
                'trial_balance_view_mode' => 'summary',
                'services' => [
                    'trialBalanceState' => [
                        'trial_balance' => [
                            'available' => true,
                            'company' => ['company_name' => 'Nested Metric Limited'],
                            'accounting_period' => ['label' => '2025'],
                            'summary' => [
                                'trial_balance_status' => ['is_balanced' => true, 'label' => 'Balanced'],
                            ],
                            'rows' => [],
                        ],
                        'validation' => [
                            'available' => true,
                            'ready_for_ct_working_papers' => 'Nearly ready',
                            'checks' => [
                                [
                                    'title' => 'Nested metric check',
                                    'status' => 'warning',
                                    'detail' => 'Contains nested metric arrays.',
                                    'metric_value' => [
                                        'difference' => 0.0,
                                        'bank_ledger_reasonableness' => [
                                            'transaction_movement' => 100.0,
                                            'ledger_movement' => 100.0,
                                        ],
                                        'month_tiles' => [
                                            ['month_key' => '2025-01', 'status' => 'green'],
                                        ],
                                    ],
                                ],
                            ],
                            'month_tiles' => [],
                        ],
                    ],
                ],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame(false, str_contains($html, 'Array'));

        test_output_line('Cards: trial_balance_state renders nested metric values without warnings.');
    }

    private function assertTrialBalanceStateDoesNotShowPerfectGaugeBeforeReady(): void
    {
        $card = new _trial_balance_stateCard();
        $html = $card->render([
            'company' => [
                'settings' => ['default_currency_symbol' => '&pound;'],
            ],
            'services' => [
                'trialBalanceState' => [
                    'trial_balance' => [
                        'available' => true,
                        'summary' => [
                            'trial_balance_status' => ['is_balanced' => true, 'label' => 'Balanced'],
                        ],
                    ],
                    'validation' => [
                        'available' => true,
                        'ready_for_ct_working_papers' => 'Nearly ready',
                        'checks' => [
                            ['code' => 'trial_balance_equality', 'title' => 'Trial balance equality', 'status' => 'pass'],
                            ['code' => 'uncategorised_transactions', 'title' => 'Uncategorised and posting route check', 'status' => 'pass'],
                            ['code' => 'suspense_check', 'title' => 'Suspense and uncategorised exposure', 'status' => 'pass'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(str_contains($html, '>70</text>'));
        $this->assertTrue(!str_contains($html, '>100</text><text class="chart-gauge-label"'));
        $this->assertTrue(str_contains($html, '>Nearly ready</text>'));
        $this->assertTrue(str_contains($html, 'Posted ledger'));
        $this->assertTrue(str_contains($html, 'TB equality'));
        $this->assertTrue(str_contains($html, 'Uncategorised txns'));
        $this->assertTrue(str_contains($html, 'Missing posting routes'));
        $this->assertTrue(str_contains($html, 'Suspense exposure'));
        $this->assertTrue(str_contains($html, 'Unposted journals'));
        $this->assertTrue(str_contains($html, 'Bank ledger diff'));
        $this->assertTrue(str_contains($html, 'Period completeness'));
        $this->assertTrue(str_contains($html, 'FRS 105 deferred tax'));
        $this->assertTrue(str_contains($html, 'Review notes'));
        $this->assertTrue(str_contains($html, 'Trial balance comparison differences'));

        test_output_line('Cards: trial_balance_state caps the gauge before CT readiness is complete.');
    }

    private function assertTrialBalanceValidationUsesCompanyCurrency(): void
    {
        $card = new _trial_balance_validationCard();
        $html = $card->render([
            'company' => [
                'settings' => ['default_currency_symbol' => '&#36;'],
            ],
            'services' => [
                'trialBalanceState' => [
                    'validation' => [
                        'available' => true,
                        'checks' => [
                            [
                                'title' => 'Nested metric check',
                                'status' => 'warning',
                                'detail' => 'Contains nested metric arrays.',
                                'metric_value' => [
                                    'difference' => 0.0,
                                    'bank_ledger_reasonableness' => [
                                        'transaction_movement' => 100.0,
                                        'ledger_movement' => 100.0,
                                    ],
                                ],
                            ],
                            [
                                'title' => 'Scalar metric check',
                                'status' => 'pass',
                                'detail' => 'Contains a top-level numeric metric.',
                                'metric_value' => 42.5,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(!str_contains($html, '$ 0.00'));
        $this->assertTrue(!str_contains($html, 'Difference'));
        $this->assertTrue(str_contains($html, '$ 100.00'));
        $this->assertTrue(str_contains($html, '$ 42.50'));

        test_output_line('Cards: trial_balance_validation suppresses zero metrics and renders remaining metrics with company currency.');
    }

    private function assertTrialBalanceLossesRendersCtPeriodTotals(): void
    {
        $card = new _trial_balance_lossesCard();
        $html = $card->render([
            'company' => [
                'settings' => ['default_currency_symbol' => '&#36;'],
            ],
            'services' => [
                'trialBalanceTaxSummary' => [
                    'available' => true,
                    'summary_scope' => 'accounting_period_ct_periods',
                    'estimated_corporation_tax' => 300.00,
                    'taxable_profit' => 1500.00,
                    'loss_created_in_period' => 50.00,
                    'losses_brought_forward' => 25.00,
                    'losses_used' => 10.00,
                    'losses_carried_forward' => 65.00,
                    'periods' => [
                        [
                            'period_label' => '1 Jan 2026 to 31 Mar 2026',
                            'taxable_profit' => 500.00,
                            'estimated_corporation_tax' => 100.00,
                            'loss_created_in_period' => 50.00,
                            'losses_used' => 0.00,
                            'losses_carried_forward' => 75.00,
                            'warnings' => ['Review one'],
                        ],
                        [
                            'period_label' => '1 Apr 2026 to 31 Dec 2026',
                            'taxable_profit' => 1000.00,
                            'estimated_corporation_tax' => 200.00,
                            'loss_created_in_period' => 0.00,
                            'losses_used' => 10.00,
                            'losses_carried_forward' => 65.00,
                            'warnings' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(str_contains($html, 'Estimated corporation tax'));
        $this->assertTrue(str_contains($html, '$ 300.00'));
        $this->assertTrue(str_contains($html, 'CT period breakdown'));
        $this->assertTrue(str_contains($html, '1 Jan 2026 to 31 Mar 2026'));
        $this->assertTrue(str_contains($html, '1 Apr 2026 to 31 Dec 2026'));
        $this->assertTrue(str_contains($html, '$ 100.00'));
        $this->assertTrue(str_contains($html, '$ 200.00'));

        $errorHtml = $card->render([
            'services' => [
                'trialBalanceTaxSummary' => [
                    'available' => false,
                    'errors' => ['No CT period summaries could be generated.'],
                ],
            ],
        ]);
        $this->assertTrue(str_contains($errorHtml, 'No CT period summaries could be generated.'));

        test_output_line('Cards: trial_balance_losses renders CT-period tax totals and unavailable state.');
    }

    private function assertCompaniesHousePageIncludesSnapshotAndConfirmation(): void
    {
        $trialBalancePage = new _trial_balance();
        $companiesHousePage = new _companies_house();

        $this->assertSame(['trial_balance_state', 'trial_balance_validation', 'trial_balance_losses'], $trialBalancePage->cards());
        $this->assertSame(['companies_house_snapshot', 'year_end_companies_house_comparison'], $companiesHousePage->cards());
        $this->assertPageTabContains($companiesHousePage, 'Snapshot', ['companies_house_snapshot']);
        $this->assertPageTabContains($companiesHousePage, 'Year End Confirmation', ['year_end_companies_house_comparison']);

        test_output_line('Cards: companies_house page includes snapshot and Year End Confirmation tabs.');
    }

    private function assertCompaniesHouseSnapshotUsesSelectedCompanyContext(): void
    {
        $card = new _companies_house_snapshotCard();
        $this->assertSame('Companies House Snapshot', $card->title());
        $this->assertSame(['companiesHouseSnapshot'], array_column($card->services(), 'key'));

        foreach ($card->services() as $definition) {
            $params = (array)($definition['params'] ?? []);

            if (array_key_exists('companyId', $params)) {
                $this->assertSame(':company.id', $params['companyId']);
            }

            if (array_key_exists('accountingPeriodId', $params)) {
                $this->assertSame(':company.accounting_period_id', $params['accountingPeriodId']);
            }
        }

        $html = $card->render([
            'services' => [
                'companiesHouseSnapshot' => [
                    'available' => true,
                    'is_balance_sheet_balanced' => true,
                    'fields' => [
                        ['label' => 'Company name', 'value' => 'Snapshot Limited', 'is_money' => false],
                        ['label' => 'Current assets', 'value' => 5000.0, 'is_money' => true, 'note' => 'Current assets exclude fixed assets.'],
                    ],
                    'checks' => [
                        ['label' => 'Balance equation check', 'value' => 0.0, 'detail' => 'Total net assets less capital and reserves.'],
                    ],
                    'sources' => [
                        ['label' => 'Current assets', 'count' => 1, 'amount' => 5000.0],
                    ],
                    'assumptions' => ['Balance sheet facts use closing posted-journal balances.'],
                ],
            ],
        ]);

        $this->assertTrue(str_contains($html, 'Manual Companies House balance-sheet entry only.'));
        $this->assertTrue(str_contains($html, 'Snapshot Limited'));
        $this->assertSame(3, substr_count($html, 'class="panel-soft"'));
        $this->assertSame(false, str_contains($html, 'Companies House Comparison'));
        $this->assertSame(false, str_contains($html, 'Profit and loss figures remain') && str_contains($html, 'Expenses</td>'));

        test_output_line('Cards: companies_house_snapshot uses selected company context and renders balance sheet fields.');
    }

    private function assertYearEndConfirmationCardsLiveOnRelatedPages(): void
    {
        $yearEndPage = new _year_end();
        $movedCards = [
            'year_end_director_loan_offset',
            'year_end_expenses_confirmation',
            'year_end_companies_house_comparison',
            'year_end_empty_month_confirmations',
            'year_end_transaction_tail',
            'year_end_prepayment_approvals',
            'journal_cut_off_confirmation',
            'year_end_profit_loss_confirm',
            'year_end_tax_readiness',
        ];

        foreach ($movedCards as $cardKey) {
            $this->assertSame(false, in_array($cardKey, $yearEndPage->cards(), true));
            foreach ($yearEndPage->cardLayout() as $tab) {
                $this->assertSame(false, in_array($cardKey, (array)($tab['cards'] ?? []), true));
            }
        }

        $directorLoansPage = new _loans();
        $this->assertSame(
            ['director_loan_state', 'director_loan_s455', 'director_loan_ct600a', 'year_end_director_loan_offset'],
            $directorLoansPage->cards()
        );
        $this->assertPageTabContains($directorLoansPage, 'Statement', ['director_loan_state']);
        $this->assertPageTabContains($directorLoansPage, 'Participator loans (s455)', ['director_loan_s455', 'director_loan_ct600a']);
        $this->assertPageFinalTabContains($directorLoansPage, 'Year End Confirmation', ['year_end_director_loan_offset']);

        $incorporationPage = new _incorporation();
        $this->assertSame('Directors', (string)($incorporationPage->cardLayout()[2]['tab'] ?? ''));
        $this->assertPageTabContains($incorporationPage, 'Directors', ['director_loan_directors']);
        $this->assertPageFinalTabContains(new _expense_claims(), 'Year End Confirmation', ['year_end_expenses_confirmation']);
        $this->assertPageFinalTabContains(new _companies_house(), 'Year End Confirmation', ['year_end_companies_house_comparison']);
        $this->assertPageFinalTabContains(new _transactions(), 'Year End Confirmation', ['year_end_empty_month_confirmations', 'year_end_transaction_tail']);
        $this->assertPageFinalTabContains(new _prepayments(), 'Year End Confirmation', ['year_end_prepayment_approvals']);
        $this->assertPageFinalTabContains(new _journal(), 'Year End Confirmation', ['journal_cut_off_confirmation']);
        $this->assertPageTabContains(new _profit_loss(), 'Reserve Review', ['reserve_review']);
        $this->assertPageFinalTabContains(new _profit_loss(), 'Profit & Loss Confirmation', ['year_end_profit_loss_confirm']);
        $this->assertPageFinalTabContains(new _corporation_tax(), 'Year End Review', ['year_end_tax_readiness']);

        test_output_line('Cards: year-end confirmation cards live on their related workflow pages.');
    }

    private function assertJournalPageIncludesCutOffJournalsAdjustments(): void
    {
        $journalPage = new _journal();
        $this->assertTrue(in_array('journal_cut_offs', $journalPage->cards(), true));
        $this->assertSame(false, in_array('nominal_closing_balances', $journalPage->cards(), true));

        $hasCutOffJournalsAdjustment = false;
        foreach ($journalPage->cardLayout() as $tab) {
            if (($tab['tab'] ?? '') === 'Adjustments' && in_array('journal_cut_offs', (array)($tab['cards'] ?? []), true)) {
                $hasCutOffJournalsAdjustment = true;
                $this->assertSame(false, in_array('nominal_closing_balances', (array)($tab['cards'] ?? []), true));
            }
        }

        $this->assertSame(true, $hasCutOffJournalsAdjustment);
        $this->assertPageFinalTabContains($journalPage, 'Year End Confirmation', ['journal_cut_off_confirmation']);

        test_output_line('Cards: journal page includes Cut-off Journals under Adjustments.');
    }

    private function assertYearEndTransactionTailRendersBalanceColumn(): void
    {
        $card = new _year_end_transaction_tailCard();
        $html = $card->render([
            'company' => [
                'id' => 12,
                'accounting_period_id' => 34,
                'settings' => [],
            ],
            'services' => [
                'yearEndTransactionTail' => [
                    'available' => true,
                    'accounting_period' => [
                        'id' => 34,
                    ],
                    'rows' => [
                        [
                            'account' => 'Example Bank - Current Account',
                            'account_type' => 'bank',
                            'last_transaction_date' => '2023-09-29',
                            'last_transaction_desc' => 'FIXTURE CUSTOMER ALPHA',
                            'last_transaction_amount' => '125.00',
                            'balance' => '1234.56',
                        ],
                        [
                            'account' => 'Example Trade Supplier',
                            'account_type' => 'trade',
                            'last_transaction_date' => '',
                            'last_transaction_desc' => '',
                            'last_transaction_amount' => null,
                            'balance' => null,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(str_contains($html, '<th>Balance</th>'));
        $this->assertTrue(str_contains($html, '1,234.56'));
        $this->assertTrue(str_contains($html, '<td class="numeric">-</td>'));

        test_output_line('Cards: year_end_transaction_tail renders Balance column and blank placeholders.');
    }

    /**
     * @return list<string>
     */
    private function cardFiles(): array
    {
        $files = glob(APP_CARDS . '*.php');

        if ($files === false) {
            return [];
        }

        sort($files);

        return array_values(array_filter($files, 'is_file'));
    }

    private function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                'Assertion failed. Expected ' . var_export($expected, true) . ' but received ' . var_export($actual, true) . '.'
            );
        }
    }

    private function assertTrue(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('Assertion failed. Expected condition to be true.');
        }
    }

    private function assertPageTabContains(PageInterfaceFramework $page, string $tabName, array $expectedCards): void
    {
        $found = false;
        foreach ((array)$page->cardLayout() as $tab) {
            if ((string)($tab['tab'] ?? '') !== $tabName) {
                continue;
            }

            $found = true;
            $cards = (array)($tab['cards'] ?? []);
            foreach ($expectedCards as $cardKey) {
                $this->assertTrue(in_array($cardKey, $cards, true));
            }
        }

        $this->assertSame(true, $found);
    }

    private function assertPageFinalTabContains(PageInterfaceFramework $page, string $tabName, array $expectedCards): void
    {
        $layout = array_values((array)$page->cardLayout());
        $finalTab = (array)($layout[count($layout) - 1] ?? []);

        $this->assertSame($tabName, (string)($finalTab['tab'] ?? ''));
        foreach ($expectedCards as $cardKey) {
            $this->assertTrue(in_array($cardKey, (array)($finalTab['cards'] ?? []), true));
        }
    }
}

(new TestCardsHarness())->run();

