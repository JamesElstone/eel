<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _user_login_lockoutsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'user_login_lockouts';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'login_lockouts_dashboard',
                'service' => UserManagementService::class,
                'method' => 'loginLockoutsDashboard',
            ],
        ];
    }

    public function helper(array $context): string
    {
        return 'Active login lockouts caused by repeated failed password attempts.';
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
            ->filename('user-login-lockouts')
            ->exportLimit(200)
            ->empty('No users are currently locked out.')
            ->textColumn('display_name', 'User')
            ->textColumn('email_address', 'Email')
            ->textColumn('consecutive_failed_password_attempts', 'Attempts', fallback: '0', exportType: 'number')
            ->textColumn('locked_scopes_label', 'Scope')
            ->textColumn('lock_reasons_label', 'Reason')
            ->textColumn('lock_expires_at', 'Expires')
            ->column(
                'action',
                'Action',
                html: fn(array $row): string => $this->resetButtonHtml($context, max(0, (int)($row['user_id'] ?? 0))),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'user.login.lockouts');
    }

    private function rows(array $context): array
    {
        $dashboard = (array)(($context['services'] ?? [])['login_lockouts_dashboard'] ?? []);
        $rows = [];

        foreach ((array)($dashboard['locked_users'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $userId = max(0, (int)($row['user_id'] ?? 0));
            if ($userId <= 0) {
                continue;
            }

            $row['locked_scopes_label'] = $this->scopeLabels((string)($row['locked_scopes'] ?? ''));
            $row['lock_reasons_label'] = $this->reasonLabels((string)($row['lock_reasons'] ?? ''));
            $rows[] = $row;
        }

        return $rows;
    }

    private function resetButtonHtml(array $context, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        return '<form method="post" action="?page=users" data-ajax="true">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="action" value="users-reset-login-lockout">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($context['page']['csrf_token'] ?? '')) . '">
            <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
            <button class="button primary" type="submit">Reset Lockout</button>
        </form>';
    }

    private function scopeLabels(string $scopes): string
    {
        return $this->commaSeparatedLabels($scopes);
    }

    private function reasonLabels(string $reasons): string
    {
        return $this->commaSeparatedLabels(str_replace('password_failures_', '', $reasons));
    }

    private function commaSeparatedLabels(string $values): string
    {
        $labels = [];

        foreach (explode(',', $values) as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $labels[] = HelperFramework::labelFromKey($value, '_');
        }

        return $labels === [] ? 'Unknown' : implode(', ', array_unique($labels));
    }

    private function hiddenFields(array $context): string
    {
        $html = '';

        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }
}
