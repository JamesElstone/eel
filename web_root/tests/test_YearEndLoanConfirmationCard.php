<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_year_end_loan_confirmationCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_loan_confirmationCard $card): void {
    $harness->check(_year_end_loan_confirmationCard::class, 'uses the canonical section review service', static function () use ($harness, $card): void {
        $services = $card->services();
        $harness->assertCount(1, $services);
        $harness->assertSame('sectionReview', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\YearEndSectionApprovalService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchReview', (string)($services[0]['method'] ?? ''));
        $harness->assertSame('Director Loan Year End Review', $card->title());
    });

    $harness->check(_year_end_loan_confirmationCard::class, 'renders loan facts and CT600A questions in one approval form', static function () use ($harness, $card): void {
        $html = $card->render(yearEndDirectorLoanReviewCardContext());
        $confirmation = 'I confirm the directors, attributed entries, per-director balances, tax flags and calculated control-account reclassification shown above are correct for this accounting period.';
        $harness->assertTrue(str_contains($html, $confirmation));
        $harness->assertTrue(str_contains($html, 'name="intent" value="approve_section_review"'));
        $harness->assertTrue(str_contains($html, 'name="check_code" value="director_loan_year_end_review"'));
        $harness->assertTrue(str_contains($html, 'name="approval_answers[ct600a.missing_parties]"'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="save_ct600a_review"'));
        $harness->assertTrue(str_contains($html, 'Primary Director'));
        $harness->assertTrue(str_contains($html, 'Total Participator Loan Asset (Gross)'));
        $harness->assertTrue(str_contains($html, 'Balance after Year End has closed'));
        $harness->assertSame(false, str_contains($html, 'director_loan_legally_enforceable_right'));
    });

    $harness->check(_year_end_loan_confirmationCard::class, 'shows the saved question answer with the completed approval', static function () use ($harness, $card): void {
        $context = yearEndDirectorLoanReviewCardContext();
        $context['services']['sectionReview']['acknowledgement'] = ['acknowledged_at' => '2026-07-24 10:00:00', 'acknowledged_by' => 'Fixture user'];
        $context['services']['sectionReview']['acknowledgement_current'] = true;
        $context['services']['sectionReview']['answers'] = ['ct600a.missing_parties' => 'no'];
        $html = $card->render($context);
        $harness->assertTrue(str_contains($html, 'Are any participators missing?'));
        $harness->assertTrue(str_contains($html, 'Revoke approval'));
        $harness->assertSame(false, str_contains($html, 'save_ct600a_review'));
    });

    $harness->check(_year_end_loan_confirmationCard::class, 'renders stable per-party tax statuses', static function () use ($harness, $card): void {
        $context = yearEndDirectorLoanReviewCardContext();
        $context['services']['sectionReview']['display']['party_facts'] = [
            [
                'party_id' => 101,
                'party_name' => 'Terms Missing',
                'gross_asset' => 100,
                'gross_liability' => 0,
                'potential_s455_exposure' => 100,
                'terms_saved' => false,
            ],
            [
                'party_id' => 102,
                'party_name' => 'Evidence Pending',
                'gross_asset' => 200,
                'gross_liability' => 0,
                'potential_s455_exposure' => 200,
                'terms_saved' => true,
                'tax_status_code' => 'review_required',
            ],
            [
                'party_id' => 103,
                'party_name' => 'Exposure Reviewed',
                'gross_asset' => 300,
                'gross_liability' => 0,
                'potential_s455_exposure' => 300,
                'terms_saved' => true,
                'tax_status_code' => 'reviewed_exposure',
            ],
            [
                'party_id' => 104,
                'party_name' => 'No Exposure',
                'gross_asset' => 0,
                'gross_liability' => 100,
                'potential_s455_exposure' => 0,
                'terms_saved' => true,
                'tax_status_code' => 'no_exposure',
            ],
        ];
        $context['services']['sectionReview']['display']['tax_review'] = ['party_flags' => []];

        $html = $card->render($context);
        $harness->assertTrue(str_contains($html, 'Terms required'));
        $harness->assertTrue(str_contains($html, 'Review required'));
        $harness->assertTrue(str_contains($html, 'Reviewed — exposure recorded'));
        $harness->assertTrue(str_contains($html, 'No exposure flagged'));
    });

    $harness->check(_year_end_loan_confirmationCard::class, 'passes automatically when no director loan activity exists and retains legacy repair controls', static function () use ($harness, $card): void {
        $context = yearEndDirectorLoanReviewCardContext();
        $context['services']['sectionReview']['display']['has_activity'] = false;
        $html = $card->render($context);
        $harness->assertTrue(str_contains($html, 'passes automatically'));

        $context = yearEndDirectorLoanReviewCardContext();
        $context['services']['sectionReview']['display']['legacy_unresolved_reclassification_amount'] = 125;
        $context['services']['sectionReview']['display']['has_activity'] = true;
        $html = $card->render($context);
        $harness->assertTrue(str_contains($html, 'Repair legacy offset'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="repair_legacy_director_loan_offset"'));
    });
});

function yearEndDirectorLoanReviewCardContext(): array
{
    return [
        'company' => ['id' => 33, 'accounting_period_id' => 70, 'settings' => ['default_currency_symbol' => '£']],
        'services' => ['sectionReview' => [
            'available' => true,
            'check_code' => 'director_loan_year_end_review',
            'acknowledgement' => null,
            'acknowledgement_state' => 'absent',
            'acknowledgement_current' => false,
            'answers' => [],
            'questions' => [[
                'id' => 'ct600a.missing_parties',
                'prompt' => 'Are any participators missing?',
                'type' => 'choice',
                'options' => ['no' => 'No', 'yes' => 'Yes'],
                'required' => true,
                'required_value' => 'no',
            ]],
            'display' => [
                'available' => true,
                'has_activity' => true,
                'asset_receivable' => 253.00,
                'liability_payable' => 1288.63,
                'net_position' => 1035.63,
                'desired_reclassification_amount' => 253.00,
                'posted_reclassification_amount' => 0.00,
                'pending_adjustment_amount' => 253.00,
                'potential_s455_exposure' => 0.00,
                'unattributed_count' => 0,
                'legacy_unresolved_reclassification_amount' => 0,
                'warnings' => ['A separate review warning remains relevant.'],
                'per_director' => [[
                    'director_name' => 'Primary Director', 'gross_asset' => 253.00, 'gross_liability' => 1288.63,
                    'desired_reclassification' => 253.00, 'net_closing_position' => 1035.63, 'potential_s455_exposure' => 0.00,
                ]],
                'tax_review' => ['director_flags' => [['director_name' => 'Primary Director', 'review_required' => false, 'potential_s455_exposure' => 0.00]]],
                'proposed_lines' => [
                    ['line_description' => 'Director loan control reclassification - Primary Director', 'debit' => 253.00, 'credit' => 0.00],
                    ['line_description' => 'Director loan control reclassification - Primary Director', 'debit' => 0.00, 'credit' => 253.00],
                ],
            ],
        ]],
    ];
}
