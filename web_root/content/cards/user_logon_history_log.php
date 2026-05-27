<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _user_logon_history_logCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'user_logon_history_log';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'logon_rows',
                'service' => LogsRepository::class,
                'method' => 'fetchRecentLogonHistory',
                'params' => [
                    'limit' => 200,
                    'userId' => ':user_logon_history_log.selected_user_id',
                ],
            ],
            [
                'key' => 'users',
                'service' => UserAuthenticationService::class,
                'method' => 'listUsers',
            ],
        ];
    }

    public function helper(array $context): string
    {
        return 'Recent login, logout, OTP, and session events captured for user accounts.';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext = $this->applyTableSortContext($request, $pageContext, $this->key());
        $pageContext[$this->key()]['selected_user_id'] = max(0, (int)$request->input(
            'logon_history_user_id',
            $pageContext[$this->key()]['selected_user_id'] ?? 0
        ));

        return $pageContext;
    }

    protected function additionalInvalidationFacts(): array
    {
        return [];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        return $this->configuredTable($context)->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
            'logon_history_user_id' => (string)$this->selectedUserId($context),
        ]);
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        $selectedUserId = $this->selectedUserId($context);
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? ''),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            'logon_history_user_id' => (string)$selectedUserId,
        ];
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'User logon events',
                $this->paginationPageField(),
                $hiddenFields
            )
            ->filterSelect(
                'logon_history_user_id',
                'User',
                $this->userFilterOptions($context),
                (string)$selectedUserId,
                [
                    'page' => (string)($context['page']['page_id'] ?? ''),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('user-logon-history-log')
            ->exportLimit(200)
            ->empty('No user logon history has been recorded yet.')
            ->textColumn('occurred_at', 'Time')
            ->textColumn('principal', 'User', fallback: 'Unknown user')
            ->badgeColumn(
                'event_type',
                'Event',
                labelSeparator: '_',
                badgeClassFormatter: static fn(array $row): string => (int)($row['success'] ?? 0) === 1 ? 'success' : 'danger'
            )
            ->textColumn('ip_address', 'IP')
            ->primarySecondaryColumn(
                'browser_label',
                'Browser Agent Details',
                secondaryKey: 'user_agent',
                primaryFallback: 'Unknown browser',
                secondaryPreviewLength: 512
            )
            ->textColumn('reason', 'Reason');
    }

    private function selectedUserId(array $context): int
    {
        return max(0, (int)($context[$this->key()]['selected_user_id'] ?? 0));
    }

    private function rows(array $context): array
    {
        return array_map(
            fn(array $row): array => $this->normaliseRow($row),
            array_filter(
                (array)(($context['services'] ?? [])['logon_rows'] ?? []),
                static fn(mixed $row): bool => is_array($row)
            )
        );
    }

    private function normaliseRow(array $row): array
    {
        $principal = trim((string)($row['user_display_name'] ?? ''));

        if ($principal === '') {
            $principal = trim((string)($row['attempted_email_address'] ?? ''));
        }

        $row['principal'] = $principal !== '' ? $principal : 'Unknown user';

        return $row;
    }

    private function userFilterOptions(array $context): array
    {
        $options = ['0' => 'All users'];

        foreach ((array)(($context['services'] ?? [])['users'] ?? []) as $user) {
            $userId = (int)($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $label = trim((string)($user['display_name'] ?? ''));
            $email = trim((string)($user['email_address'] ?? ''));

            if ($label === '') {
                $label = $email !== '' ? $email : 'User #' . $userId;
            } elseif ($email !== '') {
                $label .= ' (' . $email . ')';
            }

            $options[(string)$userId] = $label;
        }

        return $options;
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'user.logon.history.log');
    }
}
