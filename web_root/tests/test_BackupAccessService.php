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
$harness->run(\eel_accounts\Service\BackupAccessService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\BackupAccessService::class, 'is shared by manual backup and Year End closure actions', static function () use ($harness): void {
        $actions = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR;
        $backupAction = (string)file_get_contents($actions . 'BackupAction.php');
        $yearEndAction = (string)file_get_contents($actions . 'YearEndAction.php');

        $harness->assertSame(true, str_contains($backupAction, 'BackupAccessService'));
        $harness->assertSame(true, str_contains($yearEndAction, 'BackupAccessService'));
        $harness->assertSame(true, str_contains($yearEndAction, 'automatic pre-close database backup'));
    });
});
