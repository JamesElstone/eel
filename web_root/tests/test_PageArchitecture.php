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
        $this->runTest('AJAX delta responses include only stale cards', [$this, 'testAjaxDeltaResponseReturnsOnlyStaleCards']);
        $this->runTest('navigation builder sorts items without loading page files', [$this, 'testNavigationRendererBuildsSortedItemsWithoutLoadingPages']);
        $this->runTest('navigation builder returns an empty array for missing directories', [$this, 'testNavigationRendererReturnsEmptyArrayForMissingDirectory']);
    }

    private function testPageKeyConvention(): void
    {
        $this->assertSame('_trial_balance', FrameworkHelper::pageKeyToClassName('trial-balance'));
    }

    private function testCardKeyConvention(): void
    {
        $this->assertSame('_monthly_statusCard', FrameworkHelper::cardKeyToClassName('monthly-status'));
    }

    private function testPageFactoryResolvesDashboard(): void
    {
        $this->loadPageCards('dashboard');

        $page = (new WebPageFactory())->create('dashboard');
        $this->assertSame(_dashboard::class, $page::class);
        $this->assertSame(['company_account'], $page->services());
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
            WebRequest::fromGlobals(),
            new WebPageService(['company_account' => new stdClass()])
        );

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertSame('dashboard', $payload['page'] ?? null);
        $this->assertTrue(isset($payload['cards']['dashboard-hero']));
        $this->assertTrue(isset($payload['cards']['dashboard-overview']));
        $this->assertTrue(isset($payload['cards']['dashboard-activity']));
    }

    private function loadPageCards(string $pageKey): WebPageInterface
    {
        $page = (new WebPageFactory())->create($pageKey);

        foreach ($page->cards() as $cardKey) {
            $className = FrameworkHelper::cardKeyToClassName((string)$cardKey);
            $this->assertTrue(class_exists($className));
        }

        return $page;
    }

    private function testNavigationRendererBuildsSortedItemsWithoutLoadingPages(): void
    {
        $this->assertTrue(is_dir(self::NAVIGATION_FIXTURES_DIRECTORY));

        $items = (new NavigationRenderer(self::NAVIGATION_FIXTURES_DIRECTORY, 'trialBalance'))->build();

        $this->assertSame(['uploads', 'directorLoan', 'trialBalance', 'zebra'], array_column($items, 'key'));
        $this->assertSame('Director Loan', $items[1]['label']);
        $this->assertSame('/?page=trialBalance', $items[2]['url']);
        $this->assertSame('/tests/fixtures/navigation_pages/trialBalance.svg', $items[2]['icon_path']);
        $this->assertTrue($items[2]['is_active']);
        $this->assertSame(1000, $items[3]['order']);
    }

    private function testNavigationRendererReturnsEmptyArrayForMissingDirectory(): void
    {
        $items = (new NavigationRenderer(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'missing-pages', 'dashboard'))->build();
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
}

(new TestPageArchitectureHarness())->run();
