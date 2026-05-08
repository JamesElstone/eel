<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

abstract class PageBaseFramework implements PageInterfaceFramework
{
    public function pageStackClass(): string
    {
        return '';
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ResponseFramework
    {
        $actionDispatcher = new ActionDispatcherFramework();
        $actionResult = $actionDispatcher->dispatch(
            $request,
            $services,
            fn(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
                => $this->handlePageAction($request, $services)
        );

        $pageContext = $this->buildContext($request, $services, $actionResult);

        // Build page context
        $concatenatedContext = array_merge(
            $pageContext,
            $actionResult->context()
        );

        $context = $concatenatedContext;

        $context['page']['page_cards'] = $this->allowedPageCards($context, $services);
        
        $context['page']['cards_dom_ids'] = array_map(
            fn(string $cardKey): string => HelperFramework::cardDomId($this->id(), $cardKey),
            $context['page']['page_cards']
        );

        $context = $this->handleCards($request, $services, $context, $actionResult);
        $renderer = new PageRendererFramework(
            new CardRendererFramework(new CardFactoryFramework())
        );

        if ($request->isAjax()) {

            return $renderer->renderDelta($this, $request, $context, $actionResult, $services);

        }

        return $renderer->renderFull($this, $request, $context, $actionResult, $services);
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

        return $cards;
    }
}
