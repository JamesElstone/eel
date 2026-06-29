<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Repository\AccountingAuditRepository::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Repository\AccountingAuditRepository $repository): void {
        $harness->check(\eel_accounts\Repository\AccountingAuditRepository::class, 'returns arrays for empty audit tables', static function () use ($harness, $repository): void {
            $harness->assertTrue(is_array($repository->fetchRecentTransactionCategoryAudit(5)));
            $harness->assertTrue(is_array($repository->fetchRecentYearEndAudit(5)));
        });
    }
);
