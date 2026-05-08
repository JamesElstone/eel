<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _user_account_audit_logCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'user_account_audit_log';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'audit_rows',
                'service' => UserHistoryStore::class,
                'method' => 'fetchRecentAccountAudit',
                'params' => [
                    'limit' => 200,
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $rows = (array)(($context['services'] ?? [])['audit_rows'] ?? []);
        $pagination = HelperFramework::paginateArray(
            $rows,
            $this->paginationPage($context),
            self::PAGE_SIZE
        );
        $rows = (array)$pagination['items'];
        $tableRows = '';

        foreach ($rows as $row) {
            $details = $this->detailsSummary((string)($row['details_json'] ?? ''));
            $userAgent = trim((string)($row['user_agent'] ?? ''));
            $userAgentHtml = $userAgent !== ''
                ? '<div class="helper log-agent-preview" title="' . HelperFramework::escape($userAgent) . '">' . HelperFramework::escape($this->compactText($userAgent, 96)) . '</div>'
                : '';

            $tableRows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['created_at'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['affected_user_display_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['actor_user_display_name'] ?? 'System')) . '</td>
                <td><span class="badge info">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['action_type'] ?? ''), '_')) . '</span></td>
                <td>' . HelperFramework::escape((string)($row['reason'] ?? '')) . ($details !== '' ? '<div class="helper">' . HelperFramework::escape($details) . '</div>' : '') . '</td>
                <td class="log-agent-cell">' . HelperFramework::escape((string)($row['ip_address'] ?? '')) . $userAgentHtml . '</td>
            </tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="6">No user account audit events have been recorded yet.</td></tr>';
        }

        return '
            <p class="helper">Recent user-account changes such as profile updates, password changes, OTP resets, and enable or disable actions.</p>
            <div class="table-scroll user-account-audit-table">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Affected<br>User</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Reason</th>
                            <th>IP / User Agent</th>
                        </tr>
                    </thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
            </div>
            ' . $this->paginationControls($context, $pagination, 'User account audit events') . '
        ';
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

    private function compactText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if ($value === '' || mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(1, $maxLength - 1))) . '...';
    }
}
