<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _user_account_audit_logCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'user_account_audit_log';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'audit_rows',
                'service' => UserHistoryStore::class,
                'method' => 'fetchRecentAccountAudit',
                'params' => [
                    'limit' => 200,
                ],
            ],
        ];
    }

    public function helper(array $context): string 
    {
        return 'Recent user-account changes such as profile updates, password changes, OTP resets, and enable or disable actions.';
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
                'User account audit events',
                $this->paginationPageField(),
                $hiddenFields
            );
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('user-account-audit-log')
            ->exportLimit(200)
            ->empty('No user account audit events have been recorded yet.')
            ->classes(wrapperClass: 'table-scroll user-account-audit-table')
            ->textColumn('created_at', 'Time')
            ->textColumn('affected_user_display_name', 'Affected User')
            ->textColumn(
                'actor_user_display_name',
                'Actor',
                fallback: 'System'
            )
            ->badgeColumn(
                'action_type',
                'Action',
                badgeClass: 'info',
                labelSeparator: '_'
            )
            ->textWithJsonSummaryColumn(
                'reason',
                'Reason',
                'details_json'
            )
            ->primarySecondaryColumn(
                'ip_address',
                'IP / User Agent',
                secondaryKey: 'user_agent',
                secondaryPreviewLength: 96,
                secondaryClass: 'helper',
                secondaryPreviewClass: 'log-agent-preview',
                cellClass: 'log-agent-cell'
            );
    }

    private function rows(array $context): array
    {
        return (array)(($context['services'] ?? [])['audit_rows'] ?? []);
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'user.account.audit.log');
    }
}
