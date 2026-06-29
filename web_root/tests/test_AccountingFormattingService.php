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
    \eel_accounts\Service\AccountingFormattingService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(\eel_accounts\Service\AccountingFormattingService::class, 'formats nominal tax treatment labels', static function () use ($harness): void {
            $harness->assertSame('Allowable', \eel_accounts\Service\AccountingFormattingService::nominalTaxTreatmentLabel(''));
            $harness->assertSame('Disallowable', \eel_accounts\Service\AccountingFormattingService::nominalTaxTreatmentLabel('disallowable'));
            $harness->assertSame('Capital', \eel_accounts\Service\AccountingFormattingService::nominalTaxTreatmentLabel('capital'));
        });

        $harness->check(\eel_accounts\Service\AccountingFormattingService::class, 'uses default display date format without company context', static function () use ($harness): void {
            $harness->assertSame('d/m/Y', \eel_accounts\Service\AccountingFormattingService::displayDateFormat(0));
        });
    }
);
