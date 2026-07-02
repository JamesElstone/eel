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

function resetTestPageContextSelection(): void
{
    $_REQUEST = [];
    unset($GLOBALS['__request_framework_raw_body']);
    $sessionAuthenticationService = new SessionAuthenticationService();
    $sessionAuthenticationService->startSession();
    $_SESSION = [];
    (new \eel_accounts\Service\AccountingContextService())->clearPageContext();
}

function expectedTestPageContextSelection(): array
{
    $companies = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanySelectorRows();

    if (count($companies) !== 1) {
        return [0, 0, false];
    }

    $companyId = (int)($companies[0]['id'] ?? 0);
    $accountingPeriods = $companyId > 0
        ? (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriods($companyId)
        : [];

    return [
        $companyId,
        (int)($accountingPeriods[0]['id'] ?? 0),
        $companyId > 0,
    ];
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(TestPageContextFrameworkDouble::class, function (GeneratedServiceClassTestHarness $harness, TestPageContextFrameworkDouble $page): void {
    $harness->check(PageContextFramework::class, 'declares shared module services', function () use ($harness, $page): void {
        $harness->assertSame([], $page->services());
    });

    $harness->check(PageContextFramework::class, 'builds baseline module page context and merges module context', function () use ($harness, $page): void {
        resetTestPageContextSelection();
        $_GET = ['page' => 'test-module'];
        $_POST = [];
        $_REQUEST = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $request = RequestFramework::fromGlobals();
        $services = createTestPageServiceFramework();

        $context = $page->exposedBuildContext($request, $services, ActionResultFramework::none());
        [$expectedCompanyId, $expectedAccountingPeriodId, $expectedValidSelected] = expectedTestPageContextSelection();

        $harness->assertSame('test_module', $context['page']['page_id'] ?? null);
        $harness->assertSame(['alpha', 'beta'], $context['page']['page_cards'] ?? null);
        $harness->assertSame($expectedCompanyId, $context['company']['id'] ?? null);
        $harness->assertSame($expectedAccountingPeriodId, $context['company']['accounting_period_id'] ?? null);
        $harness->assertSame($expectedValidSelected, $context['company']['valid_selected'] ?? null);
        $harness->assertTrue(is_array($context['company']['settings'] ?? null));
        if ($expectedCompanyId === 0) {
            $harness->assertSame('', $context['company']['settings']['default_bank_nominal_id'] ?? null);
        }
        $harness->assertSame(true, $context['module_flag'] ?? null);
        $harness->assertSame('test_module', $context['base_page_id'] ?? null);
    });

    $harness->check(PageContextFramework::class, 'does not treat action query values as site context selection', function () use ($harness, $page): void {
        resetTestPageContextSelection();
        $_GET = ['page' => 'test-module'];
        $_POST = [];
        $_REQUEST = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $request = RequestFramework::fromGlobals();
        $services = createTestPageServiceFramework();
        [$expectedCompanyId, $expectedAccountingPeriodId] = expectedTestPageContextSelection();

        $context = $page->exposedBuildContext(
            $request,
            $services,
            ActionResultFramework::success([], [], [], [
                'company_id' => 22,
                'accounting_period_id' => 33,
            ])
        );

        $harness->assertSame($expectedCompanyId, $context['company']['id'] ?? null);
        $harness->assertSame($expectedAccountingPeriodId, $context['company']['accounting_period_id'] ?? null);
    });

    $harness->check(PageContextFramework::class, 'ignores stale legacy session company values', function () use ($harness, $page): void {
        resetTestPageContextSelection();
        $_GET = ['page' => 'test-module'];
        $_POST = [];
        $_REQUEST = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $_SESSION['app.company_id'] = 22;

        $request = RequestFramework::fromGlobals();
        $services = createTestPageServiceFramework();
        [$expectedCompanyId] = expectedTestPageContextSelection();

        $context = $page->exposedBuildContext($request, $services, ActionResultFramework::none());

        $harness->assertSame($expectedCompanyId, $context['company']['id'] ?? null);
    });
});
