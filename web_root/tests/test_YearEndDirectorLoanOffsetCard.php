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
$harness->run(_year_end_director_loan_offsetCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_director_loan_offsetCard $card): void {
    $harness->check(_year_end_director_loan_offsetCard::class, 'uses one factual year-end review service', static function () use ($harness, $card): void {
        $services = $card->services();
        $harness->assertCount(1, $services);
        $harness->assertSame('directorLoanReview', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\DirectorLoanReconciliationService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchYearEndConfirmationContext', (string)($services[0]['method'] ?? ''));
        $harness->assertSame('Director Loan Year End Review', $card->title());
    });

    $harness->check(_year_end_director_loan_offsetCard::class, 'renders one confirmation after the per-director accounting facts', static function () use ($harness, $card): void {
        $html = $card->render(yearEndDirectorLoanReviewCardContext([
            'available' => true,
            'has_activity' => true,
            'can_confirm' => true,
            'asset_receivable' => 253.00,
            'liability_payable' => 1288.63,
            'desired_reclassification_amount' => 253.00,
            'posted_reclassification_amount' => 0.00,
            'pending_adjustment_amount' => 253.00,
            'potential_s455_exposure' => 0.00,
            'warnings' => [
                '118 Director Loan entries are not attributed to a valid same-company director.',
                'A separate review warning remains relevant.',
            ],
            'per_director' => [[
                'director_name' => 'Primary Director',
                'gross_asset' => 253.00,
                'gross_liability' => 1288.63,
                'desired_reclassification' => 253.00,
                'net_closing_position' => 1035.63,
                'potential_s455_exposure' => 0.00,
            ]],
            'tax_review' => [
                'director_flags' => [[
                    'director_name' => 'Primary Director',
                    'review_required' => false,
                    'potential_s455_exposure' => 0.00,
                ]],
            ],
            'proposed_lines' => [
                ['line_description' => 'Director loan control reclassification - Primary Director', 'debit' => 253.00, 'credit' => 0.00],
                ['line_description' => 'Director loan control reclassification - Primary Director', 'debit' => 0.00, 'credit' => 253.00],
            ],
            'acknowledgement_current' => false,
            'acknowledgement_state' => 'absent',
            'acknowledgement' => null,
        ]));

        $confirmation = 'I confirm the directors, attributed entries, per-director balances, tax flags and calculated control-account reclassification shown above are correct for this accounting period.';
        $harness->assertTrue(str_contains($html, $confirmation));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_director_loan_year_end_review"'));
        $harness->assertTrue(str_contains($html, 'name="director_loan_year_end_review" value="1"'));
        $harness->assertTrue(str_contains($html, 'Primary Director'));
        $harness->assertTrue(str_contains($html, 'Calculated reclassification'));
        $harness->assertTrue(str_contains($html, '<div class="panel-soft table-scroll"><table>'));
        $harness->assertSame(2, substr_count($html, '<div class="panel-soft table-scroll"><table>'));
        $harness->assertSame(false, str_contains($html, '118 Director Loan entries are not attributed'));
        $harness->assertTrue(str_contains($html, 'A separate review warning remains relevant.'));
        $harness->assertSame(false, str_contains($html, 'director_loan_legally_enforceable_right'));
        $harness->assertSame(false, str_contains($html, 'director_loan_net_settlement_intent'));
        $harness->assertSame(false, str_contains($html, 'director_loan_set_off_evidence_note'));
        $harness->assertSame(false, str_contains($html, 'FRS 105'));
        $harness->assertSame(false, str_contains($html, 'approval_note'));
    });

    $harness->check(_year_end_director_loan_offsetCard::class, 'passes automatically when no director loan activity exists', static function () use ($harness, $card): void {
        $html = $card->render(yearEndDirectorLoanReviewCardContext([
            'available' => true,
            'has_activity' => false,
            'can_confirm' => false,
            'asset_receivable' => 0,
            'liability_payable' => 0,
            'desired_reclassification_amount' => 0,
            'posted_reclassification_amount' => 0,
            'pending_adjustment_amount' => 0,
            'potential_s455_exposure' => 0,
            'warnings' => [],
            'per_director' => [],
            'tax_review' => ['director_flags' => []],
            'proposed_lines' => [],
        ]));

        $harness->assertTrue(str_contains($html, 'passes automatically'));
        $harness->assertSame(false, str_contains($html, 'save_director_loan_year_end_review'));
    });

    $harness->check(_year_end_director_loan_offsetCard::class, 'blocks confirmation and offers repair for an unresolved legacy offset', static function () use ($harness, $card): void {
        $html = $card->render(yearEndDirectorLoanReviewCardContext([
            'available' => true,
            'has_activity' => true,
            'can_confirm' => false,
            'asset_receivable' => 0,
            'liability_payable' => 0,
            'desired_reclassification_amount' => 0,
            'posted_reclassification_amount' => 125,
            'pending_adjustment_amount' => 0,
            'potential_s455_exposure' => 0,
            'legacy_unresolved_reclassification_amount' => 125,
            'warnings' => ['A legacy Director Loan offset journal cannot be attributed deterministically and remains an unresolved historical accounting record.'],
            'per_director' => [],
            'tax_review' => ['director_flags' => []],
            'proposed_lines' => [],
        ]));

        $harness->assertTrue(str_contains($html, 'Repair legacy offset'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="repair_legacy_director_loan_offset"'));
        $harness->assertTrue(str_contains($html, 'Repair the legacy Director Loan offset before confirming these facts.'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="save_director_loan_year_end_review"'));
    });
});

function yearEndDirectorLoanReviewCardContext(array $review): array
{
    return [
        'company' => [
            'id' => 33,
            'accounting_period_id' => 70,
            'settings' => ['default_currency_symbol' => '£'],
        ],
        'services' => ['directorLoanReview' => $review],
    ];
}
