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
$harness->run(\eel_accounts\Service\VatValidationResultService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\VatValidationResultService $result): void {
    $harness->check(\eel_accounts\Service\VatValidationResultService::class, 'builds valid VAT validation results', function () use ($harness): void {
        $valid = \eel_accounts\Service\VatValidationResultService::valid('hmrc', 'Example Ltd', '1 High Street');

        $harness->assertSame('valid', $valid->status);
        $harness->assertSame('Example Ltd', $valid->name);
    });

    $harness->check(\eel_accounts\Service\VatValidationResultService::class, 'builds error VAT validation results', function () use ($harness): void {
        $error = \eel_accounts\Service\VatValidationResultService::error('hmrc', 'Boom');

        $harness->assertSame('error', $error->status);
        $harness->assertSame('Boom', $error->error);
    });
});
