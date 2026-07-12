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

    public function ajaxPendingBlurScope(): string
    {
        return 'none';
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

        $context['auth'] = $this->authContext();
        $context['page']['csrf_token'] = (new SessionAuthenticationService())->csrfToken();

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

    protected function currentUserRoleId(int $userId): int
    {
        return (new CardAccessFramework())->roleIdForUser($userId);
    }

    private function authContext(): array
    {
        $userId = $this->currentUserId();

        return [
            'user_id' => $userId,
            'role_id' => $userId > 0 ? $this->currentUserRoleId($userId) : 0,
        ];
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

        if ($requestedCards === []) {
            return [];
        }

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

        $cardsToHandle = (array)$context['page']['page_cards'];
        if ((string)$request->input('_on_demand_cards', '') === '1') {
            $cardsToHandle = array_values(array_intersect(
                $cardsToHandle,
                $request->cardKeys(),
                $this->declaredOnDemandCardKeys()
            ));
        } elseif (!$request->isAjax() && method_exists($this, 'cardLayout')) {
            $cardsToHandle = $this->initiallyRenderedCardKeys($request, $actionResult, $cardsToHandle);
        }

        foreach ($cardsToHandle as $cardKey) {
            $card = $cardFactory->create((string)$cardKey);
            $updatedContext = $card->handle($request, $services, $context, $actionResult);

            if (is_array($updatedContext)) {
                $context = $updatedContext;
            }
        }

        return $context;
    }

    private function initiallyRenderedCardKeys(
        RequestFramework $request,
        ActionResultFramework $actionResult,
        array $allowedCards
    ): array {
        $layout = array_values(array_filter((array)$this->cardLayout(), 'is_array'));
        if ($layout === []) {
            return $allowedCards;
        }

        $placedCards = [];
        foreach ($layout as $entry) {
            $placedCards = array_merge($placedCards, array_map('strval', (array)($entry['cards'] ?? [])));
        }
        $layout[0]['cards'] = array_values(array_merge(
            array_map('strval', (array)($layout[0]['cards'] ?? [])),
            array_diff($allowedCards, $placedCards)
        ));

        $showCard = trim((string)($actionResult->query()['show_card'] ?? $request->input('show_card', '')));
        $selectedIndex = 0;
        if ($showCard !== '') {
            foreach ($layout as $index => $entry) {
                if (in_array($showCard, array_map('strval', (array)($entry['cards'] ?? [])), true)) {
                    $selectedIndex = $index;
                    break;
                }
            }
        }

        $handled = [];
        foreach ($layout as $index => $entry) {
            if (($entry['on_demand'] ?? false) === true && $index !== $selectedIndex) {
                continue;
            }
            $handled = array_merge($handled, array_map('strval', (array)($entry['cards'] ?? [])));
        }

        return array_values(array_intersect($allowedCards, array_unique($handled)));
    }

    private function declaredOnDemandCardKeys(): array
    {
        if (!method_exists($this, 'cardLayout')) {
            return [];
        }

        $cards = [];
        foreach ((array)$this->cardLayout() as $entry) {
            if (!is_array($entry) || ($entry['on_demand'] ?? false) !== true) {
                continue;
            }
            $cards = array_merge($cards, array_map('strval', (array)($entry['cards'] ?? [])));
        }

        return array_values(array_unique($cards));
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
