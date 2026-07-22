<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_director_loan_attributionCard::class, static function (GeneratedServiceClassTestHarness $harness, _director_loan_attributionCard $card): void {
    $context = [
        'company' => ['id' => 47, 'accounting_period_id' => 74],
        'page' => ['page_id' => 'loans', 'page_cards' => ['director_loan_attribution']],
        'services' => [
            'directorLoanStatement' => [
                'success' => true,
                'default_currency_symbol' => '£',
                'directors' => [['id' => 9, 'full_name' => 'Primary Director', 'is_active' => 1]],
                'attribution_entries' => [
                    [
                        'journal_line_id' => 123,
                        'journal_date' => '2025-06-30',
                        'description' => 'Assigned entry',
                        'source_label' => 'Journal #1',
                        'signed_amount' => -10,
                        'director_id' => 9,
                    ],
                    [
                        'journal_line_id' => 124,
                        'journal_date' => '2025-07-01',
                        'description' => 'Unassigned entry',
                        'source_label' => 'Journal #2',
                        'signed_amount' => -20,
                        'director_id' => null,
                    ],
                ],
            ],
        ],
    ];

    $harness->check(_director_loan_attributionCard::class, 'defaults to all attribution entries', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, 'Assigned entry'));
        $harness->assertTrue(str_contains($html, 'Unassigned entry'));
        $harness->assertTrue(str_contains($html, '<option value="all" selected>All</option>'));
        $harness->assertTrue(str_contains($html, '<option value="requires_assignment">Requires Assignment</option>'));
    });

    $harness->check(_director_loan_attributionCard::class, 'filters to entries requiring assignment', static function () use ($harness, $card, $context): void {
        $context['director_loan_attribution']['director_loan_attribution_filter'] = 'requires_assignment';
        $html = $card->render($context);

        $harness->assertSame(false, str_contains($html, 'Assigned entry'));
        $harness->assertTrue(str_contains($html, 'Unassigned entry'));
        $harness->assertTrue(str_contains($html, '<option value="requires_assignment" selected>Requires Assignment</option>'));
    });
});
