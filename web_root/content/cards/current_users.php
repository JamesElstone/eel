<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _current_usersCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'current_users';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'current_users_dashboard',
                'service' => UserManagementService::class,
                'method' => 'currentUsersDashboard',
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
        $dashboard = $this->dashboard($context);
        $users = (array)($dashboard['current_users'] ?? []);
        $roles = (array)($dashboard['roles'] ?? []);
        $currentUser = (array)($dashboard['current_user'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $rowsHtml = '';

        foreach ($users as $user) {
            $isCurrentUser = (int)($user['id'] ?? 0) === (int)($currentUser['id'] ?? 0);
            $sessionSummary = trim((string)($user['current_session_browser_label'] ?? ''));
            $sessionIp = trim((string)($user['current_session_ip_address'] ?? ''));

            if ($sessionSummary === '') {
                $sessionSummary = 'No active session';
            } elseif ($sessionIp !== '') {
                $sessionSummary .= ' (' . $sessionIp . ')';
            }

            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($user['display_name'] ?? '')) . ($isCurrentUser ? ' <span class="badge info">You</span>' : '') . '</td>
                <td>' . HelperFramework::escape((string)($user['email_address'] ?? '')) . '</td>
                <td>' . $this->roleSelectHtml($context, $user, $roles, $csrfToken) . '</td>
                <td><span class="badge ' . ((int)($user['is_active'] ?? 0) === 1 ? 'success' : 'danger') . '">' . ((int)($user['is_active'] ?? 0) === 1 ? 'Enabled' : 'Disabled') . '</span>' . (!empty($user['must_change_password']) ? ' <span class="badge warning">Password change required</span>' : '') . '</td>
                <td>' . HelperFramework::escape($sessionSummary) . '</td>
                <td>' . $this->actionsHtml($context, $user, $csrfToken) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6">No users were found.</td></tr>';
        }

        return '
            <p class="helper">Assign a role for each user, then manage access, OTP reset, and password updates from the same table.</p>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Current Session</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        ';
    }

    private function roleSelectHtml(array $context, array $user, array $roles, string $csrfToken): string
    {
        $userId = max(0, (int)($user['id'] ?? 0));
        if ($userId <= 0) {
            return '';
        }

        $currentRoleId = isset($user['role_id']) && $user['role_id'] !== null && $user['role_id'] !== ''
            ? (int)$user['role_id']
            : 0;
        $currentUser = (array)($this->dashboard($context)['current_user'] ?? []);
        $isCurrentUser = $userId === max(0, (int)($currentUser['id'] ?? 0));
        $cards = $this->hiddenFields($context);
        $optionsHtml = '';

        $optionsHtml .= '<option value="0"' . ($currentRoleId === 0 ? ' selected' : '') . '>No role assigned</option>';

        foreach ($roles as $role) {
            $roleId = (int)($role['id'] ?? 0);
            $selected = $roleId === $currentRoleId ? ' selected' : '';
            $optionsHtml .= '<option value="' . HelperFramework::escape((string)$roleId) . '"' . $selected . '>'
                . HelperFramework::escape((string)($role['role_name'] ?? ''))
                . '</option>';
        }

        if ($isCurrentUser) {
            return '<span class="badge info">Cannot change own role</span>';
        }

        return '<form method="post" action="?page=users" data-ajax="true">
            ' . $cards . '
            <input type="hidden" name="action" value="users-set-role">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
            <select class="selector-input" name="target_role_id">
                ' . $optionsHtml . '
            </select>
        </form>';
    }

    private function actionsHtml(array $context, array $user, string $csrfToken): string
    {
        $userId = max(0, (int)($user['id'] ?? 0));
        if ($userId <= 0) {
            return '';
        }

        $currentUser = (array)($this->dashboard($context)['current_user'] ?? []);
        $isCurrentUser = $userId === max(0, (int)($currentUser['id'] ?? 0));
        $cards = $this->hiddenFields($context);
        $enableState = (int)($user['is_active'] ?? 0) === 1 ? '0' : '1';
        $enableLabel = $enableState === '1' ? 'Enable' : 'Disable';
        $toggleButton = $isCurrentUser && $enableState === '0'
            ? '<button class="button primary disabled" type="button" aria-disabled="true">Disable</button>'
            : '<button class="button primary" type="submit">' . HelperFramework::escape($enableLabel) . '</button>';

        return '<div class="actions-row">
            <form method="post" action="?page=users" data-ajax="true">
                ' . $cards . '
                <input type="hidden" name="action" value="users-toggle-user">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
                <input type="hidden" name="target_state" value="' . HelperFramework::escape($enableState) . '">
                ' . $toggleButton . '
            </form>
            <form method="post" action="?page=users" data-ajax="true">
                ' . $cards . '
                <input type="hidden" name="action" value="users-reset-otp">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
                <button class="button primary" type="submit">Reset OTP</button>
            </form>
            <form method="post" action="?page=users" data-ajax="true">
                ' . $cards . '
                <input type="hidden" name="action" value="users-require-password-change">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
                <button class="button primary" type="submit">Force Password Change</button>
            </form>
            ' . ($isCurrentUser
                ? '<span class="badge info">Use Current User Details to change password</span>'
                : '<form method="post" action="?page=users" data-ajax="true" class="toolbar">
                ' . $cards . '
                <input type="hidden" name="action" value="users-set-password">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
                <input class="input" name="target_password" type="password" placeholder="New password" autocomplete="new-password" required>
                <button class="button primary" type="submit">Set Password</button>
            </form>') . '
        </div>';
    }

    private function hiddenFields(array $context): string
    {
        $html = '';

        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }

    private function dashboard(array $context): array
    {
        return (array)(($context['services'] ?? [])['current_users_dashboard'] ?? []);
    }
}
