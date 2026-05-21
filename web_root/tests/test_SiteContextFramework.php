<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class SiteContextTestDependency
{
    public function marker(): string
    {
        return 'resolved-through-app-service';
    }
}

final class SiteContextFakeProvider implements SiteContextProviderInterface
{
    public static array $handledActions = [];

    public function __construct(private readonly SiteContextTestDependency $dependency)
    {
    }

    public function resolveSiteContext(
        RequestFramework $request,
        PageInterfaceFramework $page,
        PageServiceFramework $services,
        array $pageContext
    ): SiteContextResultFramework {
        return new SiteContextResultFramework(
            [
                'site_context' => [
                    'workspace_id' => 321,
                    'dependency_marker' => $this->dependency->marker(),
                ],
                'workspace' => [
                    'id' => 321,
                    'name' => 'Example Workspace',
                ],
            ],
            [
                [
                    'key' => 'workspace_id',
                    'slot' => 'sidebar',
                    'label' => 'Workspace',
                    'value' => '321',
                    'options' => [
                        ['value' => '321', 'label' => 'Example Workspace', 'short_label' => 'Example'],
                    ],
                    'disabled' => false,
                    'visible' => true,
                ],
                [
                    'key' => 'reporting_window',
                    'slot' => 'topbar',
                    'label' => 'Reporting Window',
                    'value' => 'current',
                    'options' => [
                        ['value' => 'current', 'label' => 'Current Window'],
                        ['value' => 'archive', 'label' => 'Archive Window'],
                    ],
                    'disabled' => false,
                    'visible' => true,
                ],
                [
                    'key' => 'display_scope',
                    'slot' => 'summary',
                    'label' => 'Display Scope',
                    'value' => 'team',
                    'options' => [
                        ['value' => 'team', 'label' => 'Team'],
                        ['value' => 'personal', 'label' => 'Personal'],
                    ],
                    'disabled' => false,
                    'visible' => true,
                ],
            ]
        );
    }

    public function handleSiteContextAction(
        RequestFramework $request,
        PageInterfaceFramework $page,
        PageServiceFramework $services
    ): ActionResultFramework {
        self::$handledActions[] = [
            'key' => (string)$request->input('site_context_key', ''),
            'value' => (string)$request->input('site_context_value', ''),
            'page' => $page->id(),
        ];

        return ActionResultFramework::success([], [
            [
                'type' => 'success',
                'message' => 'Context updated.',
            ],
        ]);
    }
}

final class SiteContextTestProbeService
{
    public function lookup(int $workspaceId): array
    {
        return ['workspace_id' => $workspaceId];
    }
}

if (!class_exists('_site_context_probeCard', false)) {
    final class _site_context_probeCard extends CardBaseFramework
    {
        public function services(): array
        {
            return [
                [
                    'key' => 'probe',
                    'service' => SiteContextTestProbeService::class,
                    'method' => 'lookup',
                    'params' => [
                        'workspaceId' => ':site_context.workspace_id',
                    ],
                ],
            ];
        }

        public function render(array $context): string
        {
            return '<p>Probe</p>';
        }
    }
}

final class SiteContextTestPage implements PageInterfaceFramework
{
    public function __construct(private readonly array $hiddenSelectors = [])
    {
    }

    public function id(): string { return 'site_context_test'; }
    public function title(): string { return 'Site Context Test'; }
    public function subtitle(): string { return 'Generic context'; }
    public function pageStackClass(): string { return ''; }
    public function services(): array { return []; }
    public function cards(): array { return ['site_context_probe']; }

