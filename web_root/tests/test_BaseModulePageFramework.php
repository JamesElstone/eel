<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class TestBaseModulePageFrameworkDouble extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'test_module';
    }

    public function title(): string
    {
        return 'Test Module';
    }

    public function subtitle(): string
    {
        return 'Test subtitle';
    }

    public function cards(): array
    {
        return ['alpha', 'beta'];
    }

    public function exposedBuildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        return $this->buildContext($request, $services, $actionResult);
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        return [
            'module_flag' => true,
            'base_page_id' => (string)($baseContext['page_id'] ?? ''),
        ];
    }
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(TestBaseModulePageFrameworkDouble::class, function (GeneratedServiceClassTestHarness $harness, TestBaseModulePageFrameworkDouble $page): void {
    $harness->check(BaseModulePageFramework::class, 'declares shared module services', function () use ($harness, $page): void {
        $harness->assertSame([CompanyAccountService::class], $page->services());
    });

    $harness->check(BaseModulePageFramework::class, 'builds baseline module page context and merges module context', function () use ($harness, $page): void {
        $_GET = ['page' => 'test-module'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $request = RequestFramework::fromGlobals();
        $services = new PageServiceFramework([
            CompanyAccountService::class => new CompanyAccountService(),
        ]);

        $context = $page->exposedBuildContext($request, $services, ActionResultFramework::none());

        $harness->assertSame('test_module', $context['page_id'] ?? null);
        $harness->assertSame(['alpha', 'beta'], $context['page_cards'] ?? null);
        $harness->assertSame(0, $context['company_id'] ?? null);
        $harness->assertSame(0, $context['tax_year_id'] ?? null);
        $harness->assertSame(false, $context['has_valid_selected_company'] ?? null);
        $harness->assertTrue(is_array($context['settings'] ?? null));
        $harness->assertSame(0, $context['default_bank_nominal_id'] ?? null);
        $harness->assertSame(true, $context['module_flag'] ?? null);
        $harness->assertSame('test_module', $context['base_page_id'] ?? null);
    });
});
