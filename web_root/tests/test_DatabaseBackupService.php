<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

if (!class_exists('BackupServiceQuoteThrowingPdo', false)) {
    final class BackupServiceQuoteThrowingPdo extends PDO
    {
        public function __construct()
        {
        }

        public function quote(string $string, int $type = PDO::PARAM_STR): string|false
        {
            throw new PDOException('driver does not support quoting');
        }
    }
}

$harness = new GeneratedServiceClassTestHarness();

$harness->run(\eel_accounts\Service\DatabaseBackupService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DatabaseBackupService $service
): void {
    $status = $service->fetchBackupStatus();

    $harness->assertTrue(is_array($status));
    $harness->assertTrue(array_key_exists('directory', $status));
    $harness->assertTrue(array_key_exists('zip_available', $status));
    $harness->assertTrue(array_key_exists('recent_backups', $status));

    $method = new ReflectionMethod($service, 'sqlLiteral');
    $method->setAccessible(true);

    $harness->assertSame(
        "'O''Brien\\\\Tools\\nBackup'",
        $method->invoke($service, new BackupServiceQuoteThrowingPdo(), "O'Brien\\Tools\nBackup")
    );
});
