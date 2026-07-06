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
        $this->assertCompaniesHousePageIncludesCompaniesHouseSnapshot();
        $this->assertCompaniesHouseSnapshotUsesSelectedCompanyContext();
        $this->assertYearEndPageIncludesCompaniesHouseComparison();
        $this->assertYearEndCompaniesHouseComparisonRendersMismatchAcknowledgement();
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
                    'trialBalancePageData' => [
                        'available' => true,
                        'company' => ['company_name' => 'Nested Metric Limited'],
                        'accounting_period' => ['label' => '2025'],
                        'filters' => [
                            'search' => '',
                            'account_type' => 'all',
                            'focus' => 'all',
                        ],
                        'summary' => [
                            'trial_balance_status' => ['is_balanced' => true, 'label' => 'Balanced'],
                            'tax_computation' => ['available' => false, 'errors' => ['No tax computation.']],
                        ],
                        'rows' => [],
                    ],
                    'trialBalanceValidation' => [
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
                                        [
                                            'month_key' => '2025-01',
                                            'status' => 'green',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'month_tiles' => [],
                    ],
                    'trialBalanceComparison' => [
                        'available' => false,
                        'errors' => ['No comparison.'],
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
                'trialBalancePageData' => [
                    'available' => true,
                    'summary' => [
                        'trial_balance_status' => ['is_balanced' => true, 'label' => 'Balanced'],
                    ],
                ],
                'trialBalanceValidation' => [
                    'available' => true,
                    'ready_for_ct_working_papers' => 'Nearly ready',
                    'checks' => [
                        ['code' => 'trial_balance_equality', 'title' => 'Trial balance equality', 'status' => 'pass'],
                        ['code' => 'uncategorised_transactions', 'title' => 'Uncategorised and posting route check', 'status' => 'pass'],
                        ['code' => 'suspense_check', 'title' => 'Suspense and uncategorised exposure', 'status' => 'pass'],
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
        $this->assertTrue(str_contains($html, 'TB comparison diffs'));

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
                'trialBalanceValidation' => [
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
        ]);

        $this->assertTrue(str_contains($html, '$ 0.00'));
        $this->assertTrue(str_contains($html, '$ 100.00'));
        $this->assertTrue(str_contains($html, '$ 42.50'));

        test_output_line('Cards: trial_balance_validation renders monetary metrics with company currency.');
    }

    private function assertTrialBalanceLossesRendersCtPeriodTotals(): void
    {
        $card = new _trial_balance_lossesCard();
        $html = $card->render([
            'company' => [
                'settings' => ['default_currency_symbol' => '&#36;'],
            ],
            'services' => [
                'trialBalancePageData' => [
                    'available' => true,
                    'summary' => [
                        'tax_computation' => [
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
                'trialBalancePageData' => [
                    'available' => true,
                    'summary' => [
                        'tax_computation' => [
                            'available' => false,
                            'errors' => ['No CT period summaries could be generated.'],
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertTrue(str_contains($errorHtml, 'No CT period summaries could be generated.'));

        test_output_line('Cards: trial_balance_losses renders CT-period tax totals and unavailable state.');
    }

    private function assertCompaniesHousePageIncludesCompaniesHouseSnapshot(): void
    {
        $trialBalancePage = new _trial_balance();
        $companiesHousePage = new _companies_house();

        $this->assertSame(['trial_balance_state', 'trial_balance_validation', 'trial_balance_losses'], $trialBalancePage->cards());
        $this->assertSame(['companies_house_snapshot'], $companiesHousePage->cards());

        test_output_line('Cards: companies_house page includes the Companies House snapshot card.');
    }

    private function assertCompaniesHouseSnapshotUsesSelectedCompanyContext(): void
    {
        $card = new _companies_house_snapshotCard();
        $this->assertSame('Companies House Snapshot', $card->title());

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
        $this->assertTrue(substr_count($html, 'class="panel-soft"') >= 4);
        $this->assertSame(false, str_contains($html, 'Profit and loss figures remain') && str_contains($html, 'Expenses</td>'));

        test_output_line('Cards: companies_house_snapshot uses selected company context and renders balance sheet fields.');
    }

    private function assertYearEndPageIncludesCompaniesHouseComparison(): void
    {
        $yearEndPage = new _year_end();
        $this->assertTrue(in_array('year_end_companies_house_comparison', $yearEndPage->cards(), true));
        $this->assertTrue(in_array('prepayments_review', $yearEndPage->cards(), true));
        $this->assertTrue(in_array('cut_off_journals', $yearEndPage->cards(), true));

        $layout = $yearEndPage->cardLayout();
        $hasCompaniesHouseTab = false;
        $hasPrepaymentsTab = false;
        $hasCutOffJournalsTab = false;
        foreach ($layout as $tab) {
            if (($tab['tab'] ?? '') === 'Companies House' && in_array('year_end_companies_house_comparison', (array)($tab['cards'] ?? []), true)) {
                $hasCompaniesHouseTab = true;
            }
            if (($tab['tab'] ?? '') === 'Prepayments' && in_array('prepayments_review', (array)($tab['cards'] ?? []), true)) {
                $hasPrepaymentsTab = true;
            }
            if (($tab['tab'] ?? '') === 'Cut-off Journals' && in_array('cut_off_journals', (array)($tab['cards'] ?? []), true)) {
                $hasCutOffJournalsTab = true;
            }
        }

        $this->assertSame(true, $hasCompaniesHouseTab);
        $this->assertSame(true, $hasPrepaymentsTab);
        $this->assertSame(true, $hasCutOffJournalsTab);

        test_output_line('Cards: year_end page includes Companies House, Prepayments, and Cut-off Journals tabs.');
    }

    private function assertYearEndCompaniesHouseComparisonRendersMismatchAcknowledgement(): void
    {
        $card = new _year_end_companies_house_comparisonCard();
        $this->assertSame('Year End Companies House Comparison', $card->title());

        $html = $card->render([
            'company' => [
                'id' => 12,
                'accounting_period_id' => 34,
                'settings' => [],
            ],
            'services' => [
                'yearEndCompaniesHouseComparison' => [
                    'available' => true,
                    'comparison_note' => 'Matching filed numbers suggests the reconstructed ledger aligns with the stored Companies House filing.',
                    'filing' => [
                        'filing_date' => '2025-05-29',
                    ],
                    'rows' => [
                        ['label' => 'Fixed assets', 'app_value' => 208.41, 'filed_value' => 0.00, 'variance' => 208.41, 'status' => 'fail'],
                        ['label' => 'Current assets', 'app_value' => 1038.26, 'filed_value' => 275.00, 'variance' => 763.26, 'status' => 'fail'],
                    ],
                ],
            ],
            'year_end' => [
                'checklist' => [
                    'review_acknowledgements' => [],
                ],
            ],
        ]);

        $this->assertTrue(str_contains($html, 'Companies House Comparison'));
        $this->assertTrue(str_contains($html, 'Stored filing date: 2025-05-29'));
        $this->assertTrue(str_contains($html, 'Fixed assets'));
        $this->assertTrue(str_contains($html, 'Current assets'));
        $this->assertTrue(str_contains($html, 'companies_house_mismatch_acknowledgement'));
        $this->assertTrue(str_contains($html, 'will be corrected before HMRC submission'));

        test_output_line('Cards: year_end_companies_house_comparison renders mismatch data and acknowledgement.');
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
                            'last_transaction_desc' => 'LAURA IRVINE',
                            'last_transaction_amount' => '379.41',
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
}

(new TestCardsHarness())->run();

