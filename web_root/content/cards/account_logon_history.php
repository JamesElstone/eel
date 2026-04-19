<?php
declare(strict_types=1);

final class _account_logon_historyCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'account_logon_history';
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
        $rows = (array)($dashboard['logon_history'] ?? []);
        $tableRows = '';

        foreach ($rows as $row) {
            $browser = trim((string)($row['browser_label'] ?? ''));
            $userAgent = trim((string)($row['user_agent'] ?? ''));
            $browserDetails = $browser !== '' ? $browser : 'Unknown browser';

            if ($userAgent !== '') {
                $browserDetails .= '<div class="helper">' . HelperFramework::escape($userAgent) . '</div>';
            }

            $tableRows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['occurred_at'] ?? '')) . '</td>
                <td><span class="badge ' . ((int)($row['success'] ?? 0) === 1 ? 'success' : 'danger') . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['event_type'] ?? ''), '_')) . '</span></td>
                <td>' . HelperFramework::escape((string)($row['ip_address'] ?? '')) . '</td>
                <td>' . $browserDetails . '</td>
            </tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="4">No account logon history has been recorded yet.</td></tr>';
        }

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Account Logon History</h2>
                </div>
            </div>
            <div class="card-body stack">
                <p class="helper">Recent account events for your user, including login outcomes, OTP steps, and sign-outs.</p>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Event</th>
                                <th>IP</th>
                                <th>Browser Agent Details</th>
                            </tr>
                        </thead>
                        <tbody>' . $tableRows . '</tbody>
                    </table>
                </div>
            </div>
        </div>';
    }
}
