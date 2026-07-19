<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    _director_loan_s455Card::class,
    static function (GeneratedServiceClassTestHarness $harness, _director_loan_s455Card $card): void {
        $harness->check(_director_loan_s455Card::class, 'renders monetary s455 results through the company settings formatter', static function () use ($harness, $card): void {
            $html = $card->render([
                'company' => [
                    'id' => 49,
                    'accounting_period_id' => 79,
                    'settings' => ['currency_symbol' => '£', 'currency_decimals' => 2],
                ],
                'services' => [
                    'ownership' => ['available' => true, 'parties' => []],
                    's455' => [
                        'available' => true,
                        'periods' => [[
                            'available' => true,
                            'ct_period_id' => 6,
                            'sequence_no' => 1,
                            'period_start' => '2022-09-05',
                            'period_end' => '2023-09-04',
                            'confirmed' => true,
                            'gross_principal' => 100.00,
                            'gross_tax' => 33.75,
                            'qualifying_repayments' => 25.00,
                            'net_tax' => 25.31,
                            'repayment_deadline' => '2024-06-05',
                            'evidence_cutoff' => '2023-09-30 12:00:00',
                            'close_company_status' => 'yes',
                            'confirmation_note' => '',
                            'window_status' => 'provisional_window_open',
                            'ct600a_required' => true,
                            'errors' => [],
                        ]],
                    ],
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'Net s455 tax'));
            $harness->assertTrue(str_contains($html, '25.31'));
        });
    }
);
