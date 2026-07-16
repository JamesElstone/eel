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
    $harness->check(_year_end_companies_house_comparisonCard::class, 'declares one focused comparison review context service', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertCount(1, $services);
        $harness->assertSame('companiesHouseComparisonReview', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\CompaniesHouseComparisonReviewService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchContext', (string)($services[0]['method'] ?? ''));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'renders comparison rows and pending approval controls', static function () use ($harness, $card): void {
        $html = $card->render(companiesHouseComparisonCardContext(null));

        $harness->assertSame(true, str_contains($html, 'Stored filing date: 2026-02-14'));
        $harness->assertSame(true, str_contains($html, 'Fixed assets'));
        $harness->assertSame(true, str_contains($html, 'Current assets'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(true, str_contains($html, 'name="check_code" value="companies_house_mismatch_acknowledgement"'));
        $harness->assertSame(true, str_contains($html, 'I confirm that I have reviewed the Companies House comparison shown above and approve it as accurate for Year End.'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'renders no-approval and unavailable states', static function () use ($harness, $card): void {
        $noMismatch = companiesHouseComparisonCardContext(null);
        $noMismatch['services']['companiesHouseComparisonReview']['mismatch_count'] = 0;
        $noMismatchHtml = $card->render($noMismatch);
        $harness->assertSame(true, str_contains($noMismatchHtml, 'No Companies House mismatch approval is needed'));
        $harness->assertSame(false, str_contains($noMismatchHtml, 'name="intent" value="acknowledge_review_check"'));

        $unavailable = companiesHouseComparisonCardContext(null);
        $unavailable['services']['companiesHouseComparisonReview']['comparison'] = [
            'available' => false,
            'errors' => ['No stored Companies House accounts filings were found for this company.'],
        ];
        $unavailable['services']['companiesHouseComparisonReview']['mismatch_count'] = 0;
        $unavailableHtml = $card->render($unavailable);
        $harness->assertSame(true, str_contains($unavailableHtml, 'No stored Companies House accounts filings were found'));
        $harness->assertSame(true, str_contains($unavailableHtml, 'A Companies House filing must be available before a mismatch can be approved.'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'uses context lock state and preserves stale evidence', static function () use ($harness, $card): void {
        $lockedPending = companiesHouseComparisonCardContext(null, true);
        $lockedPendingHtml = $card->render($lockedPending);
        $harness->assertSame(true, str_contains($lockedPendingHtml, 'This accounting period is locked, so this approval cannot be changed.'));
        $harness->assertSame(true, str_contains($lockedPendingHtml, 'data-year-end-ack-checkbox disabled'));

        $lockedCurrent = companiesHouseComparisonCardContext([
            'acknowledged_at' => '2026-07-13 12:00:00',
            'acknowledged_by' => 'Comparison reviewer',
            'note' => 'Reviewed filing difference.',
            'state' => 'current',
            'current' => true,
        ], true);
        $lockedCurrentHtml = $card->render($lockedCurrent);
        $harness->assertSame(true, str_contains($lockedCurrentHtml, 'This accounting period is locked, so this approval cannot be revoked.'));
        $harness->assertSame(false, str_contains($lockedCurrentHtml, 'Revoke approval'));

        $stale = companiesHouseComparisonCardContext([
            'acknowledged_at' => '2026-07-13 12:00:00',
            'acknowledged_by' => 'Comparison reviewer',
            'note' => 'Reviewed filing difference.',
            'state' => 'stale',
            'current' => false,
        ]);
        $staleHtml = $card->render($stale);
        $harness->assertSame(true, str_contains($staleHtml, 'Previous Year End Confirmation'));
        $harness->assertSame(true, str_contains($staleHtml, 'Review required — underlying data changed.'));
        $harness->assertSame(true, str_contains($staleHtml, 'name="intent" value="acknowledge_review_check"'));
    });
});

function companiesHouseComparisonCardContext(?array $acknowledgement, bool $locked = false): array
{
    return [
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
            'settings' => [],
        ],
        'services' => [
            'companiesHouseComparisonReview' => [
                'comparison' => [
                    'available' => true,
                    'comparison_note' => 'An exact-period Companies House filing was selected, but 2 of 2 comparable values differ from the current reconstructed accounts.',
                    'filing' => ['filing_date' => '2026-02-14'],
                    'rows' => [
                        ['label' => 'Fixed assets', 'app_value' => 420.00, 'filed_value' => 250.00, 'variance' => 170.00, 'status' => 'fail'],
                        ['label' => 'Current assets', 'app_value' => 2750.00, 'filed_value' => 2300.00, 'variance' => 450.00, 'status' => 'fail'],
                    ],
                ],
                'acknowledgement' => $acknowledgement,
                'access' => [
                    'permitted' => !$locked,
                    'is_locked' => $locked,
                    'reason_code' => $locked ? 'period_locked' : '',
                    'reason' => $locked ? 'This accounting period is locked, so data entry is not permitted.' : '',
                ],
                'mismatch_count' => 2,
            ],
        ],
    ];
}
