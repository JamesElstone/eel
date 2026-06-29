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
$harness->run(_uploads_bank_transactionsCard::class, static function (GeneratedServiceClassTestHarness $harness, _uploads_bank_transactionsCard $card): void {
    $harness->check(_uploads_bank_transactionsCard::class, 'renders upload submit loader hidden by default', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
                'accounting_period_id' => 4,
            ],
            'services' => [
                'activeCompanyAccounts' => [
                    [
                        'id' => 7,
                        'account_name' => 'Current Account',
                        'account_type' => 'bank',
                    ],
                ],
            ],
            'uploads' => [
                'filter' => 'all',
                'page' => 1,
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'data-upload-submit>Upload CSV</button>'));
        $harness->assertTrue(str_contains($html, 'class="upload-processing-icon is-hidden"'));
        $harness->assertTrue(str_contains($html, 'src="svg/loader.svg"'));
        $harness->assertTrue(str_contains($html, 'data-upload-processing-icon'));
    });
});
