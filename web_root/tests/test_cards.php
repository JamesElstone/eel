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

