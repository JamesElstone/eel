<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

abstract class PageBaseFramework implements PageInterfaceFramework
{
    public function cards(): array
    {
        return [];
    }

    public function pageStackClass(): string
    {
        return '';
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ResponseFramework
    {
        $actionResult = $services->siteContextCoordinator()->handleAction($request, $this, $services);
        if (!$actionResult instanceof ActionResultFramework) {
            $actionDispatcher = new ActionDispatcherFramework();
            $actionResult = $actionDispatcher->dispatch(
                $request,
                $services,
                fn(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
                    => $this->handlePageAction($request, $services)
            );
        }
        $this->recordFlashActivity($request, $actionResult);

        $context = $this->buildContextForRequest($request, $services, $actionResult);
        $cardRenderer = new CardRendererFramework(new CardFactoryFramework());

        $exportResponse = (new TableExportFramework())->handle($this, $request, $context, $services, $cardRenderer);
        if ($exportResponse instanceof ResponseFramework) {
            return $exportResponse;
        }

        $renderer = new PageRendererFramework($cardRenderer);

        if ($request->isAjax()) {

            return $renderer->renderDelta($this, $request, $context, $actionResult, $services);

        }

        return $renderer->renderFull($this, $request, $context, $actionResult, $services);
    }

    public function buildContextForRequest(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = $this->buildContext($request, $services, $actionResult);

        $context = array_merge(
            $pageContext,
            $actionResult->context()
        );

        $context = $services->siteContextCoordinator()->injectContext($request, $this, $services, $context);

        $context['page']['page_cards'] = $this->allowedPageCards($context, $services);

        $context['page']['cards_dom_ids'] = array_map(
            fn(string $cardKey): string => HelperFramework::cardDomId($this->id(), $cardKey),
            $context['page']['page_cards']
        );

        return $this->handleCards($request, $services, $context, $actionResult);
    }

    public function allServiceDefinitions(): array
    {
        $definitions = $this->services();

        foreach ($this->requestedCardKeys() as $card) {
            $cardInstance = is_string($card) ? new $card() : $card;

            if (method_exists($cardInstance, 'services')) {
                $definitions = array_merge($definitions, $cardInstance->services());
            }
        }

        return $definitions;
    }

    protected function handlePageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework
    {
        return ActionResultFramework::none();
    }

    protected function currentUserId(): int
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }

    private function recordFlashActivity(RequestFramework $request, ActionResultFramework $actionResult): void
    {
        if ($actionResult->flashMessages() === []) {
            return;
        }

        try {
            (new ActivityStore())->recordFlashMessages($this->id(), $request, $actionResult, $this->currentUserId());
        } catch (Throwable $exception) {
            error_log('Unable to record flash activity: ' . $exception->getMessage());
        }
    }

    private function allowedPageCards(array $context, PageServiceFramework $services): array
    {
        $requestedCards = method_exists($this, 'cardLayout')
            ? $this->requestedCardKeys()
            : ($context['page']['page_cards'] ?? $this->cards());
        $requestedCards = array_values(array_map(
            static fn(mixed $cardKey): string => (string)$cardKey,
            is_array($requestedCards) ? $requestedCards : []
        ));

        $currentUserId = $this->currentUserId();
        if ($currentUserId <= 0) {
            return [];
        }

        $cardAccess = new CardAccessFramework();
        $allowedCards = $cardAccess->allowedCardsForUser($currentUserId, $requestedCards);

        return $allowedCards === [] ? [] : $allowedCards;
    }

    private function handleCards(
        RequestFramework $request,
        PageServiceFramework $services,
        array $context,
        ActionResultFramework $actionResult
    ): array {
        if (($context['page']['page_cards'] ?? []) === []) {
            return $context;
        }

        $cardFactory = new CardFactoryFramework();

        foreach ((array)$context['page']['page_cards'] as $cardKey) {
            $card = $cardFactory->create((string)$cardKey);
            $updatedContext = $card->handle($request, $services, $context, $actionResult);

            if (is_array($updatedContext)) {
                $context = $updatedContext;
            }
        }

        return $context;
    }

    private function requestedCardKeys(): array
    {
        if (!method_exists($this, 'cardLayout')) {
            return $this->cards();
        }

        $cards = [];
        foreach ((array)$this->cardLayout() as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach ((array)($entry['cards'] ?? []) as $cardKey) {
                $cards[] = (string)$cardKey;
            }
        }

        foreach ($this->cards() as $cardKey) {
            $cards[] = (string)$cardKey;
        }

        return array_values(array_unique($cards));
    }
}
