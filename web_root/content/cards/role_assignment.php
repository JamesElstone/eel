<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _role_assignmentCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'role_assignment';
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
        $dashboard = (array)($context['page']['roles_dashboard'] ?? []);
        $roles = (array)($dashboard['roles'] ?? []);
        $selectedRoleId = (int)($dashboard['selected_role_id'] ?? 0);
        $matrixRows = (array)($dashboard['matrix_rows'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $cards = $this->hiddenFields($context);
        $roleOptionsHtml = '';
        $rowsHtml = '';

        foreach ($roles as $role) {
            $roleId = (int)($role['id'] ?? 0);
            $selected = $roleId === $selectedRoleId ? ' selected' : '';
            $roleOptionsHtml .= '<option value="' . HelperFramework::escape((string)$roleId) . '"' . $selected . '>'
                . HelperFramework::escape((string)($role['role_name'] ?? ''))
                . '</option>';
        }

        foreach ($matrixRows as $row) {
            $cardKey = (string)($row['card_key'] ?? '');
            $isAllowed = !empty($row['is_allowed']);
            $isForced = !empty($row['is_forced']);
            $disabled = $isForced ? ' disabled' : '';

            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['card_label'] ?? $cardKey)) . '</td>
                <td class="cell-fit">
                    <form method="post" action="?page=roles" data-ajax="true">
                        ' . $cards . '
                        <input type="hidden" name="action" value="roles-set-card-permission">
                        <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                        <input type="hidden" name="role_id" value="' . HelperFramework::escape((string)$selectedRoleId) . '">
                        <input type="hidden" name="card_key" value="' . HelperFramework::escape($cardKey) . '">
                        <select class="selector-input" name="permission_state" onchange="this.form.requestSubmit()"' . $disabled . '>
                            <option value="allowed"' . ($isAllowed ? ' selected' : '') . '>Allowed</option>
                            <option value="denied"' . (!$isAllowed ? ' selected' : '') . '>Denied</option>
                        </select>
                    </form>
                </td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="2">' . ($selectedRoleId === 0 ? 'Select a role to manage card access.' : 'No cards were found.') . '</td></tr>';
        }

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Role Assignment</h2>
                </div>
            </div>
            <div class="card-body stack">
                <form method="get" action="?" data-ajax="true" class="toolbar">
                    <input type="hidden" name="page" value="roles">
                    ' . $cards . '
                    <label class="helper" for="roles-role-selector">Role</label>
                    <select class="selector-input" id="roles-role-selector" name="role_id" onchange="this.form.requestSubmit()">
                        ' . $roleOptionsHtml . '
                    </select>
                </form>
                <form method="post" action="?page=roles" data-ajax="true" class="toolbar">
                    ' . $cards . '
                    <input type="hidden" name="action" value="roles-create-role">
                    <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                    <input class="input" type="text" name="new_role_name" placeholder="New role name" required>
                    <button class="button" type="submit">Add Role</button>
                </form>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Card</th>
                                <th>Access</th>
                            </tr>
                        </thead>
                        <tbody>' . $rowsHtml . '</tbody>
                    </table>
                </div>
            </div>
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
