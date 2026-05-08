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
        $dashboard = (array)(($context['services'] ?? [])['login_lockouts_dashboard'] ?? []);
        $lockedUsers = (array)($dashboard['locked_users'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $rowsHtml = '';

        foreach ($lockedUsers as $lockedUser) {
            $userId = max(0, (int)($lockedUser['user_id'] ?? 0));
            if ($userId <= 0) {
                continue;
            }

            $scopes = $this->scopeLabels((string)($lockedUser['locked_scopes'] ?? ''));
            $reasons = $this->reasonLabels((string)($lockedUser['lock_reasons'] ?? ''));
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($lockedUser['display_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($lockedUser['email_address'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($lockedUser['consecutive_failed_password_attempts'] ?? '0')) . '</td>
                <td>' . HelperFramework::escape($scopes) . '</td>
                <td>' . HelperFramework::escape($reasons) . '</td>
                <td>' . HelperFramework::escape((string)($lockedUser['lock_expires_at'] ?? '')) . '</td>
                <td>' . $this->resetButtonHtml($context, $userId, $csrfToken) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="7">No users are currently locked out.</td></tr>';
        }

        return '
            <p class="helper">Active login lockouts caused by repeated failed password attempts.</p>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Attempts</th>
                            <th>Scope</th>
                            <th>Reason</th>
                            <th>Expires</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        ';
    }

    private function resetButtonHtml(array $context, int $userId, string $csrfToken): string
    {
        return '<form method="post" action="?page=users" data-ajax="true">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="action" value="users-reset-login-lockout">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
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
