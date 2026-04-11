<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

final class PageArchitectureTestHarness
{
    public function run(): void
    {
        $this->testPageKeyConvention();
        $this->testCardKeyConvention();
        $this->testPageFactoryResolvesDashboard();
        $this->testAjaxDeltaResponseReturnsOnlyStaleCards();
        $this->testNavigationBuilderBuildsSortedItemsWithoutLoadingPages();
        $this->testNavigationBuilderReturnsEmptyArrayForMissingDirectory();

        fwrite(STDOUT, "Page architecture tests passed.\n");
    }

    private function testPageKeyConvention(): void
    {
        $this->assertSame('_trial_balance', FrameWorkHelper::pageKeyToClassName('trial-balance'));
    }

    private function testCardKeyConvention(): void
    {
        $this->assertSame('_uploads_monthly_status', FrameWorkHelper::cardKeyToClassName('uploads', 'monthly-status'));
    }

    private function testPageFactoryResolvesDashboard(): void
    {
        $page = (new WebPageFactory())->create('dashboard');
        $this->assertSame(_dashboard::class, $page::class);
        $this->assertSame(['company_account'], $page->services());
    }

    private function testAjaxDeltaResponseReturnsOnlyStaleCards(): void
    {
        $_GET = ['page' => 'dashboard', 'focus' => 'cards'];
        $_POST = [
            'action' => 'set-focus',
            'focus' => 'ajax',
            'cards' => ['hero', 'overview', 'activity'],
            '_ajax' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $page = (new WebPageFactory())->create('dashboard');
        $response = $page->handle(
            WebRequest::fromGlobals(),
            new WebPageServices(['company_account' => new stdClass()])
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

    private function testNavigationBuilderBuildsSortedItemsWithoutLoadingPages(): void
    {
        $pagesDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'navigation_pages';
        $this->recreateDirectory($pagesDirectory);

        file_put_contents($pagesDirectory . DIRECTORY_SEPARATOR . 'uploads.php', "<?php\n");
        file_put_contents($pagesDirectory . DIRECTORY_SEPARATOR . 'trialBalance.php', "<?php\n");
        file_put_contents($pagesDirectory . DIRECTORY_SEPARATOR . 'directorLoan.php', "<?php\n");
        file_put_contents($pagesDirectory . DIRECTORY_SEPARATOR . 'zebra.php', "<?php\n");
        file_put_contents($pagesDirectory . DIRECTORY_SEPARATOR . '_partial.php', "<?php\n");
        file_put_contents($pagesDirectory . DIRECTORY_SEPARATOR . 'settings.nav.php', "<?php\n");
        file_put_contents($pagesDirectory . DIRECTORY_SEPARATOR . 'trialBalance.svg', "<svg></svg>\n");
        mkdir($pagesDirectory . DIRECTORY_SEPARATOR . 'expenses');

        $items = (new NavigationBuilder($pagesDirectory, 'trialBalance'))->build();

        $this->assertSame(['uploads', 'directorLoan', 'trialBalance', 'zebra'], array_column($items, 'key'));
        $this->assertSame('Director Loan', $items[1]['label']);
        $this->assertSame('/?page=trialBalance', $items[2]['url']);
        $this->assertSame('/tests/fixtures/navigation_pages/trialBalance.svg', $items[2]['icon_path']);
        $this->assertTrue($items[2]['is_active']);
        $this->assertSame(1000, $items[3]['order']);
    }

    private function testNavigationBuilderReturnsEmptyArrayForMissingDirectory(): void
    {
        $items = (new NavigationBuilder(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'missing-pages', 'dashboard'))->build();
        $this->assertSame([], $items);
    }

    private function recreateDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            $this->removeDirectory($directory);
        }

        mkdir($directory, 0777, true);
    }

    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
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

(new PageArchitectureTestHarness())->run();
