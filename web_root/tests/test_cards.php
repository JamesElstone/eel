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
        $this->assertTrialBalanceValidationUsesCompanyCurrency();
        $this->assertCompaniesHousePageIncludesCompaniesHouseSnapshot();
        $this->assertCompaniesHouseSnapshotUsesSelectedCompanyContext();
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

        $this->assertTrue(str_contains($html, '$0.00'));
        $this->assertTrue(str_contains($html, '$100.00'));
        $this->assertTrue(str_contains($html, '$42.50'));

        test_output_line('Cards: trial_balance_validation renders monetary metrics with company currency.');
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

