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
    $harness->check(_year_end_director_loan_offsetCard::class, 'uses one confirmation context service', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertCount(1, $services);
        $harness->assertSame('directorLoanOffset', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\DirectorLoanReconciliationService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchYearEndConfirmationContext', (string)($services[0]['method'] ?? ''));
    });

    $harness->check(_year_end_director_loan_offsetCard::class, 'renders director loan approval details and revoke action', static function () use ($harness, $card): void {
        $html = $card->render(yearEndDirectorLoanOffsetCardContext([
            'available' => true,
            'accounting_period' => ['id' => 70, 'period_end' => '2025-12-31'],
            'asset_nominal' => ['id' => 3, 'code' => '1200', 'name' => 'Director Loan Asset'],
            'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
            'asset_receivable' => 1000,
            'liability_payable' => 1500,
            'offset_amount' => 1000,
            'required_offset_amount' => 1000,
            'net_position' => 500,
            'net_position_label' => 'Company owes director',
            'posted_offset_amount' => 0,
            'offset_status' => 'missing',
            'offset_status_label' => 'Missing',
            'warnings' => [],
            'can_post' => true,
            'closing_balance_acknowledged' => true,
            'closing_balance_acknowledged_at' => '2026-07-06 10:00:00',
            'closing_balance_acknowledged_by' => 'Alex Example using the web_app',
        ]));

        $harness->assertSame(true, str_contains($html, 'save_director_loan_offset_acknowledgement'));
        $harness->assertSame(true, str_contains($html, 'Approved at 2026-07-06 10:00:00 by Alex Example using the web_app.'));
        $harness->assertSame(true, str_contains($html, 'name="director_loan_offset_acknowledgement" value="0"'));
        $harness->assertSame(true, str_contains($html, 'Revoke approval'));
        $harness->assertSame(false, str_contains($html, 'data-chicken-check="true"'));
        $harness->assertSame(false, str_contains($html, 'checked required'));
        $harness->assertSame(false, str_contains($html, 'Post Offset Journal'));
        $harness->assertSame(true, str_contains($html, 'director_loan_legally_enforceable_right'));
        $harness->assertSame(true, str_contains($html, 'director_loan_net_settlement_intent'));
        $harness->assertSame(true, str_contains($html, 'director_loan_set_off_evidence_note'));
        $harness->assertSame(true, str_contains($html, 'FRS 105 presentation remains gross'));
    });

    $harness->check(_year_end_director_loan_offsetCard::class, 'keeps current set-off evidence visible after the offset is posted', static function () use ($harness, $card): void {
        $html = $card->render(yearEndDirectorLoanOffsetCardContext([
            'available' => true,
            'accounting_period' => ['id' => 70, 'period_end' => '2025-12-31'],
            'asset_nominal' => ['id' => 3, 'code' => '1200', 'name' => 'Director Loan Asset'],
            'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
            'asset_receivable' => 1000,
            'liability_payable' => 1500,
            'offset_amount' => 0,
            'required_offset_amount' => 1000,
            'net_position' => 500,
            'net_position_label' => 'Company owes director',
            'posted_offset_amount' => 1000,
            'offset_status' => 'current',
            'offset_status_label' => 'Current',
            'warnings' => [],
            'can_post' => false,
            'offset_candidate_available' => false,
            'offset_journal_posted' => true,
            'current_offset_journal_posted' => true,
            'existing_offset_journal' => ['id' => 987, 'is_posted' => 1],
            'set_off_evidence_current' => true,
            'set_off_evidence_acknowledgement' => [
                'note' => 'Executed set-off agreement clause 4.',
            ],
            'set_off_evidence_note' => 'Executed set-off agreement clause 4.',
            'set_off_evidence_acknowledged_at' => '2026-07-06 10:05:00',
            'set_off_evidence_acknowledged_by' => 'Alex Example using the web_app',
        ]));

        $harness->assertSame(true, str_contains($html, 'Current'));
        $harness->assertSame(true, str_contains($html, 'FRS 105 set-off evidence'));
        $harness->assertSame(true, str_contains($html, 'Executed set-off agreement clause 4.'));
        $harness->assertSame(true, str_contains($html, 'Approved at 2026-07-06 10:05:00 by Alex Example using the web_app.'));
        $harness->assertSame(true, str_contains($html, 'cannot be revoked while a director loan offset journal remains posted'));
        $harness->assertSame(false, str_contains($html, 'name="director_loan_set_off_evidence" value="0"'));
        $harness->assertSame(false, str_contains($html, 'Revoke set-off evidence'));
    });

    $harness->check(_year_end_director_loan_offsetCard::class, 'allows evidence revocation after appended journals reverse the effective offset to zero', static function () use ($harness, $card): void {
        $html = $card->render(yearEndDirectorLoanOffsetCardContext([
            'available' => true,
            'accounting_period' => ['id' => 70, 'period_end' => '2025-12-31'],
            'asset_nominal' => ['id' => 3, 'code' => '1200', 'name' => 'Director Loan Asset'],
            'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
            'asset_receivable' => 1000,
            'liability_payable' => 1500,
            'offset_amount' => 0,
            'required_offset_amount' => 1000,
            'desired_offset_amount' => 0,
            'pending_adjustment_amount' => 0,
            'net_position' => 500,
            'net_position_label' => 'Company owes director',
            'posted_offset_amount' => 0,
            'offset_status' => 'gross_presentation',
            'offset_status_label' => 'Gross Presentation',
            'warnings' => [],
            'can_post' => false,
            'offset_candidate_available' => true,
            'offset_journal_posted' => false,
            'current_offset_journal_posted' => true,
            'existing_offset_journal' => ['id' => 988, 'is_posted' => 1],
            'set_off_evidence_current' => true,
            'set_off_evidence_acknowledgement' => [
                'note' => 'Historic evidence retained after reversal.',
            ],
            'set_off_evidence_note' => 'Historic evidence retained after reversal.',
        ]));

        $harness->assertSame(false, str_contains($html, 'cannot be revoked while a director loan offset journal remains posted'));
        $harness->assertSame(true, str_contains($html, 'name="director_loan_set_off_evidence" value="0"'));
        $harness->assertSame(true, str_contains($html, 'Revoke set-off evidence'));
    });
});

function yearEndDirectorLoanOffsetCardContext(array $offset): array
{
    return [
        'company' => [
            'id' => 33,
            'name' => 'Director Loan Fixture Limited',
            'accounting_period_id' => 70,
            'settings' => [
                'default_currency_symbol' => '&#163;',
            ],
        ],
        'services' => [
            'directorLoanOffset' => $offset,
        ],
    ];
}
