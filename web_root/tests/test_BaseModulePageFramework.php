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

final class TestPageContextFrameworkDouble extends PageContextFramework
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
            'base_page_id' => (string)($baseContext['page']['page_id'] ?? ''),
        ];
    }
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(TestPageContextFrameworkDouble::class, function (GeneratedServiceClassTestHarness $harness, TestPageContextFrameworkDouble $page): void {
    $harness->check(PageContextFramework::class, 'declares shared module services', function () use ($harness, $page): void {
        $harness->assertSame([], $page->services());
    });

    $harness->check(PageContextFramework::class, 'builds baseline module page context and merges module context', function () use ($harness, $page): void {
        $_GET = ['page' => 'test-module'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $request = RequestFramework::fromGlobals();
        $services = createTestPageServiceFramework();

        $context = $page->exposedBuildContext($request, $services, ActionResultFramework::none());
        $singleCompanyId = 0;
        $companies = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanySelectorRows();
        if (count($companies) === 1) {
            $singleCompanyId = (int)($companies[0]['id'] ?? 0);
        }

        $harness->assertSame('test_module', $context['page']['page_id'] ?? null);
        $harness->assertSame(['alpha', 'beta'], $context['page']['page_cards'] ?? null);
        $harness->assertSame($singleCompanyId, $context['company']['id'] ?? null);
        $harness->assertSame(0, $context['company']['accounting_period_id'] ?? null);
        $harness->assertSame($singleCompanyId > 0, $context['company']['valid_selected'] ?? null);
        $harness->assertTrue(is_array($context['company']['settings'] ?? null));
        if ($singleCompanyId === 0) {
            $harness->assertSame('', $context['company']['settings']['default_bank_nominal_id'] ?? null);
        }
        $harness->assertSame(true, $context['module_flag'] ?? null);
        $harness->assertSame('test_module', $context['base_page_id'] ?? null);
    });

    $harness->check(PageContextFramework::class, 'does not treat action query values as site context selection', function () use ($harness, $page): void {
        $_GET = ['page' => 'test-module'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $request = RequestFramework::fromGlobals();
        $services = createTestPageServiceFramework();

        $context = $page->exposedBuildContext(
            $request,
            $services,
            ActionResultFramework::success([], [], [], [
                'company_id' => 22,
                'accounting_period_id' => 33,
            ])
        );

        $harness->assertSame(0, $context['company']['id'] ?? null);
        $harness->assertSame(0, $context['company']['accounting_period_id'] ?? null);
    });

    $harness->check(PageContextFramework::class, 'ignores stale legacy session company values', function () use ($harness, $page): void {
        $_GET = ['page' => 'test-module'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $_SESSION = [];
        $_SESSION['app.company_id'] = 22;

        $request = RequestFramework::fromGlobals();
        $services = createTestPageServiceFramework();

        $context = $page->exposedBuildContext($request, $services, ActionResultFramework::none());

        $harness->assertSame(0, $context['company']['id'] ?? null);
    });
});
