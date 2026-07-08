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

$harness->run(\eel_accounts\Renderer\YearEndApprovalRenderer::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Renderer\YearEndApprovalRenderer::class, 'renders pending approval with required notes', static function () use ($harness): void {
        $html = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'test position',
            'companyId' => 12,
            'accountingPeriodId' => 34,
            'acknowledged' => false,
            'intent' => 'acknowledge_review_check',
            'approveFields' => ['check_code' => 'test_check'],
            'noteMode' => \eel_accounts\Renderer\YearEndApprovalRenderer::NOTE_REQUIRED,
            'noteName' => 'review_acknowledgement_note',
            'noteId' => 'test-approval-note',
        ]);

        $harness->assertSame(true, str_contains($html, '<div class="eyebrow">Year End Confirmation</div>'));
        $harness->assertSame(true, str_contains($html, 'I confirm that I have reviewed the test position shown above and approve it as accurate for Year End.'));
        $harness->assertSame(true, str_contains($html, 'name="review_acknowledgement_note"'));
        $harness->assertSame(true, str_contains($html, 'id="test-approval-note"'));
        $harness->assertSame(true, str_contains($html, '<textarea class="input" id="test-approval-note" name="review_acknowledgement_note" rows="3" required></textarea>'));
        $harness->assertSame(true, str_contains($html, 'Approve for Year End'));
    });

    $harness->check(\eel_accounts\Renderer\YearEndApprovalRenderer::class, 'does not duplicate checkbox field as hidden approval field', static function () use ($harness): void {
        $html = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'director loan offset',
            'companyId' => 12,
            'accountingPeriodId' => 34,
            'acknowledged' => false,
            'intent' => 'save_director_loan_offset_acknowledgement',
            'checkboxName' => 'director_loan_offset_acknowledgement',
            'approveFields' => ['director_loan_offset_acknowledgement' => '1'],
        ]);

        $harness->assertSame(1, substr_count($html, 'name="director_loan_offset_acknowledgement"'));
        $harness->assertSame(false, str_contains($html, '<input type="hidden" name="director_loan_offset_acknowledgement"'));
    });

    $harness->check(\eel_accounts\Renderer\YearEndApprovalRenderer::class, 'renders completed approval with note and revoke action', static function () use ($harness): void {
        $html = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'test position',
            'companyId' => 12,
            'accountingPeriodId' => 34,
            'acknowledged' => true,
            'acknowledgedAt' => '2026-07-06 12:00:00',
            'acknowledgedBy' => 'unit_test',
            'note' => 'Evidence reviewed.',
            'intent' => 'acknowledge_review_check',
            'revokeIntent' => 'reopen_review_check',
            'revokeFields' => ['check_code' => 'test_check'],
        ]);

        $harness->assertSame(true, str_contains($html, 'Evidence reviewed.'));
        $harness->assertSame(true, str_contains($html, 'Approved at 2026-07-06 12:00:00 by unit_test.'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="reopen_review_check"'));
        $harness->assertSame(true, str_contains($html, 'Revoke approval'));
    });
});
