<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(_backups_availableCard::class, static function (GeneratedServiceClassTestHarness $harness, _backups_availableCard $card): void {
    $services = $card->services();
    $harness->assertSame('Backups Available', $card->title());
    $harness->assertSame('available_backups', (string)($services[0]['key'] ?? ''));
    $harness->assertSame(\eel_accounts\Service\DatabaseBackupService::class, (string)($services[0]['service'] ?? ''));
    $harness->assertSame('fetchAvailableBackups', (string)($services[0]['method'] ?? ''));

    $html = $card->render([
        'page' => [
            'page_id' => 'backup',
            'page_cards' => ['backup', 'backups_available'],
            'csrf_token' => 'test-token',
        ],
        'services' => [
            'available_backups' => backupRows(),
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
    $harness->assertTrue(str_contains($html, 'Database backups'));
    $harness->assertTrue(str_contains($html, '1-8 of 9'));
    $harness->assertTrue(strpos($html, 'eel_accounts_20260714_120000.sql.zip') < strpos($html, 'eel_accounts_20260713_120000.sql.zip'));
    $harness->assertTrue(str_contains($html, 'name="card_action" value="Backup"'));
    $harness->assertTrue(str_contains($html, 'name="intent" value="restore_database_backup"'));
    $harness->assertTrue(str_contains($html, 'name="backup_filename"'));
    $harness->assertTrue(str_contains($html, 'placeholder="RESTORE"'));
    $harness->assertTrue(str_contains($html, 'name="csrf_token" value="test-token"'));

    $tables = $card->tables([
        'page' => [
            'page_id' => 'backup',
            'page_cards' => ['backup', 'backups_available'],
            'csrf_token' => 'test-token',
        ],
        'services' => [
            'available_backups' => backupRows(),
        ],
    ]);
    $harness->assertTrue($tables[0] instanceof TableFramework);
    $csv = $tables[0]->exportCsv();
    $harness->assertTrue(str_contains($csv, 'eel_accounts_20260714_120000.sql.zip'));
    $harness->assertTrue(!str_contains($csv, 'Restore'));
});

function backupRows(): array
{
    $rows = [];
    foreach (range(0, 8) as $index) {
        $day = 14 - $index;
        $rows[] = [
            'filename' => 'eel_accounts_202607' . str_pad((string)$day, 2, '0', STR_PAD_LEFT) . '_120000.sql.zip',
            'path' => PROJECT_ROOT . 'sqldump' . DIRECTORY_SEPARATOR . 'backup_' . $index . '.sql.zip',
            'restore_key' => hash('sha256', (string)$index),
            'size_bytes' => 1024 + $index,
            'created_at' => '2026-07-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT) . ' 12:00:00',
        ];
    }

    return array_reverse($rows);
}