    public function hiddenSiteContextSelectors(): array
    {
        return $this->hiddenSelectors;
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ResponseFramework
    {
        return ResponseFramework::html('');
    }
}

$harness = new GeneratedServiceClassTestHarness();

$setSiteContextTestConfig = static function (?string $providerClass): void {
    $config = AppConfigurationStore::config(true);
    $config['site_context'] = [
        'service' => $providerClass ?? '',
    ];

    $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
    $property->setAccessible(true);
    $property->setValue(null, $config);
};

$createSiteContextTestServices = static function (): PageServiceFramework {
    return new PageServiceFramework(new AppService(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp'));
};

$createSiteContextTestRequest = static function (array $post = [], array $server = []): RequestFramework {
    return new RequestFramework(
        ['page' => 'site_context_test'],
        $post,
        array_merge(['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST'], $server),
        [],
        [],
    );
};

$renderSiteContextTestFull = static function (
    SiteContextTestPage $page,
    RequestFramework $request,
    array $context,
    PageServiceFramework $services
): string {
    $renderer = new PageRendererFramework(new CardRendererFramework(new CardFactoryFramework()));

    return $renderer->renderFull($page, $request, $context, ActionResultFramework::none(), $services)->body();
};

try {
    $harness->check(SiteContextCoordinatorFramework::class, 'keeps no-provider renders free of selectors', function () use ($harness, $setSiteContextTestConfig, $createSiteContextTestServices, $createSiteContextTestRequest, $renderSiteContextTestFull): void {
        $setSiteContextTestConfig(null);
        $services = $createSiteContextTestServices();
        $page = new SiteContextTestPage();
        $context = [
            'page' => [
                'page_id' => $page->id(),
                'page_cards' => [],
            ],
        ];

        $html = $renderSiteContextTestFull($page, $createSiteContextTestRequest(), $context, $services);

        $harness->assertTrue(str_contains($html, 'id="site-context-sidebar-slot"'));
        $harness->assertTrue(str_contains($html, 'id="site-context-topbar-slot"'));
        $harness->assertSame(false, str_contains($html, 'name="site_context_value"'));
        $harness->assertSame(false, str_contains($html, 'site-context-selector-form'));
    });

    $harness->check(SiteContextCoordinatorFramework::class, 'injects provider context before card service params are resolved', function () use ($harness, $setSiteContextTestConfig, $createSiteContextTestServices, $createSiteContextTestRequest): void {
        $setSiteContextTestConfig(SiteContextFakeProvider::class);
        $services = $createSiteContextTestServices();
        $page = new SiteContextTestPage();
        $context = $services->siteContextCoordinator()->injectContext(
            $createSiteContextTestRequest(),
            $page,
            $services,
            [
                'page' => [
                    'page_id' => $page->id(),
                    'page_cards' => ['site_context_probe'],
                ],
            ]
        );

        $harness->assertSame(321, $context['site_context']['workspace_id'] ?? null);
        $harness->assertSame('resolved-through-app-service', $context['site_context']['dependency_marker'] ?? null);

        $cardContext = (new CardRendererFramework(new CardFactoryFramework()))->buildContextForCard(
            new _site_context_probeCard(),
            $context,
            $services
        );

        $harness->assertSame(['workspace_id' => 321], $cardContext['services']['probe'] ?? null);
    });

    $harness->check(SiteContextRendererFramework::class, 'renders sidebar and topbar selector slots from structured selectors', function () use ($harness, $setSiteContextTestConfig, $createSiteContextTestServices, $createSiteContextTestRequest, $renderSiteContextTestFull): void {
        $setSiteContextTestConfig(SiteContextFakeProvider::class);
        $services = $createSiteContextTestServices();
        $page = new SiteContextTestPage();
        $request = $createSiteContextTestRequest();
        $context = $services->siteContextCoordinator()->injectContext($request, $page, $services, [
            'page' => [
                'page_id' => $page->id(),
                'page_cards' => [],
            ],
        ]);

        $html = $renderSiteContextTestFull($page, $request, $context, $services);

        $harness->assertTrue(str_contains($html, 'id="site-context-sidebar-slot"'));
        $harness->assertTrue(str_contains($html, 'Workspace'));
        $harness->assertTrue(str_contains($html, 'selector-input sidebar-select'));
        $harness->assertTrue(str_contains($html, 'id="site-context-topbar-slot"'));
        $harness->assertTrue(str_contains($html, 'Reporting Window'));
        $harness->assertTrue(str_contains($html, 'id="site-context-summary-slot"'));
        $harness->assertTrue(str_contains($html, 'Display Scope'));
        $harness->assertTrue(str_contains($html, 'name="action" value="set-site-context"'));
    });

    $harness->check(SiteContextRendererFramework::class, 'hides only selectors named by the page', function () use ($harness, $setSiteContextTestConfig, $createSiteContextTestServices, $createSiteContextTestRequest, $renderSiteContextTestFull): void {
        $setSiteContextTestConfig(SiteContextFakeProvider::class);
        $services = $createSiteContextTestServices();
        $page = new SiteContextTestPage(['reporting_window']);
        $request = $createSiteContextTestRequest();
        $context = $services->siteContextCoordinator()->injectContext($request, $page, $services, [
            'page' => [
                'page_id' => $page->id(),
                'page_cards' => [],
            ],
        ]);

        $html = $renderSiteContextTestFull($page, $request, $context, $services);

        $harness->assertTrue(str_contains($html, 'Workspace'));
        $harness->assertSame(false, str_contains($html, 'Reporting Window'));
    });

    $harness->check(SiteContextCoordinatorFramework::class, 'handles the generic set-site-context action', function () use ($harness, $setSiteContextTestConfig, $createSiteContextTestServices, $createSiteContextTestRequest): void {
        SiteContextFakeProvider::$handledActions = [];
        $setSiteContextTestConfig(SiteContextFakeProvider::class);
        $services = $createSiteContextTestServices();
        $page = new SiteContextTestPage();
        $request = $createSiteContextTestRequest([
            'action' => SiteContextCoordinatorFramework::ACTION,
            'site_context_key' => 'workspace_id',
            'site_context_value' => '654',
        ]);

        $result = $services->siteContextCoordinator()->handleAction($request, $page, $services);

        $harness->assertTrue($result instanceof ActionResultFramework);
        $harness->assertSame([
            [
                'key' => 'workspace_id',
                'value' => '654',
                'page' => 'site_context_test',
            ],
        ], SiteContextFakeProvider::$handledActions);
        $harness->assertTrue(in_array('page.reload', $result->changedFacts(), true));
        $harness->assertTrue(in_array(SiteContextCoordinatorFramework::UI_INVALIDATION_FACT, $result->changedFacts(), true));
    });

    $harness->check(PageRendererFramework::class, 'includes site context slot html in AJAX deltas', function () use ($harness, $setSiteContextTestConfig, $createSiteContextTestServices, $createSiteContextTestRequest): void {
        $setSiteContextTestConfig(SiteContextFakeProvider::class);
        $services = $createSiteContextTestServices();
        $page = new SiteContextTestPage();
        $request = $createSiteContextTestRequest(
            [
                '_ajax' => '1',
                'action' => SiteContextCoordinatorFramework::ACTION,
            ],
            [
                'HTTP_ACCEPT' => 'application/json',
            ]
        );
        $context = $services->siteContextCoordinator()->injectContext($request, $page, $services, [
            'page' => [
                'page_id' => $page->id(),
                'page_cards' => [],
            ],
        ]);

        $response = (new PageRendererFramework(new CardRendererFramework(new CardFactoryFramework())))->renderDelta(
            $page,
            $request,
            $context,
            ActionResultFramework::success([SiteContextCoordinatorFramework::UI_INVALIDATION_FACT]),
            $services
        );
        $payload = json_decode($response->body(), true);

        $harness->assertTrue(is_array($payload));
        $harness->assertTrue(str_contains((string)($payload['site_context_html']['sidebar'] ?? ''), 'Workspace'));
        $harness->assertTrue(str_contains((string)($payload['site_context_html']['topbar'] ?? ''), 'Reporting Window'));
        $harness->assertTrue(str_contains((string)($payload['site_context_html']['summary'] ?? ''), 'Display Scope'));
    });

    $harness->check(SiteContextRendererFramework::class, 'frontend submits rendered selectors with every form and AJAX request', function () use ($harness): void {
        $script = file_get_contents(APP_JS . 'index.js');

        if (!is_string($script)) {
            throw new RuntimeException('Unable to read frontend script.');
        }

        $harness->assertTrue(str_contains($script, 'collectSiteContextSelections'));
        $harness->assertTrue(str_contains($script, 'ajaxOptionsWithSiteContext(options)'));
        $harness->assertTrue(str_contains($script, 'syncSiteContextFieldsToForm(form)'));
        $harness->assertTrue(str_contains($script, 'appendSiteContextSelectionsToFormData(formData)'));
        $harness->assertTrue(str_contains($script, 'appendSiteContextSelectionsToPayload(payload)'));
        $harness->assertTrue(str_contains($script, "site_context_keys[]"));
        $harness->assertTrue(str_contains($script, "site_context_values[]"));
        $harness->assertTrue(str_contains($script, 'payload.site_context_keys'));
        $harness->assertTrue(str_contains($script, 'payload.site_context_values'));
    });
} finally {
    AppConfigurationStore::config(true);
}
