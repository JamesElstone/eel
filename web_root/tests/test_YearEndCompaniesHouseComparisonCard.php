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

        $harness->assertCount(1, $card->services());
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
        $harness->assertSame(true, str_contains($html, 'Filing classification'));
        $harness->assertSame(true, str_contains($html, 'Revised'));
        $harness->assertSame(true, str_contains($html, 'Difference identified'));
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
        $harness->assertSame(true, str_contains($html, 'Revoke approval'));
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
        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'No exact Companies House accounts filing is available.'));
        $harness->assertSame(true, str_contains($html, 'Not Filed'));
        $harness->assertSame(true, str_contains($html, 'name="check_code" value="companies_house_no_filing_acknowledgement"'));
        $harness->assertSame(false, str_contains($html, 'Why do the Companies House figures need revising?'));
        $harness->assertSame(true, str_contains($html, 'Original'));
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
                        'rows' => [['label' => 'Fixed assets', 'app_value' => 420.00, 'filed_value' => 250.00, 'variance' => 170.00, 'status' => 'fail']],
                    ],
                    'access' => ['is_locked' => false],
                    'mismatch_count' => 1,
                ],
            ],
        ],
    ];
}
