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
    $harness->check(_year_end_companies_house_comparisonCard::class, 'declares focused comparison and revised-accounts filing services', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertCount(2, $services);
        $harness->assertSame('companiesHouseComparisonReview', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\CompaniesHouseComparisonReviewService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchContext', (string)($services[0]['method'] ?? ''));
        $harness->assertSame('companiesHouseAccountsFiling', (string)($services[1]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class, (string)($services[1]['service'] ?? ''));
        $harness->assertSame('fetchContext', (string)($services[1]['method'] ?? ''));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'renders comparison rows and pending approval controls', static function () use ($harness, $card): void {
        $context = companiesHouseComparisonCardContext(null);
        $context['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext();
        $context['services']['companiesHouseComparisonReview']['can_acknowledge'] = true;
        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'Stored filing date: 2026-02-14'));
        $harness->assertSame(true, str_contains($html, 'Fixed assets'));
        $harness->assertSame(true, str_contains($html, 'Current assets'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(true, str_contains($html, 'name="check_code" value="companies_house_mismatch_acknowledgement"'));
        $harness->assertSame(true, str_contains($html, 'I confirm that I have reviewed the Companies House comparison shown above and approve it as accurate for Year End.'));
        $harness->assertSame(true, strpos($html, 'Companies House Revised Accounts Filing') < strpos($html, 'Companies House Comparison'));
        $harness->assertSame(true, str_contains($html, 'class="summary-grid four"'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'does not require a separate CT-period associated-company confirmation', static function () use ($harness, $card): void {
        $context = companiesHouseComparisonCardContext(null);
        $context['services']['companiesHouseComparisonReview']['can_acknowledge'] = true;
        $html = $card->render($context);
        $harness->assertSame(false, str_contains($html, 'Confirm the associated-company count for this CT period.'));
        $harness->assertSame(false, str_contains($html, 'data-year-end-ack-checkbox disabled'));
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

    $harness->check(_year_end_companies_house_comparisonCard::class, 'fails closed until Year End and gateway eligibility are confirmed', static function () use ($harness, $card): void {
        $unlocked = companiesHouseComparisonCardContext(null);
        $unlocked['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext(['locked' => false]);
        $unlockedHtml = $card->render($unlocked);
        $harness->assertSame(true, str_contains($unlockedHtml, 'Lock Year End before preparing or filing revised accounts.'));
        $harness->assertSame(false, str_contains($unlockedHtml, 'name="intent" value="prepare_revised_accounts"'));

        $pending = companiesHouseComparisonCardContext(null, true);
        $pending['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext();
        $pendingHtml = $card->render($pending);
        $harness->assertSame(true, str_contains($pendingHtml, 'Eligibility confirmation required'));
        $harness->assertSame(true, str_contains($pendingHtml, 'name="intent" value="record_gateway_eligibility"'));
        $harness->assertSame(true, str_contains($pendingHtml, 'name="eligibility_evidence"'));

        $ineligible = companiesHouseComparisonCardContext(null, true);
        $ineligible['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext([
            'eligibility' => ['decision' => 'ineligible', 'evidence' => ['Paper amendment required.']],
        ]);
        $ineligibleHtml = $card->render($ineligible);
        $harness->assertSame(true, str_contains($ineligibleHtml, 'Use the paper amendment route.'));
        $harness->assertSame(false, str_contains($ineligibleHtml, 'name="intent" value="prepare_revised_accounts"'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'shows precise readiness blockers and prepare controls', static function () use ($harness, $card): void {
        $blocked = companiesHouseComparisonCardContext(null, true);
        $blocked['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext([
            'eligibility' => ['decision' => 'eligible'],
            'readiness' => ['ready_for_filing' => false, 'filing_errors' => ['Arelle validation has not passed.']],
            'blockers' => ['A current filing artifact is required.'],
        ]);
        $blockedHtml = $card->render($blocked);
        $harness->assertSame(true, str_contains($blockedHtml, 'A current filing artifact is required.'));
        $harness->assertSame(true, str_contains($blockedHtml, 'Arelle validation has not passed.'));
        $harness->assertSame(false, str_contains($blockedHtml, 'name="intent" value="prepare_revised_accounts"'));

        $ready = companiesHouseComparisonCardContext(null, true);
        $ready['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext([
            'eligibility' => ['decision' => 'eligible'],
            'readiness' => ['ready_for_filing' => true, 'filing_errors' => []],
            'can_prepare' => true,
            'blockers' => [],
        ]);
        $readyHtml = $card->render($ready);
        $harness->assertSame(true, str_contains($readyHtml, 'Ready to prepare'));
        $harness->assertSame(true, str_contains($readyHtml, 'name="intent" value="prepare_revised_accounts"'));
        $harness->assertSame(true, str_contains($readyHtml, 'name="non_compliance_explanation"'));
        $harness->assertSame(true, str_contains($readyHtml, 'Preparing creates an immutable revised-accounts artifact'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'renders prepared LIVE confirmation without exposing stored credentials', static function () use ($harness, $card): void {
        $context = companiesHouseComparisonCardContext(null, true);
        $context['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext([
            'feature' => ['mode' => 'LIVE', 'enabled' => true, 'live_approved' => true],
            'eligibility' => ['decision' => 'eligible'],
            'submission' => ['id' => 77, 'status' => 'prepared', 'submission_number' => '000077'],
            'prepared_artifact' => [
                'filename' => 'revised-accounts.xhtml',
                'sha256' => str_repeat('a', 64),
                'basis_hash' => str_repeat('b', 64),
                'validation_status' => 'passed',
                'external_validation_status' => 'passed',
            ],
            'can_submit' => true,
        ]);
        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'Prepared for submission'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="submit_revised_accounts"'));
        $harness->assertSame(true, str_contains($html, 'name="company_auth_code"'));
        $harness->assertSame(true, str_contains($html, 'SUBMIT LIVE REVISED ACCOUNTS'));
        $harness->assertSame(false, str_contains($html, 'value="secret-code"'));
        $harness->assertSame(false, str_contains($html, 'name="company_auth_code" minlength="6" maxlength="8" pattern="[A-Za-z0-9]{6,8}" required autocomplete="off" disabled'));
    });

    $harness->check(_year_end_companies_house_comparisonCard::class, 'renders asynchronous and terminal gateway states', static function () use ($harness, $card): void {
        foreach (['submitting', 'pending', 'parked', 'transport_unknown'] as $status) {
            $context = companiesHouseComparisonCardContext(null, true);
            $context['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext([
                'eligibility' => ['decision' => 'eligible'],
                'submission' => ['id' => 88, 'status' => $status, 'submission_number' => '000088'],
            ]);
            $html = $card->render($context);
            $harness->assertSame(true, str_contains($html, 'name="intent" value="refresh_revised_accounts_status"'));
            $harness->assertSame(false, str_contains($html, 'name="intent" value="submit_revised_accounts"'));
        }

        $rejected = companiesHouseComparisonCardContext(null, true);
        $rejected['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext([
            'eligibility' => ['decision' => 'eligible'],
            'submission' => ['id' => 89, 'status' => 'rejected', 'examiner_comments' => ['Revised statement is incomplete.']],
        ]);
        $rejectedHtml = $card->render($rejected);
        $harness->assertSame(true, str_contains($rejectedHtml, 'Submission rejected'));
        $harness->assertSame(true, str_contains($rejectedHtml, 'Revised statement is incomplete.'));

        $accepted = companiesHouseComparisonCardContext(null, true);
        $accepted['services']['companiesHouseAccountsFiling'] = companiesHouseAccountsFilingContext([
            'eligibility' => ['decision' => 'eligible'],
            'submission' => ['id' => 90, 'status' => 'accepted', 'gateway_reference' => 'CH-ACCEPTED'],
        ]);
        $acceptedHtml = $card->render($accepted);
        $harness->assertSame(true, str_contains($acceptedHtml, 'Companies House accepted the revised accounts.'));
        $harness->assertSame(true, str_contains($acceptedHtml, 'CH-ACCEPTED'));
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

function companiesHouseAccountsFilingContext(array $overrides = []): array
{
    $base = [
        'company' => ['id' => 12, 'company_name' => 'Fixture Limited', 'company_number' => '12345678'],
        'accounting_period' => ['id' => 34, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
        'locked' => true,
        'feature' => ['mode' => 'TEST', 'enabled' => true, 'live_approved' => false],
        'eligibility' => [
            'decision' => 'pending',
            'detected_channel' => 'webfiling',
            'original_document_id' => 56,
            'evidence' => [],
            'response_reference' => '',
        ],
        'readiness' => ['ready_for_filing' => false, 'filing_errors' => []],
        'submission' => null,
        'prepared_artifact' => null,
        'can_prepare' => false,
        'can_submit' => false,
        'blockers' => [],
    ];

    return array_replace_recursive($base, $overrides);
}
