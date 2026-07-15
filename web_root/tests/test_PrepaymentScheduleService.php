<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'migrateDb.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(
    \eel_accounts\Service\PrepaymentScheduleService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\PrepaymentScheduleService::class,
            'prepayment migration avoids nested prepared statements under PDO ODBC',
            static function () use ($harness): void {
                $migrationPath = dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR . 'db_schema'
                    . DIRECTORY_SEPARATOR . 'migrations'
                    . DIRECTORY_SEPARATOR . '2026_07_14_002_prepayment_schedules.sql';
                $sql = (string)file_get_contents($migrationPath);
                $statements = splitMigrationSql($sql);

                $harness->assertSame(13, count($statements));
                $harness->assertSame(false, preg_match('/\b(?:PREPARE|EXECUTE|DEALLOCATE)\b/i', $sql) === 1);
                $harness->assertTrue(str_contains($sql, "LOWER(TRIM(name)) = 'prepayments'"));
                $harness->assertTrue(str_contains($sql, 'FOREIGN KEY IF NOT EXISTS (current_schedule_id)'));
                $harness->assertTrue(str_contains($sql, 'calculation_version smallint(5) unsigned NOT NULL DEFAULT 1'));
                $harness->assertTrue(str_contains($sql, 'MODIFY calculation_version smallint(5) unsigned NOT NULL DEFAULT 2'));

                foreach ($statements as $statement) {
                    $harness->assertSame(
                        false,
                        preg_match('/^\s*(?:SET\s+@|PREPARE\b|EXECUTE\b|DEALLOCATE\b)/i', $statement) === 1
                    );
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\PrepaymentScheduleService::class,
            'repair migration restores the partially applied schedule version without posting accounting entries',
            static function () use ($harness): void {
                $migrationPath = dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR . 'db_schema'
                    . DIRECTORY_SEPARATOR . 'migrations'
                    . DIRECTORY_SEPARATOR . '2026_07_15_001_prepayment_schedule_repair.sql';
                $sql = (string)file_get_contents($migrationPath);

                $harness->assertTrue(str_contains($sql, 'ADD COLUMN IF NOT EXISTS calculation_version'));
                $harness->assertTrue(str_contains($sql, 'DEFAULT 1'));
                $harness->assertTrue(str_contains($sql, 'MODIFY calculation_version'));
                $harness->assertTrue(str_contains($sql, 'DEFAULT 2'));
                $harness->assertTrue(str_contains($sql, 'ADD UNIQUE KEY IF NOT EXISTS uq_prepayment_schedules_review_version'));
                $harness->assertTrue(str_contains($sql, 'FOREIGN KEY IF NOT EXISTS (schedule_period_id)'));
                $harness->assertTrue(str_contains($sql, 'FOREIGN KEY IF NOT EXISTS (current_schedule_id)'));
                $harness->assertTrue(str_contains($sql, "'tax_prepayment_treatment'"));
                $harness->assertSame(false, preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+)?(?:prepayment_schedules|prepayment_schedule_allocations|journals|journal_lines)\b/i', $sql) === 1);
                $harness->assertSame(false, preg_match('/\b(?:PREPARE|EXECUTE|DEALLOCATE)\b/i', $sql) === 1);
            }
        );
    }
);
