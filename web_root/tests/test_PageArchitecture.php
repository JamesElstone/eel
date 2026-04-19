<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class TestPageArchitectureHarness
{
    private const NAVIGATION_FIXTURES_DIRECTORY =
        APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'navigation_pages';

    public function run(): void
    {
        $this->runTest('page keys map to the expected page class name', [$this, 'testPageKeyConvention']);
        $this->runTest('card keys map to the expected card class name', [$this, 'testCardKeyConvention']);
        $this->runTest('page factory resolves the dashboard page', [$this, 'testPageFactoryResolvesDashboard']);
        $this->runTest('logs page resolves all configured log cards', [$this, 'testLogsPageResolvesAllCards']);
        $this->runTest('assets page resolves all configured asset cards', [$this, 'testAssetsPageResolvesAllCards']);
        $this->runTest('AJAX delta responses include only stale cards', [$this, 'testAjaxDeltaResponseReturnsOnlyStaleCards']);
        $this->runTest('selector ajax responses include compact selector UI data', [$this, 'testSelectorAjaxResponseIncludesSelectorUi']);
        $this->runTest('selector ui is excluded from shared card context', [$this, 'testSelectorUiIsNotSharedInCardContext']);
        $this->runTest('card ajax responses omit selector UI data when it is unchanged', [$this, 'testCardAjaxResponseOmitsSelectorUiWhenUnchanged']);
        $this->runTest('selector changes invalidate all current page cards', [$this, 'testSelectorChangesInvalidateAllCurrentPageCards']);
        $this->runTest('test page shares context across cards during AJAX updates', [$this, 'testTestPageSharedContextAcrossCards']);
        $this->runTest('navigation builder sorts items without loading page files', [$this, 'testNavigationFrameworkBuildsSortedItemsWithoutLoadingPages']);
        $this->runTest('navigation builder resolves shared svg icons from display labels', [$this, 'testNavigationFrameworkResolvesSharedSvgIcons']);
        $this->runTest('navigation builder returns an empty array for missing directories', [$this, 'testNavigationFrameworkReturnsEmptyArrayForMissingDirectory']);
    }

    private function testPageKeyConvention(): void
    {
        $this->assertSame('_trial_balance', HelperFramework::pageKeyToClassName('trial-balance'));
    }

    private function testCardKeyConvention(): void
    {
        $this->assertSame('_monthly_statusCard', HelperFramework::cardKeyToClassName('monthly-status'));
    }

    private function testPageFactoryResolvesDashboard(): void
    {
        $this->loadPageCards('dashboard');

        $page = (new PageFactoryFramework())->create('dashboard');
        $this->assertSame(_dashboard::class, $page::class);
        $this->assertSame([CompanyAccountService::class], $page->services());
    }

    private function testLogsPageResolvesAllCards(): void
    {
        $page = $this->loadPageCards('logs');

        $this->assertSame(
            [
                'user_account_audit_log',
                'user_logon_history_log',
                'transaction_category_audit_log',
                'year_end_audit_log',
            ],
            $page->cards()
        );
    }

    private function testAssetsPageResolvesAllCards(): void
    {
        $page = $this->loadPageCards('assets');

        $this->assertSame(
            [
                'asset_create',
                'asset_register',
                'asset_tax',
            ],
            $page->cards()
        );
    }

    private function testAjaxDeltaResponseReturnsOnlyStaleCards(): void
    {
        $page = $this->loadPageCards('dashboard');

        $_GET = ['page' => 'dashboard', 'focus' => 'cards'];
        $_POST = [
            'action' => 'set-focus',
            'focus' => 'ajax',
            'cards' => $page->cards(),
            '_ajax' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $response = $page->handle(
            RequestFramework::fromGlobals(),
            new PageServiceFramework($this->testPageServices())
        );

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertSame('dashboard', $payload['page'] ?? null);
        $this->assertSame([], $payload['cards'] ?? null);
    }

    private function testSelectorAjaxResponseIncludesSelectorUi(): void
    {
        $page = $this->loadPageCards('dashboard');

        $_GET = ['page' => 'dashboard'];
        $_POST = [
            'action' => 'set-page-context',
            'company_id' => '0',
            'tax_year_id' => '',
            'cards' => $page->cards(),
            '_ajax' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $response = $page->handle(
            RequestFramework::fromGlobals(),
            new PageServiceFramework($this->testPageServices())
        );

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertTrue(isset($payload['selector_ui']['companies']));
        $this->assertTrue(isset($payload['selector_ui']['tax_years']));
        $this->assertTrue(isset($payload['selector_ui']['selected_company_id']));
        $this->assertTrue(isset($payload['selector_ui']['selected_tax_year_id']));
        $this->assertTrue(is_array($payload['selector_ui']['companies']));
        $this->assertTrue(is_array($payload['selector_ui']['tax_years']));
    }

    private function testSelectorUiIsNotSharedInCardContext(): void
    {
        $page = $this->loadPageCards('test');

        $_GET = ['page' => 'test', 'preset' => 'alpha'];
        $_POST = [
            'action' => 'set-test-context',
            'preset' => 'beta',
            'note' => 'Shared note from the source card',
            'cards' => $page->cards(),
            '_ajax' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $response = $page->handle(
            RequestFramework::fromGlobals(),
            new PageServiceFramework($this->testPageServices())
        );

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertNotContains('_selector_ui', (string)($payload['cards']['test-context_dump'] ?? ''));
    }

    private function testCardAjaxResponseOmitsSelectorUiWhenUnchanged(): void
    {
        $page = $this->loadPageCards('test');

        $_GET = ['page' => 'test', 'preset' => 'alpha'];
        $_POST = [
            'action' => 'set-test-context',
            'preset' => 'beta',
            'note' => 'Shared note from the source card',
            'cards' => $page->cards(),
            '_ajax' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $response = $page->handle(
            RequestFramework::fromGlobals(),
            new PageServiceFramework($this->testPageServices())
        );

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertSame(null, $payload['selector_ui'] ?? null);
    }

    private function testSelectorChangesInvalidateAllCurrentPageCards(): void
    {
        $page = $this->loadPageCards('test');

        $_GET = ['page' => 'test'];
        $_POST = [
            'action' => 'set-page-context',
            'company_id' => '22',
            'tax_year_id' => '25',
            'cards' => $page->cards(),
            '_ajax' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $response = $page->handle(
            RequestFramework::fromGlobals(),
            new PageServiceFramework($this->testPageServices())
        );

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertSame([], $payload['cards'] ?? null);
        $this->assertTrue(isset($payload['selector_ui']['companies']));
        $this->assertTrue(isset($payload['selector_ui']['tax_years']));
    }

    private function testTestPageSharedContextAcrossCards(): void
    {
        $page = $this->loadPageCards('test');

        $_GET = ['page' => 'test', 'preset' => 'alpha'];
        $_POST = [
            'action' => 'set-test-context',
            'preset' => 'beta',
            'note' => 'Shared note from the source card',
            'cards' => $page->cards(),
            '_ajax' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $response = $page->handle(
            RequestFramework::fromGlobals(),
            new PageServiceFramework($this->testPageServices())
        );

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertSame('test', $payload['page'] ?? null);
        $this->assertSame([], $payload['cards'] ?? null);
        $this->assertSame(null, $payload['selector_ui'] ?? null);
        $this->assertContains('Shared test context updated.', (string)($payload['flash_html'] ?? ''));
        $this->assertContains('preset=beta', (string)($payload['url'] ?? ''));
        $this->assertContains('note=Shared+note+from+the+source+card', (string)($payload['url'] ?? ''));
    }

    private function loadPageCards(string $pageKey): PageInterfaceFramework
    {
        $page = (new PageFactoryFramework())->create($pageKey);

        foreach ($page->cards() as $cardKey) {
            $className = HelperFramework::cardKeyToClassName((string)$cardKey);
            $this->assertTrue(class_exists($className));
        }

        return $page;
    }

    private function testPageServices(): array
    {
        $companyAccount = new CompanyAccountService();
        $companyStore = new CompanyStore();

        return [
            'company_account' => $companyAccount,
            CompanyAccountService::class => $companyAccount,
            CompanyStore::class => $companyStore,
        ];
    }

    private function testNavigationFrameworkBuildsSortedItemsWithoutLoadingPages(): void
    {
        $this->assertTrue(is_dir(self::NAVIGATION_FIXTURES_DIRECTORY));

        $items = (new NavigationFramework(self::NAVIGATION_FIXTURES_DIRECTORY, 'trialBalance'))->build();

        $this->assertSame(['uploads', 'directorLoan', 'trialBalance', 'zebra'], array_column($items, 'key'));
        $this->assertSame('Director Loan', $items[1]['label']);
        $this->assertSame('/?page=trialBalance', $items[2]['url']);
        $this->assertSame('/tests/fixtures/navigation_pages/trialBalance.svg', $items[2]['icon_path']);
        $this->assertTrue($items[2]['is_active']);
        $this->assertSame(1000, $items[3]['order']);
    }

    private function testNavigationFrameworkResolvesSharedSvgIcons(): void
    {
        $items = (new NavigationFramework(APP_PAGES, 'dashboard'))->build();
        $itemsByKey = [];

        foreach ($items as $item) {
            $itemsByKey[(string)$item['key']] = $item;
        }

        $this->assertSame('/svg/dashboard.svg', $itemsByKey['dashboard']['icon_path'] ?? null);
    }

    private function testNavigationFrameworkReturnsEmptyArrayForMissingDirectory(): void
    {
        $items = (new NavigationFramework(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'missing-pages', 'dashboard'))->build();
        $this->assertSame([], $items);
    }

    private function runTest(string $description, callable $callback): void
    {
        $callback();
        test_output_line('PageArchitecture: ' . $description . '.');
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

    private function assertContains(string $needle, string $haystack): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(
                'Assertion failed. Expected to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true) . '.'
            );
        }
    }

    private function assertNotContains(string $needle, string $haystack): void
    {
        if (str_contains($haystack, $needle)) {
            throw new RuntimeException(
                'Assertion failed. Did not expect to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true) . '.'
            );
        }
    }
}

(new TestPageArchitectureHarness())->run();

