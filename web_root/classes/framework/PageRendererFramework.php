<?php
declare(strict_types=1);

final class PageRendererFramework
{
    public function __construct(private readonly CardRendererFramework $cards)
    {
    }

    public function renderFull(
        PageInterfaceFramework $page,
        RequestFramework $request,
        array $context,
        ActionResultFramework $actionResult,
        PageServiceFramework $services
    ): ResponseFramework
    {
        $selectorUi = $this->buildSelectorUi($page, $request, $services);
        $cardsHtml = [];
        foreach ($this->pageCards($page, $context) as $cardKey) {
            $cardsHtml[] = $this->cards->render($page->id(), (string)$cardKey, $context, $services);
        }

        $html = $this->renderLayout($page, $request, $context, $selectorUi, implode("\n", $cardsHtml), $actionResult);

        return ResponseFramework::html($html);
    }

    public function renderDelta(
        PageInterfaceFramework $page,
        RequestFramework $request,
        array $context,
        ActionResultFramework $actionResult,
        PageServiceFramework $services
    ): ResponseFramework
    {
        $currentCards = $request->cardKeys();
        if ($currentCards === []) {
            $currentCards = $this->pageCards($page, $context);
        }

        $currentCards = array_values(array_intersect($currentCards, $this->pageCards($page, $context)));

        $cards = [];
        $changedFacts = $actionResult->changedFacts();
        $selectorUi = null;
        $sidebarHtml = null;
        $invalidateAllCards = in_array('page.context', $changedFacts, true);

        foreach ($currentCards as $cardKey) {
            if (!$invalidateAllCards) {
                $facts = $this->cards->cardInvalidationFacts($cardKey);

                if ($changedFacts !== [] && array_intersect($changedFacts, $facts) === []) {
                    continue;
                }
            }

            $cards[HelperFramework::cardDomId($page->id(), $cardKey)] = $this->cards->render($page->id(), $cardKey, $context, $services);
        }

        if (in_array('page.selector_ui', $changedFacts, true)) {
            $selectorUi = $this->buildSelectorUi($page, $request, $services);
        }

        if (in_array('layout.sidebar', $changedFacts, true)) {
            if ($selectorUi === null) {
                $selectorUi = $this->buildSelectorUi($page, $request, $services);
            }

            $sidebarHtml = $this->renderSidebar($page, $context, $selectorUi);
        }

        return ResponseFramework::json([
            'success' => $actionResult->isSuccess(),
            'page' => $page->id(),
            'cards' => $cards,
            'selector_ui' => $selectorUi === null ? null : $this->buildSelectorDeltaPayload($page, $context, $selectorUi),
            'sidebar_html' => $sidebarHtml,
            'flash_html' => $this->renderFlashMessages($actionResult->flashMessages()),
            'url' => $request->pageUrl($actionResult->query()),
        ]);
    }

    private function renderLayout(
        PageInterfaceFramework $page,
        RequestFramework $request,
        array $context,
        array $selectorUi,
        string $cardsHtml,
        ActionResultFramework $actionResult
    ): string
    {
        $pageId = $page->id();
        $title = HelperFramework::escape($page->title());
        $subtitle = HelperFramework::escape($page->subtitle());
        $contentHtml = trim($cardsHtml) !== '' ? $cardsHtml : $this->renderNoAccessState();

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $title . ' | EEL Accounts</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="css/index.css">
</head>
<body>
<div class="layout">
    ' . $this->renderSidebar($page, $context, $selectorUi) . '
    <main class="main" data-current-page="' . HelperFramework::escape($pageId) . '">
        <div class="topbar">
            <div>
                <h1>' . $title . '</h1>
                <p>' . $subtitle . '</p>
            </div>
            <div class="topbar-cluster"><div id="tax-year-selector-slot">' . $this->renderTaxYearSelector($page, $context, $selectorUi) . '</div></div>
        </div>
        <div id="flash-messages" class="flash-messages">' . $this->renderFlashMessages($actionResult->flashMessages()) . '</div>
        <section class="page-stack" data-page-id="' . HelperFramework::escape($pageId) . '">' . $contentHtml . '</section>
        <div id="page-load-time" class="page-load-time" aria-live="polite"></div>
    </main>
</div>
<script src="js/index.js"></script>
</body>
</html>';
    }

    private function renderFlashMessages(array $flashMessages): string
    {
        $html = '';

        foreach ($flashMessages as $message) {
            $type = strtolower((string)($message['type'] ?? 'success'));
            $class = $type === 'error' ? 'error' : 'success';
            $messageHtml = array_key_exists('message_html', $message)
                ? (string)($message['message_html'] ?? '')
                : HelperFramework::escape((string)($message['message'] ?? ''));
            $html .= '<div class="alert ' . $class . '">' . $messageHtml . '</div>';
        }

        return $html;
    }

