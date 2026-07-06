<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _backupCard extends CardBaseFramework
{
    public function title(): string
    {
        return 'Database Backup';
    }

    public function helper(array $context): string
    {
        return 'Create a point-in-time SQL dump and store it as a zipped file in the sqldump folder.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'backup_status',
                'service' => \eel_accounts\Service\DatabaseBackupService::class,
                'method' => 'fetchBackupStatus',
            ],
        ];
    }

    public function invalidationFacts(): array
    {
        return ['backup.database'];
    }

    public function render(array $context): string
    {
        $status = (array)($context['services']['backup_status'] ?? []);
        $backupResult = (array)($context['backup_result'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<div class="stack">
            ' . $this->resultHtml($backupResult) . '
            ' . $this->statusHtml($status) . '
            <form method="post" action="?page=backup" data-ajax="true">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="card_action" value="Backup">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <button class="button primary" type="submit" name="intent" value="create_database_backup" data-processing-text="Creating Backup" data-processing-state="disabled">Create Backup</button>
            </form>
        </div>';
    }

    private function resultHtml(array $backupResult): string
    {
        if ($backupResult === []) {
            return '';
        }

        return '<div class="path-status ok">
            <div class="helper">Created ' . HelperFramework::escape((string)($backupResult['filename'] ?? 'backup file')) . '</div>
            <div class="path-meta">
                <div class="path-meta-item">Tables: ' . HelperFramework::escape((string)($backupResult['table_count'] ?? 0)) . '</div>
                <div class="path-meta-item">Size: ' . HelperFramework::escape($this->formatBytes((int)($backupResult['size_bytes'] ?? 0))) . '</div>
                <div class="path-meta-item">Folder: ' . HelperFramework::escape((string)($backupResult['directory'] ?? '')) . '</div>
            </div>
        </div>';
    }

    private function statusHtml(array $status): string
    {
        $directoryExists = (bool)($status['directory_exists'] ?? false);
        $directoryWritable = (bool)($status['directory_writable'] ?? false);
        $zipAvailable = (bool)($status['zip_available'] ?? false);
        $state = $zipAvailable && (!$directoryExists || $directoryWritable) ? 'ok' : 'bad';
        $detail = $state === 'ok'
            ? 'Backup output is ready.'
            : 'Backup output needs attention before a ZIP can be created.';

        return '<div class="path-status ' . HelperFramework::escape($state) . '">
            <div class="helper">' . HelperFramework::escape($detail) . '</div>
            <div class="path-meta">
                <div class="path-meta-item">
                    <span class="status-indicator"><span class="status-square ' . HelperFramework::escape($directoryExists ? 'ok' : 'warn') . '"></span>sqldump folder: ' . HelperFramework::escape($directoryExists ? 'Exists' : 'Will be created') . '</span>
                </div>
                <div class="path-meta-item">
                    <span class="status-indicator"><span class="status-square ' . HelperFramework::escape($directoryWritable || !$directoryExists ? 'ok' : 'bad') . '"></span>Write access: ' . HelperFramework::escape($directoryWritable || !$directoryExists ? 'Ready' : 'Needs attention') . '</span>
                </div>
                <div class="path-meta-item">
                    <span class="status-indicator"><span class="status-square ' . HelperFramework::escape($zipAvailable ? 'ok' : 'bad') . '"></span>ZIP support: ' . HelperFramework::escape($zipAvailable ? 'Available' : 'Unavailable') . '</span>
                </div>
            </div>
            <div class="helper">Folder: ' . HelperFramework::escape((string)($status['directory'] ?? '')) . '</div>
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1048576, 1) . ' MB';
    }
}
