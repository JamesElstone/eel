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
$harness->run(\eel_accounts\Service\YearEndChecklistService::class, static function (
    GeneratedServiceClassTestHarness $harness
): void {
    $harness->check(\eel_accounts\Contract\DatabaseBackupCreatorInterface::class, 'closure invokes one injectable full backup operation', static function () use ($harness): void {
        $backup = new class implements \eel_accounts\Contract\DatabaseBackupCreatorInterface {
            public int $calls = 0;

            public function createBackup(): array
            {
                $this->calls++;
                return ['filename' => 'pre-close.sql.zip', 'size_bytes' => 1024, 'table_count' => 42];
            }
        };

        $result = $backup->createBackup();
        $harness->assertSame(1, $backup->calls);
        $harness->assertSame('pre-close.sql.zip', (string)($result['filename'] ?? ''));
        $harness->assertSame(true, is_a(\eel_accounts\Service\DatabaseBackupService::class, \eel_accounts\Contract\DatabaseBackupCreatorInterface::class, true));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'backup is after live preflight and before the first close mutation', static function () use ($harness): void {
        $source = (string)file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts'
            . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'YearEndChecklistService.php'
        );
        $methodStart = strpos($source, 'public function lockPeriod(');
        $methodEnd = strpos($source, 'private function canLockOverallStatus', (int)$methodStart);
        $method = substr($source, (int)$methodStart, (int)$methodEnd - (int)$methodStart);
        $preflight = strpos($method, '$this->preflightLockPeriod(');
        $permission = strpos($method, 'if (!$backupPermitted)');
        $backup = strpos($method, '->createBackup()');
        $firstMutation = strpos($method, '$this->applyDirectorLoanOffsetBeforeLock(');

        $harness->assertSame(true, $preflight !== false && $permission !== false && $backup !== false && $firstMutation !== false);
        $harness->assertSame(true, $preflight < $permission && $permission < $backup && $backup < $firstMutation);
        $harness->assertSame(1, substr_count($method, '->createBackup()'));
        $harness->assertSame(true, str_contains($method, 'verified, non-empty full database backup'));
        $harness->assertSame(true, str_contains($method, '$this->rollbackLockTransaction($transaction'));
        $harness->assertSame(true, str_contains($method, "'backup' => \$backup"));
    });
});
