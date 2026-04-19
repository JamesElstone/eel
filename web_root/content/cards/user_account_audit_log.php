<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _user_account_audit_logCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'user_account_audit_log';
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
        $rows = (array)($context['page']['audit_rows'] ?? []);
        $tableRows = '';

        foreach ($rows as $row) {
            $details = $this->detailsSummary((string)($row['details_json'] ?? ''));
            $userAgent = trim((string)($row['user_agent'] ?? ''));

            $tableRows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['created_at'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['affected_user_display_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['actor_user_display_name'] ?? 'System')) . '</td>
                <td><span class="badge info">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['action_type'] ?? ''), '_')) . '</span></td>
                <td>' . HelperFramework::escape((string)($row['reason'] ?? '')) . ($details !== '' ? '<div class="helper">' . HelperFramework::escape($details) . '</div>' : '') . '</td>
                <td>' . HelperFramework::escape((string)($row['ip_address'] ?? '')) . ($userAgent !== '' ? '<div class="helper">' . HelperFramework::escape($userAgent) . '</div>' : '') . '</td>
            </tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="6">No user account audit events have been recorded yet.</td></tr>';
        }

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">All Users Account Audit</h2>
                </div>
            </div>
            <div class="card-body stack">
                <p class="helper">Recent user-account changes such as profile updates, password changes, OTP resets, and enable or disable actions.</p>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Affected User</th>
                                <th>Actor</th>
                                <th>Action</th>
                                <th>Reason</th>
                                <th>IP / User Agent</th>
                            </tr>
                        </thead>
                        <tbody>' . $tableRows . '</tbody>
                    </table>
                </div>
            </div>
        </div>';
    }

    private function detailsSummary(string $detailsJson): string
    {
        $detailsJson = trim($detailsJson);
        if ($detailsJson === '') {
            return '';
        }

        $decoded = json_decode($detailsJson, true);
        if (!is_array($decoded) || $decoded === []) {
            return '';
        }

        $parts = [];

        foreach ($decoded as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $parts[] = str_replace('_', ' ', (string)$key) . ': ' . (string)$value;
        }

        return implode(' | ', $parts);
    }
}
