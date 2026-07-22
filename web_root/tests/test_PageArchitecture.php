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
        $this->runTest('assets page groups cards into asset tabs', [$this, 'testAssetsPageCardLayout']);
        $this->runTest('vehicles page resolves the vehicle register card', [$this, 'testVehiclesPageResolvesVehicleRegisterCard']);
        $this->runTest('tax page resolves read-only tax workings cards', [$this, 'testTaxPageResolvesTaxWorkingsCards']);
        $this->runTest('tax artifacts page remains rate and treatment rule workflow', [$this, 'testTaxRatesPageRemainsRateWorkflow']);
        $this->runTest('CT filing mapping maintenance is global and separate from tax workings', [$this, 'testCtFilingMappingsPage']);
        $this->runTest('AJAX delta responses include only stale cards', [$this, 'testAjaxDeltaResponseReturnsOnlyStaleCards']);
        $this->runTest('AJAX delta responses expose a nonce refresh slot', [$this, 'testAjaxDeltaResponseIncludesAjaxNonceField']);
        $this->runTest('selector ajax responses include compact selector UI data', [$this, 'testSelectorAjaxResponseIncludesSelectorUi']);
        $this->runTest('selector ui is excluded from shared card context', [$this, 'testSelectorUiIsNotSharedInCardContext']);
        $this->runTest('card handle hooks can enrich shared page context', [$this, 'testCardHandleHooksEnrichSharedPageContext']);
        $this->runTest('card ajax responses omit selector UI data when it is unchanged', [$this, 'testCardAjaxResponseOmitsSelectorUiWhenUnchanged']);
        $this->runTest('selector changes invalidate all current page cards', [$this, 'testSelectorChangesInvalidateAllCurrentPageCards']);
        $this->runTest('test page shares context across cards during AJAX updates', [$this, 'testTestPageSharedContextAcrossCards']);
        $this->runTest('AJAX actions with no invalidated facts return no cards', [$this, 'testAjaxNoneResultReturnsNoCards']);
        $this->runTest('JSON ajax requests are parsed through RequestFramework', [$this, 'testJsonAjaxRequestParsesCurrentCards']);
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
        $this->assertSame([\eel_accounts\Service\CompanyAccountService::class], $page->services());
    }

    private function testLogsPageResolvesAllCards(): void
    {
        $page = $this->loadPageCards('logs');

        $this->assertSame(
            [
                'activity',
                'signup_token_lockouts',
                'signup_verification_lockouts',
                'user_account_audit_log',
                'user_logon_history_log',
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
                'asset_reconcile_manual',
                'asset_register',
                'not_an_asset',
            ],
            $page->cards()
        );
    }

    private function testAssetsPageCardLayout(): void
    {
        $page = $this->loadPageCards('assets');

        $this->assertSame(
            [
                [
                    'tab' => 'Asset Register',
                    'cards' => [
                        'asset_register',
                    ],
                ],
                [
                    'tab' => 'Manual Assets',
                    'cards' => [
                        'asset_create',
                        'asset_reconcile_manual',
                    ],
                ],
                [
                'tab' => 'Year End Actions',
                    'cards' => [
                        'not_an_asset',
                    ],
                ],
            ],
            $page->cardLayout()
        );
    }

    private function testVehiclesPageResolvesVehicleRegisterCard(): void
    {
        $page = $this->loadPageCards('vehicles');

        $this->assertSame(_vehicles::class, $page::class);
        $this->assertSame('vehicles', $page->id());
        $this->assertSame('Vehicles', $page->title());
        $this->assertSame(['vehicle_register'], $page->cards());
    }

    private function testTaxPageResolvesTaxWorkingsCards(): void
    {
        $page = $this->loadPageCards('corporation_tax');

        $this->assertSame(_corporation_tax::class, $page::class);
        $this->assertSame('corporation_tax', $page->id());
        $this->assertSame('Tax', $page->title());
        $this->assertSame(
            [
                'tax_period_selector',
                'tax_corporation_tax_summary',
                'tax_taxable_profit_bridge',
                'tax_prepayment_treatment',
                'tax_disallowable_add_backs',
                'tax_capital_add_backs',
                'tax_depreciation_add_back',
                'tax_capital_allowances_summary',
                'tax_aia_allocation',
                'tax_main_rate_pool',
                'tax_special_rate_pool',
                'tax_car_co2_treatment',
                'tax_disposals_balancing',
                'tax_losses',
                'tax_rate_bands',
                'tax_warnings',
                'tax_ct_period_facts',
                'corporation_tax_review',
                'year_end_tax_readiness',
            ],
            $page->cards()
        );
        $this->assertSame(
            [
                [
                    'tab' => 'Corporation Tax',
                    'cards' => [
                        'tax_period_selector',
                        'tax_corporation_tax_summary',
                        'tax_taxable_profit_bridge',
                        'tax_prepayment_treatment',
                        'tax_disallowable_add_backs',
                        'tax_capital_add_backs',
                        'tax_depreciation_add_back',
                        'tax_capital_allowances_summary',
                        'tax_aia_allocation',
                        'tax_main_rate_pool',
                        'tax_special_rate_pool',
                        'tax_car_co2_treatment',
                        'tax_disposals_balancing',
                        'tax_losses',
                        'tax_rate_bands',
                        'tax_warnings',
                    ],
                ],
                [
                    'tab' => 'CT Period Facts',
                    'on_demand' => true,
                    'cards' => [
                        'tax_ct_period_facts',
                    ],
                ],
                [
                    'tab' => 'Review',
                    'on_demand' => true,
                    'cards' => [
                        'corporation_tax_review',
                    ],
                ],
                [
                    'tab' => 'Year End Confirmation',
                    'on_demand' => true,
                    'cards' => [
                        'year_end_tax_readiness',
                    ],
                ],
            ],
            $page->cardLayout()
        );
    }

    private function testTaxRatesPageRemainsRateWorkflow(): void
    {
        $page = $this->loadPageCards('tax_artifacts');

        $this->assertSame(_tax_artifacts::class, $page::class);
        $this->assertSame([
            'tax_artifacts_refresh',
            'tax_treatment_rules',
            'tax_rates_ct',
            'tax_rates_ct600_rim',
            'tax_frc_taxonomy',
            'tax_companies_house_accounts_schemas',
            'tax_rates_vat',
            'tax_thresholds_vat',
        ], $page->cards());
    }

    private function testCtFilingMappingsPage(): void
    {
        $page = $this->loadPageCards('filing_mappings');
        $this->assertSame(_filing_mappings::class, $page::class);
        $this->assertSame('Filing Mappings', $page->title());
        $this->assertSame(['company_id', 'accounting_period_id'], $page->hiddenSiteContextSelectors());
        $this->assertSame(['tax_ct600_rim_mappings', 'tax_ct_computation_mappings'], $page->cards());

        $taxPage = $this->loadPageCards('corporation_tax');
        $this->assertTrue(!in_array('tax_ct600_rim_mappings', $taxPage->cards(), true));
        $this->assertTrue(!in_array('tax_ct_computation_mappings', $taxPage->cards(), true));
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
            'action' => 'set-site-context',
            'site_context_key' => 'company_id',
            'site_context_input_name' => 'company_id',
            'company_id' => '0',
            'cards' => $page->cards(),
            '_ajax' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $response = $page->handle(
            RequestFramework::fromGlobals(),
            createTestPageServiceFramework()
        );

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertTrue(isset($payload['site_context_html']['sidebar']));
        $this->assertTrue(isset($payload['site_context_html']['topbar']));
        $this->assertContains('name="company_id"', (string)$payload['site_context_html']['sidebar']);
        $this->assertContains('name="accounting_period_id"', (string)$payload['site_context_html']['topbar']);
    }

    private function testAjaxDeltaResponseIncludesAjaxNonceField(): void
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
        $this->assertTrue(array_key_exists('ajax_nonce', $payload));
        $this->assertSame(null, $payload['ajax_nonce']);
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
        $this->assertNotContains('_selector_ui', (string)($payload['cards']['dump_context'] ?? ''));
    }

    private function testCardHandleHooksEnrichSharedPageContext(): void
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

        $request = RequestFramework::fromGlobals();
        $services = new PageServiceFramework($this->testPageServices());
        $actionResult = ActionResultFramework::success(
            ['test.context'],
            [],
            ['preset' => 'beta', 'note' => 'Shared note from the source card']
        );
        $baseContext = [
            'page' => [
                'page_cards' => ['test_source', 'test_target'],
            ],
            'test.context' => [
                'shared_demo_context' => [
                    'preset' => 'beta',
                    'title' => 'Beta handoff',
                    'status' => 'Needs review',
                    'summary' => 'A second payload proving the consumer card updates from the same shared page context.',
                    'note' => 'Shared note from the source card',
                    'items' => ['Assumptions listed', 'Open questions flagged', 'Review requested'],
                ],
            ],
        ];

        $method = new ReflectionMethod(PageBaseFramework::class, 'handleCards');
        $method->setAccessible(true);
        $handledContext = $method->invoke($page, $request, $services, $baseContext, $actionResult);

        $this->assertTrue(is_array($handledContext));
        $this->assertSame(
            ['test_source'],
            $handledContext['test.context']['shared_demo_context']['handled_by_cards'] ?? []
        );
        $this->assertSame('set-test-context', $handledContext['test.context']['shared_demo_context']['last_action_type'] ?? null);
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
            'action' => 'set-site-context',
            'site_context_key' => 'company_id',
            'site_context_input_name' => 'company_id',
            'company_id' => '0',
            'cards' => $page->cards(),
            '_ajax' => '1',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $response = $page->handle(
            RequestFramework::fromGlobals(),
            createTestPageServiceFramework()
        );

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertSame([], $payload['cards'] ?? null);
        $this->assertTrue(isset($payload['site_context_html']['sidebar']));
        $this->assertTrue(isset($payload['site_context_html']['topbar']));
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

    private function testJsonAjaxRequestParsesCurrentCards(): void
    {
        $page = $this->loadPageCards('dashboard');

        $_GET = ['page' => 'dashboard', 'focus' => 'cards'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $GLOBALS['__request_framework_raw_body'] = json_encode([
            'action' => 'set-focus',
            'focus' => 'ajax',
            'cards' => $page->cards(),
            '_ajax' => '1',
        ]);

        try {
            $response = $page->handle(
                RequestFramework::fromGlobals(),
                new PageServiceFramework($this->testPageServices())
            );
        } finally {
            unset($GLOBALS['__request_framework_raw_body']);
        }

        ob_start();
        $response->send();
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertTrue(is_array($payload));
        $this->assertSame('dashboard', $payload['page'] ?? null);
        $this->assertSame([], $payload['cards'] ?? null);
    }

    private function testAjaxNoneResultReturnsNoCards(): void
    {
        $page = $this->loadPageCards('test');

        $_GET = ['page' => 'test', 'preset' => 'alpha'];
        $_POST = [
            'action' => 'unknown-action',
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
    }

    private function loadPageCards(string $pageKey): PageInterfaceFramework
    {
        $navPageFile = APP_PAGES . $pageKey . '.nav.php';
        if (is_file($navPageFile)) {
            require_once $navPageFile;
        }

        $page = (new PageFactoryFramework())->create($pageKey);

        foreach ($page->cards() as $cardKey) {
            $className = HelperFramework::cardKeyToClassName((string)$cardKey);
            $this->assertTrue(class_exists($className));
        }

        return $page;
    }

    private function testPageServices(): AppService
    {
        return new AppService(testPageServiceUploadBasePath());
    }

    private function testNavigationFrameworkBuildsSortedItemsWithoutLoadingPages(): void
    {
        $this->assertTrue(is_dir(self::NAVIGATION_FIXTURES_DIRECTORY));

        $items = (new NavigationFramework(self::NAVIGATION_FIXTURES_DIRECTORY, 'trialBalance'))->build();

        $this->assertSame(['directorloan', 'trialbalance', 'uploads', 'zebra'], array_column($items, 'key'));
        $this->assertSame('Director Loan', $items[0]['label']);
        $this->assertSame('/?page=trialbalance', $items[1]['url']);
        $this->assertSame('/tests/fixtures/navigation_pages/trialBalance.svg', $items[1]['icon_path']);
        $this->assertTrue($items[1]['is_active']);
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
