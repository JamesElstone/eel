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
    AccountingFormattingService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(AccountingFormattingService::class, 'formats nominal tax treatment labels', static function () use ($harness): void {
            $harness->assertSame('Allowable', AccountingFormattingService::nominalTaxTreatmentLabel(''));
            $harness->assertSame('Disallowable', AccountingFormattingService::nominalTaxTreatmentLabel('disallowable'));
            $harness->assertSame('Capital', AccountingFormattingService::nominalTaxTreatmentLabel('capital'));
        });

        $harness->check(AccountingFormattingService::class, 'uses default display date format without company context', static function () use ($harness): void {
            $harness->assertSame('d/m/Y', AccountingFormattingService::displayDateFormat(0));
        });
    }
);
