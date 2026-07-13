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
            'download_database_backup' => $this->downloadBackup($request),
            default => ActionResultFramework::none(),
        };
    }

    private function createBackup(RequestFramework $request): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!(new \eel_accounts\Service\BackupAccessService())->canUseBackups($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
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

        if (!(new \eel_accounts\Service\BackupAccessService())->canUseBackups($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
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

    private function downloadBackup(RequestFramework $request): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!(new \eel_accounts\Service\BackupAccessService())->canUseBackups($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return $this->error('You do not have permission to download database backups, or your security token expired.');
        }

        $filename = trim((string)$request->post('backup_filename', ''));
        if ($filename === '') {
            return $this->error('Select a database backup to download.');
        }

        try {
            $download = (new \eel_accounts\Service\DatabaseBackupService())->backupFileForDownload($filename);
        } catch (Throwable $exception) {
            return $this->error('Database backup download failed: ' . $exception->getMessage());
        }

        $path = (string)($download['file'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $this->error('The selected database backup could not be read.');
        }

        $safeFilename = basename((string)($download['filename'] ?? 'database-backup.sql.zip'));
        $sizeBytes = (int)($download['size_bytes'] ?? (filesize($path) ?: 0));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . (string)$sizeBytes);
        readfile($path);
        exit;
    }

    private function error(string $message): ActionResultFramework
    {
        return new ActionResultFramework(false, ['backup.database'], [[
            'type' => 'error',
            'message' => $message,
        ]]);
    }
}
