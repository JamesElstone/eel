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
    $harness->check(_year_end_companies_house_comparisonCard::class, 'renders the comparison, XML eligibility choice, and approval in order', static function () use ($harness, $card): void {
        $context = companiesHouseComparisonCardContext();
        $html = $card->render($context);

        $harness->assertCount(1, $card->services());
        $harness->assertSame(true, str_contains($html, 'Companies House Comparison'));
        $harness->assertSame(true, str_contains($html, 'Is Fixture Limited eligible for XML based web filing?'));
        $harness->assertSame(true, str_contains($html, 'type="radio" name="eligibility_decision" value="eligible" required data-submit-on-change="true" checked'));
        $harness->assertSame(true, str_contains($html, 'type="radio" name="eligibility_decision" value="ineligible" required data-submit-on-change="true"'));
        $harness->assertSame(true, str_contains($html, 'data-ajax="true"'));
        $harness->assertSame(false, str_contains($html, 'Save eligibility decision'));
        $harness->assertSame(false, str_contains($html, 'Companies House Revised Accounts Filing'));
        $harness->assertSame(false, str_contains($html, 'Written evidence'));
        $harness->assertSame(false, str_contains($html, 'Companies House response reference'));
        $harness->assertSame(true, strpos($html, 'Companies House Comparison') < strpos($html, 'Is Fixture Limited eligible'));
        $harness->assertSame(true, strpos($html, 'Is Fixture Limited eligible') < strpos($html, '<h3 class="card-title">Approval</h3>'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'blocks the approval UI until an unlocked period has a saved eligibility decision', static function () use ($harness, $card): void {
        $context = companiesHouseComparisonCardContext(['decision' => 'pending', 'original_document_id' => 56], false);
        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'Record whether the company is eligible for XML based web filing before completing this Year End Confirmation.'));
        $harness->assertSame(true, str_contains($html, 'data-year-end-ack-checkbox disabled'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'preserves locked legacy positions without making eligibility a blocker', static function () use ($harness, $card): void {
        $context = companiesHouseComparisonCardContext(['decision' => 'pending', 'original_document_id' => 56], true);
        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'This legacy accounting period is locked, so its existing Year End position is not changed.'));
        $harness->assertSame(true, str_contains($html, '<fieldset disabled>'));
        $harness->assertSame(false, str_contains($html, 'Record whether the company is eligible for XML based web filing before completing this Year End Confirmation.'));
    });
});

function companiesHouseComparisonCardContext(array $eligibility = ['decision' => 'eligible', 'original_document_id' => 56], bool $locked = false): array
{
    return [
        'company' => ['id' => 12, 'company_name' => 'Fixture Limited', 'accounting_period_id' => 34, 'settings' => []],
        'services' => [
            'companiesHouseComparisonReview' => [
                'comparison' => [
                    'available' => true,
                    'comparison_note' => 'Comparison available.',
                    'filing' => ['filing_date' => '2026-02-14'],
                    'rows' => [['label' => 'Fixed assets', 'app_value' => 420.00, 'filed_value' => 250.00, 'variance' => 170.00, 'status' => 'fail']],
                ],
                'eligibility' => $eligibility,
                'acknowledgement' => null,
                'access' => ['is_locked' => $locked],
                'mismatch_count' => 1,
                'can_acknowledge' => !$locked && in_array((string)($eligibility['decision'] ?? 'pending'), ['eligible', 'ineligible'], true),
                'acknowledgement_blocked_reason' => !$locked && !in_array((string)($eligibility['decision'] ?? 'pending'), ['eligible', 'ineligible'], true)
                    ? 'Record whether the company is eligible for XML based web filing before completing this Year End Confirmation.' : '',
            ],
        ],
    ];
}
