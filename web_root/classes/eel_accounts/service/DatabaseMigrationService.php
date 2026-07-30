<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class DatabaseMigrationService
{
    /** @return array{success:bool,exit_code:int,output:string} */
    public function runOutstanding(?\Closure $progress = null): array
    {
        $script = rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'tools'
            . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'migrateDb.php';
        if (!is_file($script) || !is_executable((string)PHP_BINARY)) {
            throw new \RuntimeException('The database migration runner is unavailable.');
        }
        $progress?->__invoke('Applying outstanding database migrations…', 99);
        $command = escapeshellarg((string)PHP_BINARY) . ' ' . escapeshellarg($script);
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, rtrim((string)PROJECT_ROOT, '\\/'));
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the database migration runner.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $output = trim((string)$stdout . "\n" . (string)$stderr);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Outstanding database migrations failed: ' . ($output !== '' ? $output : 'exit code ' . $exitCode));
        }
        $migratedArchives = (new TransmissionArchiveService())
            ->migrateAllCompaniesHousePreflightBundles();
        if ($migratedArchives > 0) {
            $progress?->__invoke(
                'Migrated ' . $migratedArchives . ' Companies House transmission archive bundle(s).',
                100
            );
        }
        return ['success' => true, 'exit_code' => $exitCode, 'output' => $output];
    }
}
