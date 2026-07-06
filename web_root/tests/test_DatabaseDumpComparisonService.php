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

$harness->run(\eel_accounts\Service\DatabaseDumpComparisonService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DatabaseDumpComparisonService $service
): void {
    $mysqldumpStyle = <<<'SQL'
-- MariaDB dump
CREATE TABLE `sample` (
  `id` int(11) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `sample` VALUES
(1,'O\'Brien £','{"dash":"–","quote":"“card”"}','2026-07-06 10:00:00'),
(2,'Line\nBreak','{"path":"C:\\\\Temp"}','2026-07-06 10:00:00');
SQL;

    $appStyle = <<<'SQL'
-- EEL Accounts database backup
CREATE TABLE `sample` (
  `id` int(11) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `sample` (`id`, `label`, `payload`, `updated_at`) VALUES ('1', 'O\'Brien £', '{"dash":"–","quote":"“card”"}', '2026-07-06 10:00:00');
INSERT INTO `sample` (`id`, `label`, `payload`, `updated_at`) VALUES ('2', 'Line\nBreak', '{"path":"C:\\\\Temp"}', '2026-07-06 10:00:00');
SQL;

    $same = $service->compareSql($mysqldumpStyle, $appStyle);
    $harness->assertTrue($same['matches']);
    $harness->assertSame(1, $same['tables_left']);
    $harness->assertSame(2, $same['data_rows_left']);
    $harness->assertSame([], $same['row_mismatches']);

    $changedOnlyInIgnoredColumn = str_replace('2026-07-06 10:00:00', '2026-07-06 10:15:00', $appStyle);
    $ignored = $service->compareSql($mysqldumpStyle, $changedOnlyInIgnoredColumn, [
        'sample' => ['updated_at'],
    ]);
    $harness->assertTrue($ignored['matches']);
    $harness->assertSame(['sample'], array_keys($ignored['ignored_row_mismatches']));

    $changedData = str_replace('O\\\'Brien £', 'O\\\'Brien GBP', $appStyle);
    $different = $service->compareSql($mysqldumpStyle, $changedData);
    $harness->assertTrue(!$different['matches']);
    $harness->assertSame(['sample'], array_keys($different['row_mismatches']));
});
