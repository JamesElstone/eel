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
$harness->run(_year_end_companies_house_comparisonCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _year_end_companies_house_comparisonCard $card
): void {
    $harness->check(_year_end_companies_house_comparisonCard::class, 'renders comparison gates inside the single section approval', static function () use ($harness, $card): void {
        $html = $card->render(companiesHouseComparisonCardContext());

        $harness->assertCount(2, $card->services());
        $harness->assertSame(true, str_contains($html, 'Companies House Comparison'));
        $harness->assertSame(true, str_contains($html, 'Is this Company eligible to submit revised accounts using the Companies House XML Gateway Service?'));
        $harness->assertSame(true, str_contains($html, 'name="approval_answers[companies_house.xml_eligibility]"'));
        $harness->assertSame(true, str_contains($html, 'Why do the Companies House figures need revising?'));
        $harness->assertSame(true, str_contains($html, 'name="approval_answers[companies_house.variance_explanation]"'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="approve_section_review"'));
        $harness->assertSame(false, str_contains($html, 'name="eligibility_decision"'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="save_variance_explanation"'));
        $harness->assertSame(true, strpos($html, 'Companies House Comparison') < strpos($html, 'Is this Company eligible to submit revised accounts'));
        $harness->assertSame(true, str_contains($html, 'data-year-end-approval-required-value="eligible"'));
        $harness->assertSame(true, str_contains($html, 'Synchronise with Companies House'));
        $harness->assertSame(true, str_contains($html, 'name="card_action" value="Company"'));
        $harness->assertSame(true, str_contains($html, 'Required filing route'));
        $harness->assertSame(true, str_contains($html, 'Revised'));
        $harness->assertSame(true, str_contains($html, 'Difference identified'));
        $harness->assertSame(true, str_contains($html, 'Original Filing'));
        $harness->assertSame(true, str_contains($html, 'Latest Revised Filing'));
        $harness->assertSame(true, str_contains($html, 'Filed and verified'));
        $harness->assertSame(true, str_contains($html, 'latest of 1 revised filing'));
        $harness->assertSame(true, str_contains($html, 'Original Filing Comparison Approval'));
        $harness->assertSame(true, str_contains($html, 'read-only reconciliation evidence'));
        $harness->assertSame(true, str_contains($html, 'scope="colgroup"'));
        $harness->assertSame(true, str_contains($html, 'scope="row"'));
        $harness->assertSame(true, str_contains($html, 'aria-label="App figures compared with the original and latest revised Companies House filings"'));

        $document = new DOMDocument();
        @$document->loadHTML($html);
        $cells = (new DOMXPath($document))->query('//*[@id="companies-house-comparison"]//tbody/tr[1]/*');
        $harness->assertSame(8, $cells?->length ?? 0);
        $harness->assertSame('Fixed assets', trim((string)$cells?->item(0)?->textContent));
        $harness->assertSame(
            trim((string)$cells?->item(1)?->textContent),
            trim((string)$cells?->item(5)?->textContent)
        );
        $harness->assertSame('Pass', trim((string)$cells?->item(7)?->textContent));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'shows approved gate answers from the acknowledgement basis', static function () use ($harness, $card): void {
        $context = companiesHouseComparisonCardContext();
        $context['services']['sectionReview']['acknowledgement'] = [
            'acknowledged_at' => '2026-07-24 10:00:00',
            'acknowledged_by' => 'Fixture user',
            'note' => 'Corrective filing needed.',
        ];
        $context['services']['sectionReview']['acknowledgement_current'] = true;
        $context['services']['sectionReview']['answers'] = [
            'companies_house.xml_eligibility' => 'eligible',
            'companies_house.variance_explanation' => 'The filed fixed assets value was incomplete.',
        ];
        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'Corrective filing needed.'));
        $harness->assertSame(true, str_contains($html, 'The filed fixed assets value was incomplete.'));
        $harness->assertSame(true, str_contains($html, 'Revoke Approval'));
        $harness->assertSame(false, str_contains($html, 'Save Variance Explanation'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'uses the existing no-filing check code without a parallel acknowledgement', static function () use ($harness, $card): void {
        $context = companiesHouseComparisonCardContext();
        $context['services']['sectionReview']['check_code'] = 'companies_house_no_filing_acknowledgement';
        $context['services']['sectionReview']['display']['comparison'] = [
            'available' => true,
            'has_exact_filing' => false,
            'filing_kind' => 'original',
            'filing_reason' => 'no_exact_period_filing_found',
            'comparison_scope' => 'no_exact_filing',
            'comparison_note' => 'No exact Companies House accounts filing is available.',
            'filing' => null,
            'rows' => [['label' => 'Fixed assets', 'app_value' => 420.00, 'filed_value' => null, 'variance' => null, 'status' => 'not_filed']],
            'can_acknowledge' => true,
        ];
        $context['services']['sectionReview']['questions'] = [];
        $context['services']['revisedObservation'] = [
            'available' => true,
            'has_revised_filing' => false,
            'revision_count' => 0,
            'reconciliation_state' => 'awaiting',
            'filing_outstanding' => false,
            'filing' => null,
            'rows' => [],
        ];
        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'No exact Companies House accounts filing is available.'));
        $harness->assertSame(true, str_contains($html, 'Not Filed'));
        $harness->assertSame(true, str_contains($html, 'name="check_code" value="companies_house_no_filing_acknowledgement"'));
        $harness->assertSame(false, str_contains($html, 'Why do the Companies House figures need revising?'));
        $harness->assertSame(true, str_contains($html, 'Original'));
        $harness->assertSame(true, str_contains($html, 'No revised filing required'));
        $harness->assertSame(true, str_contains($html, 'no amended filing is required'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'shows revised observation service failures instead of reporting that no filing exists', static function () use ($harness, $card): void {
        $context = companiesHouseComparisonCardContext();
        unset($context['services']['revisedObservation']);
        $context['service_errors']['revisedObservation'] = [
            'message' => 'Companies House revised filing lookup failed.',
        ];

        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, '>Unverifiable<'));
        $harness->assertSame(true, str_contains($html, '>Unavailable<'));
        $harness->assertSame(false, str_contains($html, 'Filed but unverifiable'));
        $harness->assertSame(true, str_contains($html, 'Companies House revised filing lookup failed.'));
        $harness->assertSame(true, str_contains($html, 'current Companies House state could not be checked'));
        $harness->assertSame(false, str_contains($html, 'no amended filing has been imported'));
    });
});

function companiesHouseComparisonCardContext(): array
{
    return [
        'company' => ['id' => 12, 'company_name' => 'Fixture Limited', 'accounting_period_id' => 34, 'settings' => []],
        'services' => [
            'sectionReview' => [
                'available' => true,
                'check_code' => 'companies_house_mismatch_acknowledgement',
                'acknowledgement' => null,
                'acknowledgement_state' => 'absent',
                'acknowledgement_current' => false,
                'answers' => [],
                'questions' => [
                    [
                        'id' => 'companies_house.xml_eligibility',
                        'prompt' => 'Is this Company eligible to submit revised accounts using the Companies House XML Gateway Service?',
                        'type' => 'choice',
                        'options' => ['eligible' => 'Yes', 'ineligible' => 'No'],
                        'required' => true,
                        'required_value' => 'eligible',
                    ],
                    [
                        'id' => 'companies_house.variance_explanation',
                        'prompt' => 'Why do the Companies House figures need revising?',
                        'type' => 'text',
                        'required' => true,
                    ],
                ],
                'display' => [
                    'comparison' => [
                        'available' => true,
                        'has_exact_filing' => true,
                        'filing_kind' => 'revised',
                        'filing_reason' => 'exact_period_filing_found',
                        'can_acknowledge' => true,
                        'comparison_note' => 'Comparison available.',
                        'filing' => ['filing_date' => '2026-02-14'],
                        'rows' => [['metric_key' => 'fixed_assets', 'label' => 'Fixed assets', 'app_value' => 420.00, 'filed_value' => 250.00, 'variance' => 170.00, 'status' => 'fail']],
                    ],
                    'access' => ['is_locked' => false],
                    'mismatch_count' => 1,
                ],
            ],
            'revisedObservation' => [
                'available' => true,
                'has_revised_filing' => true,
                'revision_count' => 1,
                'reconciliation_state' => 'verified',
                'revision_reconciled' => true,
                'filing_outstanding' => false,
                'filing' => ['filing_date' => '2026-08-10'],
                'comparison_note' => 'The latest revised filing matches the reconstructed accounts.',
                'rows' => [[
                    'metric_key' => 'fixed_assets',
                    'label' => 'Fixed assets',
                    'app_value' => 420.00,
                    'revised_filed_value' => 420.00,
                    'variance' => 0.00,
                    'status' => 'pass',
                ]],
            ],
        ],
    ];
}
