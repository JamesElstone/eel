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

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_company_accounts::class, static function (GeneratedServiceClassTestHarness $harness, _company_accounts $page): void {
    $harness->check(_company_accounts::class, 'maps field mapping account id from banking request context', static function () use ($harness, $page): void {
        $_GET = ['page' => 'company_accounts'];
        $_POST = [
            'field_mapping_account_id' => '47',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $reflection = new ReflectionMethod(_company_accounts::class, 'buildContext');
        $reflection->setAccessible(true);
        $context = $reflection->invoke($page, RequestFramework::fromGlobals(), createTestPageServiceFramework(), ActionResultFramework::none());

        $harness->assertSame(47, $context['field_mapping']['account_id'] ?? null);
    });

    $harness->check(_company_accounts::class, 'preserves selected mapping account after save requests', static function () use ($harness, $page): void {
        $_GET = ['page' => 'company_accounts'];
        $_POST = [
            'mapping_account_id' => '47',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $reflection = new ReflectionMethod(_company_accounts::class, 'buildContext');
        $reflection->setAccessible(true);
        $context = $reflection->invoke($page, RequestFramework::fromGlobals(), createTestPageServiceFramework(), ActionResultFramework::none());

        $harness->assertSame(47, $context['field_mapping']['account_id'] ?? null);
    });

    $harness->check(_company_accounts::class, 'maps edit account id from banking edit request context', static function () use ($harness, $page): void {
        $_GET = ['page' => 'company_accounts'];
        $_POST = [
            'intent' => 'edit',
            'account_id' => '47',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $reflection = new ReflectionMethod(_company_accounts::class, 'buildContext');
        $reflection->setAccessible(true);
        $context = $reflection->invoke($page, RequestFramework::fromGlobals(), createTestPageServiceFramework(), ActionResultFramework::none());

        $harness->assertSame(47, $context['edit_account_id'] ?? null);
    });

    $harness->check(_company_accounts::class, 'maps edit account id from action query context', static function () use ($harness, $page): void {
        $_GET = ['page' => 'company_accounts'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $reflection = new ReflectionMethod(_company_accounts::class, 'buildContext');
        $reflection->setAccessible(true);
        $actionResult = ActionResultFramework::success(
            ['page.context'],
            [],
            ['edit_account_id' => 47]
        );
        $context = $reflection->invoke($page, RequestFramework::fromGlobals(), createTestPageServiceFramework(), $actionResult);

        $harness->assertSame(47, $context['edit_account_id'] ?? null);
    });

    $harness->check(_banking_account_formCard::class, 'posts selected account id when rendering edit form', static function () use ($harness): void {
        $card = new _banking_account_formCard();
        $html = $card->render([
            'company' => [
                'id' => 12,
                'accounting_period_id' => 34,
            ],
            'edit_account_id' => 47,
            'page' => [],
            'services' => [
                'LookupCompanyAccount' => [
                    'id' => 47,
                    'account_name' => 'Main Current Account',
                    'account_type' => CompanyAccountService::TYPE_BANK,
                    'nominal_account_id' => 7,
                    'is_active' => 1,
                ],
                'nominal_accounts' => [[
                    'id' => 7,
                    'code' => '1001',
                    'name' => 'Main Current Account',
                    'account_type' => 'asset',
                    'subtype_code' => 'bank',
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'name="intent" value="save"'));
        $harness->assertTrue(str_contains($html, 'name="account_id" value="47"'));
        $harness->assertTrue(str_contains($html, 'name="edit_account_id" value="47"'));
        $harness->assertTrue(str_contains($html, 'name="nominal_account_id"'));
        $harness->assertTrue(str_contains($html, '1001 Main Current Account'));
    });
});
