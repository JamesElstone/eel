<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _current_usersCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'current_users';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $dashboard = (array)($context['page']['users_dashboard'] ?? []);
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
                <td><span class="badge ' . ((int)($user['is_active'] ?? 0) === 1 ? 'success' : 'danger') . '">' . ((int)($user['is_active'] ?? 0) === 1 ? 'Enabled' : 'Disabled') . '</span></td>
                <td>' . HelperFramework::escape($sessionSummary) . '</td>
                <td>' . $this->actionsHtml($context, $user, $csrfToken) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6">No users were found.</td></tr>';
        }

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Current Users</h2>
                </div>
            </div>
            <div class="card-body stack">
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
            </div>
        </div>';
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
        $currentUser = (array)($context['page']['users_dashboard']['current_user'] ?? []);
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
            <select class="selector-input" name="target_role_id" onchange="this.form.requestSubmit()">
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

        $currentUser = (array)($context['page']['users_dashboard']['current_user'] ?? []);
        $isCurrentUser = $userId === max(0, (int)($currentUser['id'] ?? 0));
        $cards = $this->hiddenFields($context);
        $enableState = (int)($user['is_active'] ?? 0) === 1 ? '0' : '1';
        $enableLabel = $enableState === '1' ? 'Enable' : 'Disable';
        $toggleButton = $isCurrentUser && $enableState === '0'
            ? '<button class="button disabled" type="button" aria-disabled="true">Disable</button>'
            : '<button class="button" type="submit">' . HelperFramework::escape($enableLabel) . '</button>';

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
                <button class="button" type="submit">Reset OTP</button>
            </form>
            ' . ($isCurrentUser
                ? '<span class="badge info">Use Current User Details to change password</span>'
                : '<form method="post" action="?page=users" data-ajax="true" class="toolbar">
                ' . $cards . '
                <input type="hidden" name="action" value="users-set-password">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
                <input class="input" name="target_password" type="password" placeholder="New password" autocomplete="new-password" required>
                <button class="button" type="submit">Set Password</button>
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
}
