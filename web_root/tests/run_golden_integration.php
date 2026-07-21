<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'SelectedTestRunner.php';

$files = array_map(
    static fn(string $name): string => __DIR__ . DIRECTORY_SEPARATOR . $name,
    [
        'test_GoldenAccountingOracle.php',
        'test_GoldenCt600aLifecycle.php',
        'test_GoldenYearEndLifecycle.php',
        'test_GoldenAccountingCardAuditDefects.php',
        'test_GoldenAccountsFixture.php',
        'test_GoldenTaxControlMatrix.php',
    ]
);

eel_accounts_run_selected_tests($files);