    private function renderSidebar(PageInterfaceFramework $page, array $context, array $selectorUi): string
    {
        $currentPageId = $page->id();
        $sessionAuthenticationService = new SessionAuthenticationService();
        $items = $this->sidebarItems($sessionAuthenticationService, $currentPageId);
        $displayName = $this->currentSidebarDisplayName($sessionAuthenticationService);

        $html = '<aside id="sidebar-shell" class="sidebar">
        <div class="brand-block">
            <div class="brand">
                <div class="brand-mark">E</div>
                <div class="brand-copy">
                    <div class="brand-title">EEL Accounts</div>
                    <div class="brand-subtitle">Bookkeeping without the fog and panic</div>
                </div>
            </div>
            <div class="brand-toolbar">
                <div class="brand-toolbar-user">' . HelperFramework::escape($displayName) . '</div>
                <button class="sidebar-toggle" type="button" id="sidebar-toggle" aria-label="Toggle sidebar" aria-expanded="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M15 6l-6 6l6 6"/>
                    </svg>
                </button>
            </div>
            <div id="company-selector-slot">' . $this->renderCompanySelector($page, $context, $selectorUi) . '</div>
        </div>';

        $html .= '<div class="nav-scroll-shell">
            <div class="nav-scroll-hint top" aria-hidden="true"></div>
            <div class="nav-group" aria-label="Sidebar navigation">';

        foreach ($items as $item) {
            $active = !empty($item['is_active']) ? ' active' : '';
            $iconHtml = $this->renderNavIcon($item);

            $html .= '<a class="nav-link' . $active . '" href="' . HelperFramework::escape((string)$item['url']) . '" data-ajax-link="true">
                    <span class="nav-icon-wrap">' . $iconHtml . '</span>
                    <span class="nav-link-text">' . HelperFramework::escape((string)$item['label']) . '</span>
                    <span class="nav-link-short" aria-hidden="true">' . HelperFramework::escape((string)($item['short'] ?? '')) . '</span>
                </a>';
        }

        $html .= '</div>
            <div class="nav-scroll-hint bottom" aria-hidden="true"></div>
        </div>';
        $html .= $this->renderSidebarLogout($sessionAuthenticationService);
        $html .= '</aside>';

        return $html;
    }

    private function renderSidebarLogout(SessionAuthenticationService $sessionAuthenticationService): string
    {
        return '<div class="sidebar-footer">
            <form class="sidebar-logout-form" method="post" action="/">
                <input type="hidden" name="auth_action" value="logout">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($sessionAuthenticationService->csrfToken()) . '">
                <button class="sidebar-logout-button" type="submit">
                    <span class="nav-icon-wrap sidebar-logout-icon" aria-hidden="true"></span>
                    <span class="nav-link-text">Logout</span>
                    <span class="nav-link-short" aria-hidden="true">Out</span>
                </button>
            </form>
        </div>';
    }

    private function currentSidebarDisplayName(SessionAuthenticationService $sessionAuthenticationService): string
    {
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $sessionAuthenticationService->authenticatedUserId($currentDeviceId);

        if ($userId <= 0) {
            return '';
        }

        $user = (new UserAuthenticationService())->userById($userId);

        return trim((string)($user['display_name'] ?? ''));
    }

