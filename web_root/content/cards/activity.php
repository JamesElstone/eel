<?php
/**
 * eelKit Framework
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
        return [
            [
                'key' => 'activity_rows',
                'service' => LogsRepository::class,
                'method' => 'fetchRecentFlashActivity',
                'params' => [
                    'limit' => 200,
                ],
            ],
        ];
    }

    public function helper(array $context): string
    {
        return 'Recent success and error flash messages recorded from framework action results.';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyTableSortContext($request, $pageContext, $this->key());
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        return $this->configuredTable($context)->render(
            $context,
            [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]
        );
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? ''),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ];
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Activity',
                $this->paginationPageField(),
                $hiddenFields
            );
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('application-activity-flash-log')
            ->exportLimit(200)
            ->empty('No application activity flash messages have been recorded yet.')
            ->classes(wrapperClass: 'table-scroll application-activity-table')
            ->textColumn('occurred_at', 'Time')
            ->textColumn('user_display_name', 'User', fallback: 'System')
            ->primarySecondaryColumn(
                'page_id',
                'Page / Action',
                secondaryKey: 'activity_action',
                primaryFallback: 'Unknown page',
                secondaryPreviewLength: 80
            )
            ->badgeColumn(
                'message_type',
                'Type',
                badgeClassFormatter: static fn(array $row): string => (string)($row['message_type'] ?? '') === 'error' ? 'danger' : 'success'
            )
            ->primarySecondaryColumn(
                'message_text',
                'Message',
                secondaryKey: 'message_html_text',
                secondaryPreviewLength: 120
            )
            ->primarySecondaryColumn(
                'ip_address',
                'IP / User Agent',
                secondaryKey: 'user_agent',
                primaryFallback: 'Unknown IP',
                secondaryPreviewLength: 96,
                secondaryClass: 'helper',
                secondaryPreviewClass: 'log-agent-preview',
                cellClass: 'log-agent-cell'
            )
            ->textColumn('request_method', 'Method')
            ->textColumn('request_uri', 'Request');
    }

    private function rows(array $context): array
    {
        return array_map(
            fn(array $row): array => $this->normaliseRow($row),
            array_filter(
                (array)(($context['services'] ?? [])['activity_rows'] ?? []),
                static fn(mixed $row): bool => is_array($row)
            )
        );
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'activity');
    }

    private function normaliseRow(array $row): array
    {
        $action = trim((string)($row['action_name'] ?? ''));
        $cardAction = trim((string)($row['card_action_name'] ?? ''));

        if ($action !== '' && $cardAction !== '') {
            $row['activity_action'] = $action . ' / ' . $cardAction;
        } elseif ($action !== '') {
            $row['activity_action'] = $action;
        } elseif ($cardAction !== '') {
            $row['activity_action'] = $cardAction;
        } else {
            $row['activity_action'] = 'No action';
        }

        return $row;
    }
}
