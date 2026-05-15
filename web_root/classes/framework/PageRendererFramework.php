<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
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
        $cardsHtml = $this->renderCardLayout($page, $request, $context, $actionResult, $services);

        $html = $this->renderLayout($page, $request, $context, $cardsHtml, $actionResult);

        return ResponseFramework::html($html);
    }

    private function renderCardLayout(
        PageInterfaceFramework $page,
        RequestFramework $request,
        array $context,
        ActionResultFramework $actionResult,
        PageServiceFramework $services
    ): string {
        $layout = $this->resolveCardLayout($page, $context);

        if (!$this->shouldRenderTabs($layout)) {
            $cardsHtml = [];
            foreach (($layout[0]['cards'] ?? []) as $cardKey) {
                $cardsHtml[] = $this->renderPageStackCard($page, (string)$cardKey, $context, $services);
            }

            return implode("\n", $cardsHtml);
        }

        $tabButtons = [];
        $tabPanels = [];
        $pageId = HelperFramework::escape($page->id());

        $selectedTabIndex = $this->selectedTabIndex($layout, $request, $actionResult);

        foreach ($layout as $index => $entry) {
            $tabId = $pageId . '-layout-tab-' . (string)$index;
            $panelId = $pageId . '-layout-panel-' . (string)$index;
            $selected = $index === $selectedTabIndex;
            $tabLabel = HelperFramework::escape((string)($entry['tab'] ?? 'Tab ' . ($index + 1)));
            $layoutClass = (string)($entry['layout'] ?? 'stack') === 'split'
                ? 'page-card-tab-panel-layout split'
                : 'page-card-tab-panel-layout stack';
            $panelCardsHtml = [];

            foreach ((array)($entry['cards'] ?? []) as $cardKey) {
                $panelCardsHtml[] = $this->renderPageStackCard($page, (string)$cardKey, $context, $services);
            }

            $tabButtons[] = '<button class="page-card-tab' . ($selected ? ' is-active' : '') . '" type="button" role="tab" id="' . $tabId . '" aria-selected="' . ($selected ? 'true' : 'false') . '" aria-controls="' . $panelId . '" data-page-card-tab="' . $panelId . '">' . $tabLabel . '</button>';
            $tabPanels[] = '<div class="page-card-tab-panel" id="' . $panelId . '" role="tabpanel" aria-labelledby="' . $tabId . '"' . ($selected ? '' : ' hidden') . '><div class="' . $layoutClass . '">' . implode("\n", $panelCardsHtml) . '</div></div>';
        }

        return '<div class="page-card-tabs"><div class="page-card-tab-shell"><div class="page-card-tablist" role="tablist">'
            . implode('', $tabButtons)
            . '</div></div><div class="page-card-tab-content">'
            . implode("\n", $tabPanels)
            . '</div></div>';
    }

    private function renderPageStackCard(
        PageInterfaceFramework $page,
        string $cardKey,
        array $context,
        PageServiceFramework $services
    ): string {
        $cardClasses = [];

        if (method_exists($page, 'pageStackCards')) {
            $configuredClasses = $page->pageStackCards();

            if (isset($configuredClasses[$cardKey])) {
                $cardClasses[] = (string)$configuredClasses[$cardKey];
            } elseif (in_array($cardKey, $configuredClasses, true)) {
                $cardClasses[] = 'card-stack';
            }
        }

        $classAttribute = 'page-stack-card';
        if ($cardClasses !== []) {
            $classAttribute .= ' ' . implode(' ', array_map(
                static fn(string $class): string => HelperFramework::escape($class),
                $cardClasses
            ));
        }

        return '<div class="' . $classAttribute . '" data-page-stack-card="' . HelperFramework::escape($cardKey) . '">'
            . $this->cards->render($page->id(), $cardKey, $context, $services)
            . '</div>';
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
        $sidebarHtml = null;
        $invalidateAllCards = in_array('page.reload', $changedFacts, true);

        foreach ($currentCards as $cardKey) {
            if (!$invalidateAllCards) {
                if ($changedFacts === []) {
                    continue;
                }

                $facts = $this->cards->cardInvalidationFacts($cardKey);

                if (array_intersect($changedFacts, $facts) === []) {
                    continue;
                }
            }

            $cards[HelperFramework::cardDomId($page->id(), $cardKey)] = $this->cards->render($page->id(), $cardKey, $context, $services);
        }

        if (in_array('layout.sidebar', $changedFacts, true)) {
            $sidebarHtml = $this->renderSidebar($page, $context);
        }

        return ResponseFramework::json([
            'success' => $actionResult->isSuccess(),
            'page' => $page->id(),
            'cards' => $cards,
            'sidebar_html' => $sidebarHtml,
            'flash_html' => $this->renderFlashMessages($actionResult->flashMessages()),
            'url' => $request->pageUrl($actionResult->query()),
            'show_card' => $this->requestedVisibleCard($page, $request, $context, $actionResult),
            'ajax_nonce' => $this->ajaxNonceRefresh(),
        ]);
    }

    private function renderLayout(
        PageInterfaceFramework $page,
        RequestFramework $request,
        array $context,
        string $cardsHtml,
        ActionResultFramework $actionResult
    ): string
    {
        $pageId = $page->id();
        $title = HelperFramework::escape($page->title());
        $subtitle = HelperFramework::escape($page->subtitle());
        global $appName;
        $escapedAppName = HelperFramework::escape((string)($appName ?? 'eelKit Framework'));
        $pageStackClass = trim($page->pageStackClass());
        $pageStackClasses = 'page-stack' . ($pageStackClass !== '' ? ' ' . HelperFramework::escape($pageStackClass) : '');
        $contentHtml = $this->pageCards($page, $context) !== [] ? $cardsHtml : $this->renderNoAccessState();
        $developerOptionsEnabled = (bool)AppConfigurationStore::get('developer_options', false);
        $developerOptionsLabel = $developerOptionsEnabled ? 'Developer Options: On' : 'Developer Options: Off';

        return '<!DOCTYPE html>
        <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>' . $title . ' | ' . $escapedAppName . '</title>
                <link rel="icon" type="image/x-icon" href="favicon.ico">
                <link rel="stylesheet" href="css/index.css">
            </head>
            <body>
                <div class="layout">
                    ' . $this->renderSidebar($page, $context) . '
                    <main class="main" data-current-page="' . HelperFramework::escape($pageId) . '">
                        <div class="topbar">

                            <!-- LEFT COLUMN -->
                            <div class="topbar-left">
                                <h1>' . $title . '</h1>
                                <p>' . $subtitle . '</p>
                            </div>

                            <div class="float">
                                <div class="badge warning">' . HelperFramework::escape($developerOptionsLabel) . '</div>
                            </div>

                        </div>
                        <div id="flash-messages" class="flash-messages">' . $this->renderFlashMessages($actionResult->flashMessages()) . '</div>
                        <section class="' . $pageStackClasses . '" data-page-id="' . HelperFramework::escape($pageId) . '">' . $contentHtml . '</section>
                        <div id="page-load-time" class="page-load-time" aria-live="polite"></div>
                    </main>
                </div>
                ' . $this->renderAjaxSecurityBootstrap($request) . '
                <script src="js/index.js"></script>
            </body>
        </html>';
    }

    private function renderAjaxSecurityBootstrap(RequestFramework $request): string
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $noncePool = $sessionAuthenticationService->ensureAjaxNoncePool();

        if ($noncePool === []) {
            return '';
        }

        $json = json_encode([
            'nonce_pool' => $noncePool,
        ], JSON_THROW_ON_ERROR);

        return '<div id="ajax-security-bootstrap" hidden data-nonce-payload="'
            . HelperFramework::escape($json)
            . '"></div>';
    }

    private function ajaxNonceRefresh(): ?string
    {
        $sessionAuthenticationService = new SessionAuthenticationService();

        return $sessionAuthenticationService->consumeAjaxNonceRefresh();
    }

    private function renderFlashMessages(array $flashMessages): string
    {
        $html = '';

        foreach ($flashMessages as $message) {
            if (!is_array($message)) {
                $html .= '<div class="alert success">' . HelperFramework::escape((string)$message) . '</div>';
                continue;
            }

            $type = strtolower((string)($message['type'] ?? 'success'));
            $class = $type === 'error' ? 'error' : 'success';
            $messageHtml = array_key_exists('message_html', $message)
                ? (string)($message['message_html'] ?? '')
                : HelperFramework::escape((string)($message['message'] ?? ''));
            $html .= '<div class="alert ' . $class . '">' . $messageHtml . '</div>';
        }

        return $html;
    }

    private function renderSidebar(PageInterfaceFramework $page, array $context): string
    {
        $currentPageId = $page->id();
        $sessionAuthenticationService = new SessionAuthenticationService();
        $items = $this->sidebarItems($sessionAuthenticationService, $currentPageId);
        $displayName = $this->currentSidebarDisplayName($sessionAuthenticationService);
        global $appName;
        $escapedAppName = HelperFramework::escape((string)($appName ?? 'eelKit Framework'));
        $escapedBrandMark = HelperFramework::escape($this->brandMark());
        $escapedAppStrapline = HelperFramework::escape(AppConfigurationStore::appStrapline());
        $hideCollapsedLinkInitials = $this->hideCollapsedLinkInitials();
        $showCollapsedLinkInitials = !$hideCollapsedLinkInitials;
        $sidebarClass = 'sidebar' . ($hideCollapsedLinkInitials ? ' sidebar-hide-collapsed-link-initials' : '');

        $html = '<aside id="sidebar-shell" class="' . $sidebarClass . '">
        <div class="brand-block">
            <div class="brand">
                <div class="brand-mark">' . $escapedBrandMark . '</div>
                <div class="brand-copy">
                    <div class="brand-title">' . $escapedAppName . '</div>
                    <div class="brand-subtitle">' . $escapedAppStrapline . '</div>
                </div>
            </div>
            <div class="brand-toolbar">
                <div class="brand-toolbar-user">' . HelperFramework::escape($displayName) . '</div>
                ' . $this->renderToolbarLogout($sessionAuthenticationService) . '
                <button class="sidebar-toggle" type="button" id="sidebar-toggle" aria-label="Toggle sidebar" aria-expanded="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M15 6l-6 6l6 6"/>
                    </svg>
                </button>
            </div>
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
                    ' . ($showCollapsedLinkInitials ? '<span class="nav-link-short" aria-hidden="true">' . HelperFramework::escape((string)($item['short'] ?? '')) . '</span>' : '') . '
                </a>';
        }

        $html .= '</div>
            <div class="nav-scroll-hint bottom" aria-hidden="true"></div>
        </div>';
        $html .= $this->renderSidebarLogout($sessionAuthenticationService, $showCollapsedLinkInitials);
        $html .= '</aside>';

        return $html;
    }

    private function brandMark(): string
    {
        $brandMark = trim((string)AppConfigurationStore::get('brand-mark', 'E'));

        return $brandMark !== '' ? $brandMark : 'E';
    }

    private function hideCollapsedLinkInitials(): bool
    {
        return filter_var(
            AppConfigurationStore::get('navigation.hide_collapsed_link_initials', false),
            FILTER_VALIDATE_BOOL
        );
    }

    private function renderToolbarLogout(SessionAuthenticationService $sessionAuthenticationService): string
    {
        return '<form class="brand-toolbar-logout-form" method="post" action="/">
            <input type="hidden" name="auth_action" value="logout">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($sessionAuthenticationService->csrfToken()) . '">
            <button class="brand-toolbar-logout-button" type="submit">Logout</button>
        </form>';
    }

    private function renderSidebarLogout(SessionAuthenticationService $sessionAuthenticationService, bool $showCollapsedLinkInitials): string
    {
        return '<div class="sidebar-footer">
            <form class="sidebar-logout-form" method="post" action="/">
                <input type="hidden" name="auth_action" value="logout">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($sessionAuthenticationService->csrfToken()) . '">
                <button class="sidebar-logout-button" type="submit">
                    <span class="nav-icon-wrap sidebar-logout-icon" aria-hidden="true"></span>
                    <span class="nav-link-text">Logout</span>
                    ' . ($showCollapsedLinkInitials ? '<span class="nav-link-short" aria-hidden="true">Out</span>' : '') . '
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

    private function pageCards(PageInterfaceFramework $page, array $context): array
    {
        $cards = $context['page']['page_cards'] ?? null;

        if (!is_array($cards) && method_exists($page, 'cardLayout')) {
            $cards = [];
            foreach ((array)$page->cardLayout() as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                foreach ((array)($entry['cards'] ?? []) as $cardKey) {
                    $cards[] = (string)$cardKey;
                }
            }
        }

        if (!is_array($cards)) {
            $cards = $page->cards();
        }

        return array_values(array_map('strval', is_array($cards) ? $cards : []));
    }

    private function resolveCardLayout(PageInterfaceFramework $page, array $context = []): array
    {
        $allowedCards = $this->pageCards($page, $context);
        $usesCardLayout = method_exists($page, 'cardLayout');
        $rawLayout = $usesCardLayout
            ? (array)$page->cardLayout()
            : [[
                'tab' => null,
                'layout' => 'stack',
                'cards' => $allowedCards,
            ]];

        $layout = [];
        foreach ($rawLayout as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $cards = array_values(array_map(
                'strval',
                is_array($entry['cards'] ?? null) ? $entry['cards'] : []
            ));

            if ($usesCardLayout) {
                $cards = array_values(array_intersect($cards, $allowedCards));
            }

            if ($cards === []) {
                continue;
            }

            $layoutValue = (string)($entry['layout'] ?? 'stack');
            $layout[] = [
                'tab' => array_key_exists('tab', $entry) && $entry['tab'] !== null
                    ? (string)$entry['tab']
                    : null,
                'layout' => in_array($layoutValue, ['stack', 'split'], true) ? $layoutValue : 'stack',
                'cards' => $cards,
                'explicit' => $usesCardLayout,
            ];
        }

        return $layout;
    }

    private function shouldRenderTabs(array $layout): bool
    {
        if (count($layout) > 1) {
            return true;
        }

        return !empty($layout[0]['explicit']);
    }

    private function selectedTabIndex(array $layout, RequestFramework $request, ActionResultFramework $actionResult): int
    {
        $requestedCard = trim((string)($actionResult->query()['show_card'] ?? ''));

        if ($requestedCard === '') {
            $requestedCard = trim((string)$request->input('show_card', ''));
        }

        if ($requestedCard === '') {
            return 0;
        }

        foreach ($layout as $index => $entry) {
            $cards = is_array($entry['cards'] ?? null) ? $entry['cards'] : [];

            if (in_array($requestedCard, array_map('strval', $cards), true)) {
                return (int)$index;
            }
        }

        return 0;
    }

    private function requestedVisibleCard(PageInterfaceFramework $page, RequestFramework $request, array $context, ActionResultFramework $actionResult): ?string
    {
        $cardKey = trim((string)($actionResult->query()['show_card'] ?? ''));

        if ($cardKey === '') {
            $cardKey = trim((string)$request->input('show_card', ''));
        }

        if ($cardKey === '') {
            return null;
        }

        return in_array($cardKey, $this->pageCards($page, $context), true) ? $cardKey : null;
    }



    private function renderNoAccessState(): string
    {
        return '<p class="helper">You do not currently have access to any content on this page.</p>';
    }
}
