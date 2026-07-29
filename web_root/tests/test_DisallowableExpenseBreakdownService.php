<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\DisallowableExpenseBreakdownService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\DisallowableExpenseBreakdownService $service
    ): void {
        $harness->check($service::class, 'groups source-backed rows and reconciles the expected amount', static function () use ($harness, $service): void {
            $result = $service->fromTaxWorkings([
                [
                    'nominal_code' => '6130',
                    'nominal_name' => 'Client entertainment',
                    'journal_date' => '2025-03-01',
                    'source' => 'journal',
                    'source_label' => 'Journal #7',
                    'journal_id' => 7,
                    'journal_line_id' => 9,
                    'line_description' => 'Client meal',
                    'amount' => 40.00,
                ],
                [
                    'nominal_code' => '6130',
                    'nominal_name' => 'Client entertainment',
                    'journal_date' => '2025-03-02',
                    'source' => 'journal',
                    'source_label' => 'Journal #8',
                    'journal_id' => 8,
                    'amount' => 10.00,
                ],
            ], 50.00);

            $harness->assertTrue((bool)($result['reconciled'] ?? false));
            $harness->assertSame('50.00', number_format((float)($result['amount'] ?? 0), 2, '.', ''));
            $harness->assertCount(1, (array)($result['categories'] ?? []));
            $harness->assertSame('Journal #7, line #9', (string)($result['categories'][0]['sources'][0]['source_reference'] ?? ''));
        });

        $harness->check($service::class, 'fails reconciliation for incomplete frozen audit evidence', static function () use ($harness, $service): void {
            $result = $service->fromFrozenAudit(['rows' => [[
                'tax_treatment' => 'disallowable',
                'tax_adjustment_amount' => 25.00,
                'source_type' => 'calculation_reconciliation',
            ]]], 25.00);

            $harness->assertFalse((bool)($result['reconciled'] ?? true));
            $harness->assertSame([1], (array)($result['incomplete_source_rows'] ?? []));
        });
    }
);