    private function sidebarItems(SessionAuthenticationService $sessionAuthenticationService, string $currentPageId): array
    {
        $items = (new NavigationFramework(APP_PAGES, $currentPageId, '/?page='))->build();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $sessionAuthenticationService->authenticatedUserId($currentDeviceId);

        if ($userId <= 0) {
            return array_values(array_filter(
                $items,
                static fn(array $item): bool => (string)($item['key'] ?? '') !== 'roles'
            ));
        }

        $roleAssignmentService = new RoleAssignmentService();
        if ($roleAssignmentService->isAdminUser($userId)) {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn(array $item): bool => (string)($item['key'] ?? '') !== 'roles'
        ));
    }

    private function renderNavIcon(array $item): string
    {
        $iconPath = $item['icon_path'] ?? null;
        if (!is_string($iconPath) || $iconPath === '') {
            return '';
        }

        return '<img class="nav-icon" src="' . HelperFramework::escape($iconPath) . '" alt="" aria-hidden="true">';
    }

    private function renderCompanySelector(PageInterfaceFramework $page, array $context, array $selectorUi): string
    {
        $selectedCompanyId = (string)($context['selected_company_id'] ?? '');
        $disabled = !empty($selectorUi['company_selector_disabled']) ? ' disabled' : '';
        $html = '<form class="selector-form" method="post" data-ajax="true">
            <input type="hidden" name="action" value="set-page-context">
            <input type="hidden" name="page" value="' . HelperFramework::escape($page->id()) . '">
            <input type="hidden" name="tax_year_id" value="">
            <input type="hidden" name="_ajax" value="1">';

        foreach ($this->pageCards($page, $context) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        $html .= '<div class="selector-shell sidebar-select-shell">
                <label class="selector-label company-selector-label" for="company-selector">Company</label>
                <select class="selector-input sidebar-select" id="company-selector" name="company_id" data-selector-kind="company"' . $disabled . '>';

        foreach ((array)($selectorUi['companies'] ?? []) as $company) {
            $value = (string)($company['value'] ?? '');
            $selected = $value !== '' && $value === $selectedCompanyId ? ' selected' : '';
            $optionDisabled = !empty($company['disabled']) ? ' disabled' : '';
            $shortLabel = HelperFramework::escape((string)($company['short_label'] ?? ($company['label'] ?? '')));
            $label = HelperFramework::escape((string)($company['label'] ?? ''));
            $html .= '<option value="' . HelperFramework::escape($value) . '" data-short-label="' . $shortLabel . '"' . $selected . $optionDisabled . '>' . $label . '</option>';
        }

        $html .= '</select>
            </div>
        </form>';

        return $html;
    }

    private function renderTaxYearSelector(PageInterfaceFramework $page, array $context, array $selectorUi): string
    {
        if (!$page->showsTaxYearSelector()) {
            return '';
        }

        $selectedCompanyId = (string)($context['selected_company_id'] ?? '');
        $selectedTaxYearId = (string)($context['selected_tax_year_id'] ?? '');
        $disabled = !empty($selectorUi['tax_year_selector_disabled']) ? ' disabled' : '';
        $html = '<form class="selector-form" method="post" data-ajax="true">
            <input type="hidden" name="action" value="set-page-context">
            <input type="hidden" name="page" value="' . HelperFramework::escape($page->id()) . '">
            <input type="hidden" name="company_id" value="' . HelperFramework::escape($selectedCompanyId) . '">
            <input type="hidden" name="_ajax" value="1">';

        foreach ($this->pageCards($page, $context) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        $html .= '<div class="selector-shell topbar-select-shell">
                <label class="selector-label" for="tax-year-selector">Tax Period</label>
                <select class="selector-input topbar-select" id="tax-year-selector" name="tax_year_id" data-selector-kind="tax-year"' . $disabled . '>';

        foreach ((array)($selectorUi['tax_years'] ?? []) as $taxYear) {
            $value = (string)($taxYear['value'] ?? '');
            $selected = $value !== '' && $value === $selectedTaxYearId ? ' selected' : '';
            $optionDisabled = !empty($taxYear['disabled']) ? ' disabled' : '';
            $label = HelperFramework::escape((string)($taxYear['label'] ?? ''));
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . $selected . $optionDisabled . '>' . $label . '</option>';
        }

        $html .= '</select>
            </div>
        </form>';

        return $html;
    }

    private function buildSelectorUi(
        PageInterfaceFramework $page,
        RequestFramework $request,
        PageServiceFramework $services
    ): array
    {
        return $services->get(CompanyStore::class)->buildSelectorContext($request, $page);
    }

    private function buildSelectorDeltaPayload(
        PageInterfaceFramework $page,
        array $context,
        array $selectorUi
    ): array
    {
        return [
            'selected_company_id' => (string)($context['selected_company_id'] ?? ''),
            'selected_tax_year_id' => (string)($context['selected_tax_year_id'] ?? ''),
            'show_tax_year_selector' => $page->showsTaxYearSelector(),
            'company_selector_disabled' => !empty($selectorUi['company_selector_disabled']),
            'tax_year_selector_disabled' => !empty($selectorUi['tax_year_selector_disabled']),
            'companies' => array_values((array)($selectorUi['companies'] ?? [])),
            'tax_years' => array_values((array)($selectorUi['tax_years'] ?? [])),
        ];
    }

    private function pageCards(PageInterfaceFramework $page, array $context): array
    {
        $cards = $context['page_cards'] ?? $page->cards();

        return array_values(array_map('strval', is_array($cards) ? $cards : []));
    }

    private function renderNoAccessState(): string
    {
        return '<section class="page-card" data-card-key="no_access">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">No Access</h2>
                    </div>
                </div>
                <div class="card-body stack">
                    <p class="helper">You do not currently have access to any content on this page.</p>
                </div>
            </div>
        </section>';
    }
}

