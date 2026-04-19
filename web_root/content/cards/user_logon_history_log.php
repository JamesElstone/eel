<?php
declare(strict_types=1);

final class _user_logon_history_logCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'user_logon_history_log';
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
        $rows = (array)($context['page']['logon_rows'] ?? []);
        $tableRows = '';

        foreach ($rows as $row) {
            $eventType = HelperFramework::labelFromKey((string)($row['event_type'] ?? ''), '_');
            $badgeClass = (int)($row['success'] ?? 0) === 1 ? 'success' : 'danger';
            $principal = trim((string)($row['user_display_name'] ?? ''));

            if ($principal === '') {
                $principal = trim((string)($row['attempted_email_address'] ?? ''));
            }

            if ($principal === '') {
                $principal = 'Unknown user';
            }

            $agentDetails = trim((string)($row['browser_label'] ?? ''));
            $userAgent = trim((string)($row['user_agent'] ?? ''));
            if ($agentDetails === '') {
                $agentDetails = 'Unknown browser';
            }
            if ($userAgent !== '') {
                $agentDetails .= '<div class="helper">' . HelperFramework::escape($userAgent) . '</div>';
            }

            $reason = trim((string)($row['reason'] ?? ''));
            if ($reason === '') {
                $reason = '&nbsp;';
            } else {
                $reason = HelperFramework::escape($reason);
            }

            $tableRows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['occurred_at'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($principal) . '</td>
                <td><span class="badge ' . $badgeClass . '">' . HelperFramework::escape($eventType) . '</span></td>
                <td>' . HelperFramework::escape((string)($row['ip_address'] ?? '')) . '</td>
                <td>' . $agentDetails . '</td>
                <td>' . $reason . '</td>
            </tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="6">No user logon history has been recorded yet.</td></tr>';
        }

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">All Users Logon History</h2>
                </div>
            </div>
            <div class="card-body stack">
                <p class="helper">Recent login, logout, OTP, and session events captured for user accounts.</p>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Event</th>
                                <th>IP</th>
                                <th>Browser Agent Details</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>' . $tableRows . '</tbody>
                    </table>
                </div>
            </div>
        </div>';
    }
}
