<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(BackupAction::class, static function (GeneratedServiceClassTestHarness $harness, BackupAction $action): void {
    $none = $action->handle(
        new RequestFramework([], ['card_action' => 'Backup', 'intent' => 'unknown'], ['REQUEST_METHOD' => 'POST'], [], []),
        createTestPageServiceFramework()
    );

    $harness->assertSame([], $none->changedFacts());
    $harness->assertSame([], $none->flashMessages());

    $denied = $action->handle(
        new RequestFramework([], ['card_action' => 'Backup', 'intent' => 'create_database_backup'], ['REQUEST_METHOD' => 'POST'], [], []),
        createTestPageServiceFramework()
    );

    $harness->assertTrue(!$denied->isSuccess());
    $harness->assertSame(['backup.database'], $denied->changedFacts());

    $restoreDenied = $action->handle(
        new RequestFramework([], [
            'card_action' => 'Backup',
            'intent' => 'restore_database_backup',
            'backup_filename' => 'eel_accounts_20260706_120000.sql.zip',
            'restore_confirmation' => 'RESTORE',
        ], ['REQUEST_METHOD' => 'POST'], [], []),
        createTestPageServiceFramework()
    );

    $harness->assertTrue(!$restoreDenied->isSuccess());
    $harness->assertSame(['backup.database'], $restoreDenied->changedFacts());

    $downloadDenied = $action->handle(
        new RequestFramework([], [
            'card_action' => 'Backup',
            'intent' => 'download_database_backup',
            'backup_filename' => 'eel_accounts_20260706_120000.sql.zip',
        ], ['REQUEST_METHOD' => 'POST'], [], []),
        createTestPageServiceFramework()
    );

    $harness->assertTrue(!$downloadDenied->isSuccess());
    $harness->assertSame(['backup.database'], $downloadDenied->changedFacts());
});
