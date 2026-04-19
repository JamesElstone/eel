<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

abstract class BasePageFramework implements PageInterfaceFramework
{
    public function showsTaxYearSelector(): bool
    {
        return true;
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ResponseFramework
    {
        $actionDispatcher = new ActionDispatcherFramework();
        $actionResult = $actionDispatcher->dispatch(
            $request,
            $services,
            fn(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
                => $this->dispatchPageAction($request, $services)
        );

        $pageContext = $this->buildContext($request, $services, $actionResult);
        $selectorContext = $services->get(CompanyStore::class)->buildSelectorContext($request, $this);
        $context = array_merge($pageContext, [
            'selected_company_id' => $selectorContext['selected_company_id'] ?? 0,
            'selected_tax_year_id' => $selectorContext['selected_tax_year_id'] ?? 0,
            'show_tax_year_selector' => $selectorContext['show_tax_year_selector'] ?? true,
        ]);
        $context['page_cards'] = $this->allowedPageCards($context, $services);
        $context['cards_dom_ids'] = array_map(
            fn(string $cardKey): string => HelperFramework::cardDomId($this->id(), $cardKey),
            $context['page_cards']
        );
        $renderer = new PageRendererFramework(
            new CardRendererFramework(new CardFactoryFramework())
        );

        if ($request->isAjax()) {
            return $renderer->renderDelta($this, $request, $context, $actionResult, $services);
        }

        return $renderer->renderFull($this, $request, $context, $actionResult, $services);
    }

    protected function handlePageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework
    {
        return ActionResultFramework::none();
    }

    private function dispatchPageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework
    {
        if ($request->action() === 'set-page-context') {
            return ActionResultFramework::success(
                ['page.context', 'page.selector_ui'],
                [],
                [
                    'company_id' => $request->companyId() > 0 ? $request->companyId() : null,
                    'tax_year_id' => $request->taxYearId() > 0 ? $request->taxYearId() : null,
                ]
            );
        }

        return $this->handlePageAction($request, $services);
    }

    abstract protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array;

    protected function currentUserId(): int
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }

    private function allowedPageCards(array $context, PageServiceFramework $services): array
    {
        $requestedCards = $context['page_cards'] ?? $this->cards();
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
}
