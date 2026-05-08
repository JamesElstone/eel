<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _user_logon_history_logCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'user_logon_history_log';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'logon_rows',
                'service' => LogsRepository::class,
                'method' => 'fetchRecentLogonHistory',
                'params' => [
                    'limit' => 200,
                    'userId' => ':user_logon_history_log.selected_user_id',
                ],
            ],
            [
                'key' => 'users',
                'service' => UserAuthenticationService::class,
                'method' => 'listUsers',
            ],
        ];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext[$this->key()]['selected_user_id'] = max(0, (int)$request->input(
            'logon_history_user_id',
            $pageContext[$this->key()]['selected_user_id'] ?? 0
        ));

        return $pageContext;
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
        $rows = (array)(($context['services'] ?? [])['logon_rows'] ?? []);
        $users = (array)(($context['services'] ?? [])['users'] ?? []);
        $selectedUserId = max(0, (int)($context[$this->key()]['selected_user_id'] ?? 0));
        $pagination = HelperFramework::paginateArray(
            $rows,
            $this->paginationPage($context),
            self::PAGE_SIZE
        );
        $rows = (array)$pagination['items'];
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

        return '
            <p class="helper">Recent login, logout, OTP, and session events captured for user accounts.</p>
            ' . $this->filterControls($context, $users, $selectedUserId) . '
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
            ' . $this->paginationControls(
                $context,
                $pagination,
                'User logon events',
                null,
                [
                    'card_action' => 'UserLogonHistory',
                    'logon_history_user_id' => (string)$selectedUserId,
                ]
            ) . '
        ';
    }

    private function filterControls(array $context, array $users, int $selectedUserId): string
    {
        $optionsHtml = '<option value="0"' . ($selectedUserId === 0 ? ' selected' : '') . '>All users</option>';

        foreach ($users as $user) {
            $userId = (int)($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $label = trim((string)($user['display_name'] ?? ''));
            $email = trim((string)($user['email_address'] ?? ''));

            if ($label === '') {
                $label = $email !== '' ? $email : 'User #' . $userId;
            } elseif ($email !== '') {
                $label .= ' (' . $email . ')';
            }

            $optionsHtml .= '<option value="' . HelperFramework::escape((string)$userId) . '"' . ($selectedUserId === $userId ? ' selected' : '') . '>'
                . HelperFramework::escape($label)
                . '</option>';
        }

        return '<form method="post" data-ajax="true" class="toolbar">
            <input type="hidden" name="card_action" value="UserLogonHistory">
            <input type="hidden" name="_pagination" value="1">
            <input type="hidden" name="page" value="' . HelperFramework::escape((string)($context['page']['page_id'] ?? '')) . '">
            ' . $this->hiddenCardFields($context) . '
            <div class="form-row">
                <label for="user-logon-history-user-id">User</label>
                <select class="selector-input" id="user-logon-history-user-id" name="logon_history_user_id">
                    ' . $optionsHtml . '
                </select>
            </div>
        </form>';
    }

    private function hiddenCardFields(array $context): string
    {
        $html = '';

        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }
}
