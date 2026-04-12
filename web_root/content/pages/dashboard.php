<?php
declare(strict_types=1);

final class _dashboard extends BaseWebPage
{
    public function id(): string
    {
        return 'dashboard';
    }

    public function title(): string
    {
        return 'Dashboard';
    }

    public function subtitle(): string
    {
        return 'Track the new page architecture with convention-led cards, shared rendering, and AJAX-only card updates.';
    }

    public function services(): array
    {
        return ['company_account'];
    }

    public function cards(): array
    {
        return [
            'hero',
            'overview',
            'activity',
        ];
    }

    protected function buildContext(
        WebRequest $request,
        WebPageService $services,
        WebActionResult $actionResult
    ): array
    {
        $companyAccountService = $services->get('company_account');

        $stats = [
            [
                'label' => 'Runtime primitives',
                'value' => '10',
                'foot' => 'Request, response, page, card, factory, and renderer pieces.',
            ],
            [
                'label' => 'Ownership model',
                'value' => '1',
                'foot' => 'Each page owns its own context, actions, and helpers, then selects shared cards.',
            ],
            [
                'label' => 'AJAX mode',
                'value' => 'XHR',
                'foot' => 'Interactions now return card deltas instead of full reloads.',
            ],
            [
                'label' => 'Resolution model',
                'value' => '_page / _cardCard',
                'foot' => 'Pages and cards now resolve through separate naming conventions.',
            ],
        ];

        $activity = [
            [
                'title' => 'Thin front controller',
                'detail' => 'Bootstrap, resolve page, load declared services, call page, send response.',
            ],
            [
                'title' => 'No central registry',
                'detail' => 'Page and card classes load directly from naming convention helpers.',
            ],
            [
                'title' => 'Service boundary kept',
                'detail' => 'Existing domain services remain in classes/service and are only injected.',
            ],
        ];

        // $pageCards = ['hero', 'overview', 'activity'];
        $pageCards = $this->cards();

        return [
            'page_id' => 'dashboard',
            'stats' => $stats,
            'activity' => $activity,
            'service_class' => get_class($companyAccountService),
            'page_cards' => $pageCards,
            'cards_dom_ids' => array_map(
                static fn(string $cardKey): string => FrameworkHelper::cardDomId('dashboard', $cardKey),
                $pageCards
            ),
        ];
    }
}
