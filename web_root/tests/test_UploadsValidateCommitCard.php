<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_uploads_validate_commitCard::class, static function (GeneratedServiceClassTestHarness $harness, _uploads_validate_commitCard $card): void {
    $baseContext = [
        'company' => ['id' => 42, 'accounting_period_id' => 61],
        'uploads' => ['id' => 124, 'filter' => 'all', 'page' => 1],
        'services' => [
            'selected_upload_preview' => [
                'upload' => ['id' => 124, 'account_id' => 47, 'original_filename' => 'statement.csv'],
                'summary' => ['rows_parsed' => 1, 'rows_ready_to_import' => 1],
                'rows' => [[
                    'row_number' => 1, 'chosen_txn_date' => '2026-01-02', 'accounting_period_id' => 61,
                    'normalised_description' => 'Sale', 'normalised_amount' => '10.00', 'normalised_currency' => 'GBP',
                    'validation_status' => 'valid', 'is_duplicate_existing' => 0, 'is_duplicate_within_upload' => 0,
                ]],
            ],
            'selected_upload_lock_state' => ['is_locked' => true, 'locked_accounting_period_ids' => [61]],
            'empty_month_upload_impact' => [],
        ],
    ];

    $harness->check(_uploads_validate_commitCard::class, 'disables commit for a locked upload and enables the same eligible upload in a later period', static function () use ($harness, $card, $baseContext): void {
        $lockedHtml = $card->render($baseContext);
        $harness->assertTrue(str_contains($lockedHtml, 'preview is view only'));
        $harness->assertTrue(str_contains($lockedHtml, 'type="submit" disabled title="This upload includes a locked accounting period."'));

        $openContext = $baseContext;
        $openContext['company']['accounting_period_id'] = 62;
        $openContext['services']['selected_upload_preview']['rows'][0]['accounting_period_id'] = 62;
        $openContext['services']['selected_upload_lock_state'] = ['is_locked' => false, 'locked_accounting_period_ids' => []];
        $openHtml = $card->render($openContext);
        $harness->assertTrue(str_contains($openHtml, '<button class="button primary" type="submit">Import Transactions</button>'));
    });
});
