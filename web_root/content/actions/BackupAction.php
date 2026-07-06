<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class BackupAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        return match ((string)$request->post('intent', '')) {
            'create_database_backup' => $this->createBackup($request),
            'restore_database_backup' => $this->restoreBackup($request),
            default => ActionResultFramework::none(),
        };
    }

    private function createBackup(RequestFramework $request): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canUseBackups($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return $this->error('You do not have permission to create database backups, or your security token expired.');
        }

        try {
            @set_time_limit(0);
            $backup = (new \eel_accounts\Service\DatabaseBackupService())->createBackup();
        } catch (Throwable $exception) {
            return $this->error('Database backup failed: ' . $exception->getMessage());
        }

        return ActionResultFramework::success(
            ['backup.database'],
            [[
                'type' => 'success',
                'message' => 'Database backup created: ' . (string)($backup['filename'] ?? 'backup zip'),
            ]],
            [],
            ['backup_result' => $backup]
        );
    }

    private function restoreBackup(RequestFramework $request): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canUseBackups($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return $this->error('You do not have permission to restore database backups, or your security token expired.');
        }

        $filename = trim((string)$request->post('backup_filename', ''));
        if ($filename === '') {
            return $this->error('Select a database backup to restore.');
        }

        if ((string)$request->post('restore_confirmation', '') !== 'RESTORE') {
            return $this->error('Type RESTORE to confirm the database restore.');
        }

        try {
            @set_time_limit(0);
            $restore = (new \eel_accounts\Service\DatabaseBackupService())->restoreBackup($filename);
        } catch (Throwable $exception) {
            return $this->error('Database restore failed: ' . $exception->getMessage());
        }

        return ActionResultFramework::success(
            ['backup.database'],
            [[
                'type' => 'success',
                'message' => 'Database backup restored: ' . (string)($restore['filename'] ?? 'backup zip'),
            ]],
            [],
            ['restore_result' => $restore]
        );
    }

    private function canUseBackups(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('backup', (new CardAccessFramework())->allowedCardsForUser($userId, ['backup']), true);
    }

    private function error(string $message): ActionResultFramework
    {
        return new ActionResultFramework(false, ['backup.database'], [[
            'type' => 'error',
            'message' => $message,
        ]]);
    }
}
