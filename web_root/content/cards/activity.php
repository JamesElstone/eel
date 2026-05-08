<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _activityCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'activity';
    }

    public function services(): array
    {
        return [];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext['page']['activity_window'] = $this->normaliseWindow((string)$request->input('activity_window', '7_days'));

        return $pageContext;
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['dashboard.feed'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $itemsHtml = '';
        $page = (array)($context['page'] ?? []);
        $selectedWindow = $this->normaliseWindow((string)($page['activity_window'] ?? '7_days'));
        $activity = (array)(($context['services'] ?? [])['activity_feed'] ?? ($page['activity'] ?? []));
        $pagination = HelperFramework::paginateArray(
            $activity,
            $this->paginationPage($context),
            self::PAGE_SIZE
        );
        $activity = (array)$pagination['items'];

        foreach ($activity as $item) {
            $meta = trim((string)($item['meta'] ?? ''));
            $occurredAt = trim((string)($item['occurred_at'] ?? ''));
            $helper = trim(implode(' | ', array_filter([$occurredAt, $meta], static fn(string $part): bool => $part !== '')));

            $itemsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)($item['title'] ?? '')) . '</strong>
                <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
                ' . ($helper !== '' ? '<span class="helper">' . HelperFramework::escape($helper) . '</span>' : '') . '
            </div>';
        }

        if ($itemsHtml === '') {
            $itemsHtml = '<div class="list-item">
                <strong>No recent activity</strong>
                <span>No audit or history events have been recorded yet.</span>
            </div>';
        }

        return $this->filterControls($context, $selectedWindow)
            . '<div class="list">' . $itemsHtml . '</div>'
            . $this->paginationControls(
                $context,
                $pagination,
                'Activity',
                null,
                ['activity_window' => $selectedWindow]
            );
    }

    private function filterControls(array $context, string $selectedWindow): string
    {
        $options = [
            '1_day' => '1 day',
            '7_days' => '7 days',
            'this_month' => 'This month',
        ];
        $buttons = '';

        foreach ($options as $value => $label) {
            $buttons .= '<button class="button button-inline' . ($selectedWindow === $value ? ' primary' : '') . '" type="submit" name="activity_window" value="' . HelperFramework::escape($value) . '" data-show-card="activity">'
                . HelperFramework::escape($label)
                . '</button>';
        }

        return '<form class="toolbar" method="post" data-ajax="true" data-invalidate-page="true">
            <input type="hidden" name="card_action" value="Activity">
            ' . $buttons . '
        </form>';
    }

    private function normaliseWindow(string $window): string
    {
        return in_array($window, ['1_day', '7_days', 'this_month'], true) ? $window : '7_days';
    }
}
