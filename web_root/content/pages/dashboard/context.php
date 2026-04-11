<?php
declare(strict_types=1);

function dashboard_build_context(WebRequest $request, WebPageServices $services, WebActionResult $actionResult): array
{
    $focus = dashboard_normalise_focus((string)($actionResult->query()['focus'] ?? $request->query('focus', 'architecture')));
    $companyAccountService = $services->get('company_account');

    $statsByFocus = [
        'architecture' => [
            ['label' => 'Runtime primitives', 'value' => '10', 'foot' => 'Request, response, page, card, factory, and renderer pieces.'],
            ['label' => 'Ownership model', 'value' => '1', 'foot' => 'Each page owns its own context, actions, helpers, and cards.'],
            ['label' => 'AJAX mode', 'value' => 'XHR', 'foot' => 'Interactions now return card deltas instead of full reloads.'],
            ['label' => 'Resolution model', 'value' => '_page', 'foot' => 'Convention-based page and card class names.'],
        ],
        'cards' => [
            ['label' => 'Card contract', 'value' => 'HTML', 'foot' => 'Cards render prepared data only.'],
            ['label' => 'Invalidation facts', 'value' => '3', 'foot' => 'This example card set reacts to shared page facts.'],
            ['label' => 'Lazy load', 'value' => 'Yes', 'foot' => 'Cards resolve from the page folder by convention.'],
            ['label' => 'Shared path', 'value' => '1', 'foot' => 'Full page and delta use the same card renderer.'],
        ],
        'ajax' => [
            ['label' => 'Delta payload', 'value' => 'JSON', 'foot' => 'Only stale cards come back over the wire.'],
            ['label' => 'DOM swaps', 'value' => 'Live', 'foot' => 'Minimal JS replaces only targeted card wrappers.'],
            ['label' => 'Flash handling', 'value' => 'Inline', 'foot' => 'Messages update through the same response payload.'],
            ['label' => 'URL sync', 'value' => 'Push', 'foot' => 'State stays deep-linkable after AJAX actions.'],
        ],
    ];

    $activityByFocus = [
        'architecture' => [
            ['title' => 'Thin front controller', 'detail' => 'Bootstrap, resolve page, load declared services, call page, send response.'],
            ['title' => 'No central registry', 'detail' => 'Page and card classes load directly from naming convention helpers.'],
            ['title' => 'Service boundary kept', 'detail' => 'Existing domain services remain in classes/service and are only injected.'],
        ],
        'cards' => [
            ['title' => 'Page-owned cards', 'detail' => 'Card classes now live under content/pages/dashboard/cards instead of a global dump.'],
            ['title' => 'Presentational helpers only', 'detail' => 'Helpers stay in the page folder and do not touch DB or request globals.'],
            ['title' => 'Fact-based invalidation', 'detail' => 'Each card declares the facts that make it stale.'],
        ],
        'ajax' => [
            ['title' => 'XHR-only interactions', 'detail' => 'This example form posts with JavaScript and updates cards in place.'],
            ['title' => 'Shared renderer', 'detail' => 'Delta rendering uses the same card renderer as the first page load.'],
            ['title' => 'URL preserved', 'detail' => 'Changing focus updates the query string without a full navigation.'],
        ],
    ];

    return [
        'page_id' => 'dashboard',
        'focus' => $focus,
        'focus_label' => dashboard_focus_label($focus),
        'stats' => $statsByFocus[$focus],
        'activity' => $activityByFocus[$focus],
        'focus_options' => dashboard_focus_options(),
        'service_class' => get_class($companyAccountService),
        'page_cards' => ['hero', 'overview', 'activity'],
        'cards_dom_ids' => array_map(
            static fn(string $cardKey): string => FrameWorkHelper::cardDomId('dashboard', $cardKey),
            ['hero', 'overview', 'activity']
        ),
    ];
}
