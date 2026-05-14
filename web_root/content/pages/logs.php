<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _logs extends PageContextFramework
{
    public function id(): string
    {
        return 'logs';
    }

    public function title(): string
    {
        return 'Logs';
    }

    public function subtitle(): string
    {
        return 'Review system audit and history activity recorded by the application.';
    }

    public function services(): array
    {
        return parent::services();
    }

    public function cards(): array
    {
        return [
            'activity',
            'user_account_audit_log',
            'user_logon_history_log',
        ];
    }
}
