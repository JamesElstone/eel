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
$migrationPath = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'db_schema'
    . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_22_003_dividend_voucher_shareholder_links.sql';

$harness->check('dividend voucher shareholder migration', 'links only vouchers with one effective shareholder without changing historic wording', static function () use ($harness, $migrationPath): void {
    $sql = (string)file_get_contents($migrationPath);

    $harness->assertTrue(str_contains($sql, 'ADD COLUMN shareholder_party_id'));
    $harness->assertTrue(str_contains($sql, 'FOREIGN KEY (shareholder_party_id) REFERENCES company_parties'));
    $harness->assertTrue(str_contains($sql, 'HAVING COUNT(DISTINCT h.party_id) = 1'));
    $harness->assertTrue(str_contains($sql, 'h.effective_from <= dv_inner.declaration_date'));
    $harness->assertTrue(str_contains($sql, 'h.effective_to IS NULL OR h.effective_to >= dv_inner.declaration_date'));
    $harness->assertTrue(str_contains($sql, 'WHERE dv.shareholder_party_id IS NULL'));
    $harness->assertFalse(str_contains($sql, 'shareholder_name ='));
    $harness->assertFalse(str_contains($sql, 'minutes_text ='));
});
