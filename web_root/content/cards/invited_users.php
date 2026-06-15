<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _invited_usersCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'invited_users';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'invited_users_dashboard',
                'service' => UserManagementService::class,
                'method' => 'invitedUsersDashboard',
            ],
        ];
    }

    public function helper(array $context): string
    {
        return 'Recent account completion invitations and their current state.';
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

    public function render(array $context): string
    {
        return $this->configuredTable($context)->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
        ]);
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        return $this->configureTableSorting($this->table($context), $context, [
            'page' => (string)($context['page']['page_id'] ?? 'users'),
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ]);
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('invited-users')
            ->exportLimit(500)
            ->empty('No invitations were found.')
            ->textColumn('display_name', 'User')
            ->textColumn('contact_label', 'Contact')
            ->badgeColumn('status', 'Status', badgeClassFormatter: fn(array $row): string => $this->badgeClass((string)($row['status'] ?? '')))
            ->textColumn('created_at', 'Created')
            ->textColumn('expires_at', 'Expires')
            ->textColumn('last_sent_at', 'Last Sent', fallback: 'Not sent')
            ->textColumn('completed_at', 'Completed', fallback: 'Not completed')
            ->column(
                'actions',
                'Actions',
                html: fn(array $row): string => $this->actionsHtml($context, $row),
                exportable: false
            );
    }

    private function rows(array $context): array
    {
        $dashboard = (array)(($context['services'] ?? [])['invited_users_dashboard'] ?? []);
        $rows = [];

        foreach ((array)($dashboard['invites'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['contact_label'] = (string)($row['delivery_summary'] ?? $this->deliverySummary((array)($row['deliveries'] ?? [])));
            $rows[] = $row;
        }

        return $rows;
    }

    private function deliverySummary(array $deliveries): string
    {
        $latestByMethod = [];
        foreach ($deliveries as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }

            $method = strtolower(trim((string)($delivery['contact_method'] ?? '')));
            if ($method === '') {
                continue;
            }

            $latestByMethod[$method] = strtolower(trim((string)($delivery['status'] ?? 'created')));
        }

        if ($latestByMethod === []) {
            return 'Not sent';
        }

        $parts = [];
        foreach ($latestByMethod as $method => $status) {
            $parts[] = HelperFramework::labelFromKey($method) . ': ' . HelperFramework::labelFromKey($status);
        }

        return implode(' | ', $parts);
    }

    private function actionsHtml(array $context, array $row): string
    {
        $inviteId = max(0, (int)($row['id'] ?? 0));
        $userId = max(0, (int)($row['user_id'] ?? 0));
        $status = (string)($row['status'] ?? '');

        if ($inviteId <= 0 || $userId <= 0 || in_array($status, ['completed', 'revoked'], true)) {
            return '';
        }

        $cards = $this->hiddenFields($context);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<div class="actions-row">
            <form method="post" action="?page=users" data-ajax="true">
                ' . $cards . '
                <input type="hidden" name="action" value="users-copy-invite-link">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
                <input type="hidden" name="contact_method" value="auto">
                <button class="button primary" type="submit">Copy Link</button>
            </form>
            <form method="post" action="?page=users" data-ajax="true">
                ' . $cards . '
                <input type="hidden" name="action" value="users-revoke-invite">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="invite_id" value="' . HelperFramework::escape((string)$inviteId) . '">
                <button class="button danger" type="submit">Cancel</button>
            </form>
        </div>';
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'expired', 'revoked', 'locked' => 'danger',
            'pending', 'sent', 'opened', 'verified' => 'warning',
            default => 'info',
        };
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'invited.users');
    }

    private function hiddenFields(array $context): string
    {
        $html = '';
        $cardKeys = array_values(array_unique(array_merge(
            (array)($context['page']['page_cards'] ?? []),
            ['current_users', $this->key()]
        )));

        foreach ($cardKeys as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }
}
