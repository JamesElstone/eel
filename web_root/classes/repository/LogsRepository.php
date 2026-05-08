<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class LogsRepository
{
    public function fetchRecentLogonHistory(int $limit = 100, int $userId = 0): array
    {
        if (!InterfaceDB::tableExists('user_logon_history')) {
            return [];
        }

        $where = '';
        $params = [];

        if ($userId > 0) {
            $where = ' WHERE history.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        return InterfaceDB::fetchAll(
            'SELECT
                history.id,
                history.user_id,
                history.attempted_email_address,
                history.event_type,
                history.success,
                history.reason,
                history.session_token_hash,
                history.device_id,
                history.ip_address,
                history.user_agent,
                history.browser_label,
                history.occurred_at,
                COALESCE(users.display_name, \'\') AS user_display_name
             FROM user_logon_history history
             LEFT JOIN users
                ON users.id = history.user_id
             ' . $where . '
             ORDER BY history.occurred_at DESC, history.id DESC
             LIMIT ' . FormattingFramework::normaliseLimit($limit),
            $params
        );
    }
}
