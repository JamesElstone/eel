<?php
/**
 * eelKit Framework
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

    public function helper(array $context): string
    {
        return 'Assign a role for each user, then manage access, OTP reset, and password updates from the same table.';
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
        return $this->table($context)->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
        ]);
    }

    public function tables(array $context): array
    {
        return [$this->table($context)];
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('current-users')
            ->exportLimit(500)
            ->empty('No users were found.')
            ->column(
                'display_name',
                'User',
                html: fn(array $row): string => HelperFramework::escape((string)($row['display_name'] ?? ''))
                    . (!empty($row['is_current_user']) ? ' <span class="badge info">You</span>' : ''),
                export: static fn(array $row): string => (string)($row['display_name'] ?? '')
            )
            ->textColumn('email_address', 'Email')
            ->column(
                'role_label',
                'Role',
                html: fn(array $row): string => $this->roleSelectHtml($context, $row),
                export: static fn(array $row): string => (string)($row['role_label'] ?? 'No role assigned')
            )
            ->column(
                'status_label',
                'Status',
                html: static fn(array $row): string => '<span class="badge ' . ((int)($row['is_active'] ?? 0) === 1 ? 'success' : 'danger') . '">'
                    . ((int)($row['is_active'] ?? 0) === 1 ? 'Enabled' : 'Disabled')
                    . '</span>'
                    . (!empty($row['must_change_password']) ? ' <span class="badge warning">Password change required</span>' : ''),
                export: static fn(array $row): string => (string)($row['status_label'] ?? '')
            )
            ->column(
                'otp_required_label',
                'OTP',
                html: fn(array $row): string => $this->otpRequiredSelectHtml($context, $row),
                export: static fn(array $row): string => (string)($row['otp_required_label'] ?? '')
            )
            ->textColumn('session_summary', 'Current Session')
            ->column(
                'actions',
                'Actions',
                html: fn(array $row): string => $this->actionsHtml($context, $row),
                exportable: false
            );
    }

    private function roleSelectHtml(array $context, array $user): string
    {
        $userId = max(0, (int)($user['id'] ?? 0));
        if ($userId <= 0) {
            return '';
        }

        $currentRoleId = isset($user['role_id']) && $user['role_id'] !== null && $user['role_id'] !== ''
            ? (int)$user['role_id']
            : 0;
        $roles = (array)($this->dashboard($context)['roles'] ?? []);
        $isCurrentUser = !empty($user['is_current_user']);
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
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($context['page']['csrf_token'] ?? '')) . '">
            <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
            <select class="selector-input" name="target_role_id">
                ' . $optionsHtml . '
            </select>
        </form>';
    }

    private function otpRequiredSelectHtml(array $context, array $user): string
    {
        $userId = max(0, (int)($user['id'] ?? 0));
        if ($userId <= 0) {
            return '';
        }

        $otpRequired = (int)($user['otp_required'] ?? 1) === 1;
        $cards = $this->hiddenFields($context);

        return '<form method="post" action="?page=users" data-ajax="true">
            ' . $cards . '
            <input type="hidden" name="action" value="users-set-otp-required">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($context['page']['csrf_token'] ?? '')) . '">
            <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
            <select class="selector-input" name="otp_required">
                <option value="1"' . ($otpRequired ? ' selected' : '') . '>Required</option>
                <option value="0"' . (!$otpRequired ? ' selected' : '') . '>Optional</option>
            </select>
        </form>';
    }

    private function actionsHtml(array $context, array $user): string
    {
        $userId = max(0, (int)($user['id'] ?? 0));
        if ($userId <= 0) {
            return '';
        }

        $isCurrentUser = !empty($user['is_current_user']);
        $cards = $this->hiddenFields($context);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
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
                : '<form method="post" action="?page=users" data-ajax="true" class="input-action-row">
                ' . $cards . '
                <input type="hidden" name="action" value="users-set-password">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="target_user_id" value="' . HelperFramework::escape((string)$userId) . '">
                <input class="input" name="target_password" type="password" placeholder="New password" autocomplete="new-password" required>
                <button class="button button-inline primary" type="submit">Set Password</button>
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

    private function rows(array $context): array
    {
        $dashboard = $this->dashboard($context);
        $currentUser = (array)($dashboard['current_user'] ?? []);
        $rolesById = [];
        $rows = [];

        foreach ((array)($dashboard['roles'] ?? []) as $role) {
            $roleId = (int)($role['id'] ?? 0);
            if ($roleId > 0) {
                $rolesById[$roleId] = (string)($role['role_name'] ?? '');
            }
        }

        foreach ((array)($dashboard['current_users'] ?? []) as $user) {
            if (!is_array($user)) {
                continue;
            }

            $user['is_current_user'] = (int)($user['id'] ?? 0) === (int)($currentUser['id'] ?? 0);
            $user['role_label'] = $this->roleLabel($user, $rolesById);
            $user['status_label'] = $this->statusLabel($user);
            $user['otp_required_label'] = (int)($user['otp_required'] ?? 1) === 1 ? 'Required' : 'Optional';
            $user['session_summary'] = $this->sessionSummary($user);
            $rows[] = $user;
        }

        return $rows;
    }

    private function roleLabel(array $user, array $rolesById): string
    {
        $roleId = isset($user['role_id']) && $user['role_id'] !== null && $user['role_id'] !== ''
            ? (int)$user['role_id']
            : 0;

        return $roleId > 0 && trim((string)($rolesById[$roleId] ?? '')) !== ''
            ? (string)$rolesById[$roleId]
            : 'No role assigned';
    }

    private function statusLabel(array $user): string
    {
        $parts = [(int)($user['is_active'] ?? 0) === 1 ? 'Enabled' : 'Disabled'];

        if (!empty($user['must_change_password'])) {
            $parts[] = 'Password change required';
        }

        return implode(' | ', $parts);
    }

    private function sessionSummary(array $user): string
    {
        $sessionSummary = trim((string)($user['current_session_browser_label'] ?? ''));
        $sessionIp = trim((string)($user['current_session_ip_address'] ?? ''));

        if ($sessionSummary === '') {
            return 'No active session';
        }

        return $sessionIp !== '' ? $sessionSummary . ' (' . $sessionIp . ')' : $sessionSummary;
    }
}
