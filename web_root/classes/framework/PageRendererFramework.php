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
        $cardsHtml = [];
        foreach ($page->cards() as $cardKey) {
            $cardsHtml[] = $this->cards->render($page->id(), (string)$cardKey, $context, $services);
        }

        $html = $this->renderLayout($page, $request, implode("\n", $cardsHtml), $actionResult);

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
            $currentCards = array_map('strval', $page->cards());
        }

        $cards = [];
        $changedFacts = $actionResult->changedFacts();

        foreach ($currentCards as $cardKey) {
            $facts = $this->cards->cardInvalidationFacts($cardKey);

            if ($changedFacts !== [] && array_intersect($changedFacts, $facts) === []) {
                continue;
            }

            $cards[HelperFramework::cardDomId($page->id(), $cardKey)] = $this->cards->render($page->id(), $cardKey, $context, $services);
        }

        return ResponseFramework::json([
            'success' => $actionResult->isSuccess(),
            'page' => $page->id(),
            'cards' => $cards,
            'flash_html' => $this->renderFlashMessages($actionResult->flashMessages()),
            'url' => $request->pageUrl($actionResult->query()),
        ]);
    }

    private function renderLayout(
        PageInterfaceFramework $page,
        RequestFramework $request,
        string $cardsHtml,
        ActionResultFramework $actionResult
    ): string
    {
        $pageId = $page->id();
        $title = HelperFramework::escape($page->title());
        $subtitle = HelperFramework::escape($page->subtitle());

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
    ' . $this->renderSidebar($pageId) . '
    <main class="main" data-current-page="' . HelperFramework::escape($pageId) . '">
        <div class="topbar">
            <div>
                <h1>' . $title . '</h1>
                <p>' . $subtitle . '</p>
            </div>
            <div class="topbar-cluster"></div>
        </div>
        <div id="flash-messages" class="flash-messages">' . $this->renderFlashMessages($actionResult->flashMessages()) . '</div>
        <section class="page-stack" data-page-id="' . HelperFramework::escape($pageId) . '">' . $cardsHtml . '</section>
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
            $html .= '<div class="alert ' . $class . '">' . HelperFramework::escape((string)($message['message'] ?? '')) . '</div>';
        }

        return $html;
    }

    private function renderSidebar(string $currentPageId): string
    {
        $items = (new NavigationFramework(APP_PAGES, $currentPageId, '/?page='))->build();

        $html = '<aside class="sidebar">
        <div class="brand-block">
            <div class="brand">
                <div class="brand-mark">E</div>
                <div class="brand-copy">
                    <div class="brand-title">EEL Accounts</div>
                    <div class="brand-subtitle">Bookkeeping without the fog bank</div>
                </div>
            </div>
            <div class="brand-toolbar">
                <button class="sidebar-toggle" type="button" id="sidebar-toggle" aria-label="Toggle sidebar" aria-expanded="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M15 6l-6 6l6 6"/>
                    </svg>
                </button>
            </div>
        </div>';

        $html .= '<div class="nav-group">';

        foreach ($items as $item) {
            $active = !empty($item['is_active']) ? ' active' : '';
            $iconHtml = $this->renderNavIcon($item);

            $html .= '<a class="nav-link' . $active . '" href="' . HelperFramework::escape((string)$item['url']) . '" data-ajax-link="true">
                    <span class="nav-icon-wrap">' . $iconHtml . '</span>
                    <span class="nav-link-text">' . HelperFramework::escape((string)$item['label']) . '</span>
                    <span class="nav-link-short" aria-hidden="true">' . HelperFramework::escape((string)($item['short'] ?? '')) . '</span>
                </a>';
        }

        $html .= '</div></aside>';

        return $html;
    }

    private function renderNavIcon(array $item): string
    {
        $iconPath = $item['icon_path'] ?? null;
        if (!is_string($iconPath) || $iconPath === '') {
            return '';
        }

        return '<img src="' . HelperFramework::escape($iconPath) . '" alt="" aria-hidden="true">';
    }
}

