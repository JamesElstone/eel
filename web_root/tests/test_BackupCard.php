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

$harness->run(_backupCard::class, static function (GeneratedServiceClassTestHarness $harness, _backupCard $card): void {
    $html = $card->render([
        'page' => [
            'page_id' => 'backup',
            'page_cards' => ['backup'],
            'csrf_token' => 'test-token',
        ],
        'services' => [
            'backup_status' => [
                'directory' => PROJECT_ROOT . 'sqldump',
                'directory_exists' => false,
                'directory_writable' => false,
                'zip_available' => true,
                'recent_backups' => [],
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'name="card_action" value="Backup"'));
    $harness->assertTrue(str_contains($html, 'name="intent" value="create_database_backup"'));
    $harness->assertTrue(str_contains($html, 'name="csrf_token"'));
    $harness->assertTrue(str_contains($html, 'data-processing-state="disabled"'));
    $harness->assertTrue(str_contains($html, 'sqldump folder'));
});
