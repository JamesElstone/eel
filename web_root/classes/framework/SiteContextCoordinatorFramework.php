<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SiteContextCoordinatorFramework
{
    public const ACTION = 'set-site-context';
    public const UI_INVALIDATION_FACT = 'site-context.ui';

    private SiteContextProviderInterface $provider;
    private SiteContextRendererFramework $renderer;
    private ?SiteContextResultFramework $lastResult = null;

    public function __construct(
        ?SiteContextProviderInterface $provider = null,
        private readonly bool $enabled = false,
        ?SiteContextRendererFramework $renderer = null,
    ) {
        $this->provider = $provider ?? new NullSiteContextProviderFramework();
        $this->renderer = $renderer ?? new SiteContextRendererFramework();
    }

    public static function fromConfiguration(AppService $appServices): self
    {
        $serviceClass = ltrim(trim((string)AppConfigurationStore::get('site_context.service', '')), '\\');

        if ($serviceClass === '') {
            return new self(new NullSiteContextProviderFramework(), false);
        }

        $provider = $appServices->get($serviceClass);
        if (!$provider instanceof SiteContextProviderInterface) {
            throw new RuntimeException('Configured site context service must implement SiteContextProviderInterface: ' . $serviceClass);
        }

        return new self($provider, true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function handleAction(
        RequestFramework $request,
        PageInterfaceFramework $page,
        PageServiceFramework $services
    ): ?ActionResultFramework {
        if (!$this->enabled || $request->action() !== self::ACTION) {
            return null;
        }

        $result = $this->provider->handleSiteContextAction($request, $page, $services);
        $changedFacts = $result->changedFacts();

        if ($result->isSuccess()) {
            $changedFacts[] = 'page.reload';
            $changedFacts[] = self::UI_INVALIDATION_FACT;
        }

        return new ActionResultFramework(
            $result->isSuccess(),
            $changedFacts,
            $result->flashMessages(),
            $result->query(),
            $result->context()
        );
    }

    public function injectContext(
        RequestFramework $request,
        PageInterfaceFramework $page,
        PageServiceFramework $services,
        array $context
    ): array {
        $result = $this->resolve($request, $page, $services, $context);

        if ($result->context() === []) {
            return $context;
        }

        return array_replace_recursive($context, $result->context());
    }

    public function resolve(
        RequestFramework $request,
        PageInterfaceFramework $page,
        PageServiceFramework $services,
        array $context
    ): SiteContextResultFramework {
        if (!$this->enabled) {
            return $this->lastResult = SiteContextResultFramework::none();
        }

        return $this->lastResult = $this->provider->resolveSiteContext($request, $page, $services, $context);
    }

    public function renderSlotHtml(
        PageInterfaceFramework $page,
        RequestFramework $request,
        array $context
    ): array {
        return $this->renderer->renderSlots(
            $this->lastResult ?? SiteContextResultFramework::none(),
            $page,
            $request,
            $context,
            $this->hiddenSelectorKeys($page)
        );
    }

    public function shouldRenderAjaxSlots(array $changedFacts): bool
    {
        return $this->enabled && in_array(self::UI_INVALIDATION_FACT, $changedFacts, true);
    }

    private function hiddenSelectorKeys(PageInterfaceFramework $page): array
    {
        if (!method_exists($page, 'hiddenSiteContextSelectors')) {
            return [];
        }

        $hiddenKeys = $page->hiddenSiteContextSelectors();
        if (!is_array($hiddenKeys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $key): string => trim((string)$key),
            $hiddenKeys
        ), static fn(string $key): bool => $key !== '')));
    }
}
