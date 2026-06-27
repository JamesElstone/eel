<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _restore_deleted_userCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'restore_deleted_user';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'restore_deleted_users_dashboard',
                'service' => UserManagementService::class,
                'method' => 'restorableArchivedUsersDashboard',
            ],
        ];
    }

    public function title(): string
    {
        return 'Restore Deleted User';
    }

    public function helper(array $context): string
    {
        return 'Restore an archived user to pending invitation and send a fresh invite.';
    }

    public function render(array $context): string
    {
        $users = (array)((($context['services'] ?? [])['restore_deleted_users_dashboard'] ?? [])['users'] ?? []);

        if ($users === []) {
            return '<p class="helper">No deleted users are ready to restore.</p>';
        }

        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<form method="post" action="?page=users" data-ajax="true" class="form-grid">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="action" value="users-restore-deleted-user">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <div class="form-row full">
                <label for="restore-deleted-user-target">Deleted user</label>
                <select class="selector-input select-placeholder-muted" id="restore-deleted-user-target" name="target_user_id" required>
                    <option value="" disabled selected hidden>Select User to restore</option>
                    ' . $this->userOptionsHtml($users) . '
                </select>
            </div>
            <div class="form-row full">
                <button class="button primary" type="submit">Restore</button>
            </div>
        </form>';
    }

    private function userOptionsHtml(array $users): string
    {
        $html = '';

        foreach ($users as $user) {
            $userId = max(0, (int)($user['id'] ?? 0));
            $label = trim((string)($user['option_label'] ?? ''));
            if ($userId <= 0 || $label === '') {
                continue;
            }

            $html .= '<option value="' . HelperFramework::escape((string)$userId) . '">'
                . HelperFramework::escape($label)
                . '</option>';
        }

        return $html;
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
