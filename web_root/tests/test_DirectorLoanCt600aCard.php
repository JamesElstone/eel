<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    _director_loan_ct600aCard::class,
    static function (GeneratedServiceClassTestHarness $harness, _director_loan_ct600aCard $card): void {
        $harness->check(_director_loan_ct600aCard::class, 'renders the company-wide tax period display sequence', static function () use ($harness, $card): void {
            $html = $card->render([
                'company' => [
                    'id' => 49,
                    'accounting_period_id' => 80,
                    'settings' => ['currency_symbol' => '£', 'currency_decimals' => 2],
                ],
                'page' => ['page_cards' => ['director_loan_ct600a']],
                'services' => [
                    'ct600a' => [
                        'available' => true,
                        'periods' => [[
                            'available' => true,
                            'ct_period_id' => 8,
                            'sequence_no' => 1,
                            'display_sequence_no' => 3,
                            'period_start' => '2023-10-01',
                            'period_end' => '2024-09-30',
                            'complete' => true,
                            'required' => false,
                            'blocking_errors' => [],
                            'evidence_warnings' => [],
                            'part1' => ['total_loans' => 0, 'tax_chargeable' => 0],
                            'part2' => ['relief_due' => 0],
                            'part3' => ['relief_due' => 0],
                            'total_loans_outstanding' => 0,
                            'tax_payable' => 0,
                        ]],
                    ],
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'Tax period 3 — 2023-10-01 to 2024-09-30'));
            $harness->assertTrue(!str_contains($html, 'Tax period 1 — 2023-10-01 to 2024-09-30'));
        });

        $harness->check(_director_loan_ct600aCard::class, 'keeps stale CT600A evidence read only and routes confirmation to Year End', static function () use ($harness, $card): void {
            $html = $card->render(directorLoanCt600aCardContext([
                'stored' => true,
                'current' => false,
                'complete' => false,
            ]));

            $harness->assertTrue(str_contains($html, 'The underlying evidence has changed.'));
            $harness->assertTrue(str_contains($html, '?page=loans&amp;show_card=year_end_loan_confirmation'));
            $harness->assertTrue(str_contains($html, 'Review in Year End Confirmation'));
            $harness->assertFalse(str_contains($html, 'name="intent" value="save_ct600a_review"'));
        });

    }
);

/** @param array<string,mixed> $review */
function directorLoanCt600aCardContext(array $review): array
{
    return [
        'company' => [
            'id' => 49,
            'accounting_period_id' => 80,
            'settings' => ['currency_symbol' => '£', 'currency_decimals' => 2],
        ],
        'page' => ['page_cards' => ['director_loan_ct600a']],
        'services' => [
            'ct600a' => [
                'available' => true,
                'questions' => [
                    'missing_parties' => 'Are any participators missing?',
                    'unrecorded_value' => 'Was any value transferred outside the recorded loan accounts?',
                ],
                'review' => $review,
                'periods' => [],
            ],
        ],
    ];
}
