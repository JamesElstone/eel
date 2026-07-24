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
            'locked' => false,
            'intent' => 'acknowledge_review_check',
            'approveFields' => ['check_code' => 'test_check'],
            'noteMode' => \eel_accounts\Renderer\YearEndApprovalRenderer::NOTE_REQUIRED,
            'noteName' => 'review_acknowledgement_note',
            'noteId' => 'test-approval-note',
        ]);

        $harness->assertSame(true, str_contains($html, '<h3>Year End Confirmation</h3>'));
        $harness->assertSame(true, str_contains($html, '<section class="panel-soft full settings-stack">'));
        $harness->assertSame(false, str_contains($html, '<section class="panel-soft warn full settings-stack">'));
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
            'locked' => false,
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
            'locked' => false,
            'acknowledgedAt' => '2026-07-06 12:00:00',
            'acknowledgedBy' => 'unit_test',
            'note' => 'Evidence reviewed.',
            'intent' => 'acknowledge_review_check',
            'revokeIntent' => 'reopen_review_check',
            'revokeFields' => ['check_code' => 'test_check'],
            'questions' => [[
                'id' => 'scope',
                'prompt' => 'Is the position in scope?',
                'options' => ['yes' => 'Yes', 'no' => 'No'],
            ]],
            'answers' => ['scope' => 'yes'],
        ]);

        $harness->assertSame(true, str_contains($html, 'Evidence reviewed.'));
        $harness->assertSame(true, str_contains($html, 'Approved at 2026-07-06 12:00:00 by unit_test.'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="reopen_review_check"'));
        $harness->assertSame(true, str_contains($html, 'Revoke approval'));
        $harness->assertSame(true, str_contains($html, '<table class="table-condensed">'));
        $harness->assertSame(true, str_contains($html, '<th>Question</th><th>Answer</th>'));
        $harness->assertSame(true, str_contains($html, '<td>Is the position in scope?</td><td>Yes</td>'));
    });

    $harness->check(\eel_accounts\Renderer\YearEndApprovalRenderer::class, 'shows stale evidence while requiring a fresh approval', static function () use ($harness): void {
        $html = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'test position',
            'companyId' => 12,
            'accountingPeriodId' => 34,
            'acknowledged' => false,
            'acknowledgementState' => 'stale',
            'acknowledgedAt' => '2026-07-06 12:00:00',
            'acknowledgedBy' => 'unit_test',
            'note' => 'Original evidence note.',
            'intent' => 'acknowledge_review_check',
            'locked' => false,
        ]);

        $harness->assertSame(true, str_contains($html, 'Review required — underlying data changed.'));
        $harness->assertSame(true, str_contains($html, '<section class="panel-soft warn full settings-stack">'));
        $harness->assertSame(true, str_contains($html, 'Original note: Original evidence note.'));
        $harness->assertSame(true, str_contains($html, 'Approved at 2026-07-06 12:00:00 by unit_test.'));
        $harness->assertSame(true, str_contains($html, 'Approve for Year End'));
        $harness->assertSame(false, str_contains($html, 'Revoke approval'));
    });

    $harness->check(\eel_accounts\Renderer\YearEndApprovalRenderer::class, 'marks approval questions for neutral question styling', static function () use ($harness): void {
        $html = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'test position',
            'companyId' => 12,
            'accountingPeriodId' => 34,
            'acknowledged' => false,
            'locked' => false,
            'intent' => 'acknowledge_review_check',
            'questions' => [
                ['id' => 'reason', 'prompt' => 'Explain the position', 'type' => 'text', 'required' => true],
                ['id' => 'confirmed', 'prompt' => 'Is this confirmed?', 'type' => 'choice', 'options' => ['yes' => 'Yes']],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'class="form-row full year-end-approval-question"'));
        $harness->assertSame(true, str_contains($html, 'class="panel-soft full year-end-approval-question"'));
    });

    $harness->check(\eel_accounts\Renderer\YearEndApprovalRenderer::class, 'marks required answer values as approval blockers', static function () use ($harness): void {
        $html = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'Director Loan Year End facts',
            'companyId' => 12,
            'accountingPeriodId' => 34,
            'acknowledged' => false,
            'locked' => false,
            'intent' => 'approve_section_review',
            'questions' => [[
                'id' => 'ct600a.missing_parties',
                'prompt' => 'Are any participators missing?',
                'type' => 'choice',
                'options' => ['no' => 'No', 'yes' => 'Yes'],
                'required' => true,
                'required_value' => 'no',
            ]],
        ]);

        $harness->assertSame(true, str_contains($html, 'data-year-end-approval-required-value="no"'));
        $harness->assertSame(true, str_contains($html, 'data-year-end-approval-scope-warning hidden'));
    });

    $harness->check(\eel_accounts\Renderer\YearEndApprovalRenderer::class, 'can use a separately persisted question table without duplicating fields', static function () use ($harness): void {
        $html = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'Corporation Tax position',
            'companyId' => 12,
            'accountingPeriodId' => 34,
            'acknowledged' => false,
            'locked' => false,
            'intent' => 'approve_section_review',
            'renderQuestions' => false,
            'questions' => [[
                'id' => 'filing_scope.ct600b',
                'prompt' => 'Does CT600B apply?',
                'type' => 'choice',
                'options' => ['no' => 'No', 'yes' => 'Yes'],
                'required' => true,
                'required_value' => 'no',
            ]],
        ]);

        $harness->assertSame(false, str_contains($html, 'name="approval_answers[filing_scope.ct600b]"'));
        $harness->assertSame(false, str_contains($html, 'data-year-end-approval-scope-warning hidden'));
        $harness->assertSame(true, str_contains($html, 'Approve for Year End'));
    });
});
