<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlUntransmittedHistoryCleanupService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlUntransmittedHistoryCleanupService $service): void {
        $harness->check($service::class, 'removes untransmitted approvals but preserves evidence bundles during cleanup', static function () use ($harness): void {
            $historySource = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'IxbrlUntransmittedHistoryCleanupService.php');
            $missingRunSource = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'IxbrlGenerationRunCleanupService.php');

            $harness->assertFalse(str_contains($historySource, 'DELETE FROM filing_evidence_bundles'));
            $harness->assertFalse(str_contains($missingRunSource, 'DELETE FROM filing_evidence_bundles'));
            $harness->assertTrue(str_contains($historySource, 'DELETE FROM ixbrl_accounts_filing_approvals'));
            $harness->assertTrue(str_contains($historySource, 'SET filing_approval_id = NULL, filing_approval_hash = NULL'));
            $harness->assertTrue(str_contains($historySource, 'submission.submitted_at IS NOT NULL'));
            $harness->assertTrue(str_contains($historySource, "UPDATE corporation_tax_computation_runs run"));
            $harness->assertTrue(str_contains($historySource, "SET ixbrl_status = 'not_generated'"));
            $harness->assertTrue(str_contains($historySource, 'FROM ct_period_filing_bases basis'));
            $harness->assertTrue(str_contains($historySource, 'FROM hmrc_ct600_submissions submission'));
        });
    }
);
