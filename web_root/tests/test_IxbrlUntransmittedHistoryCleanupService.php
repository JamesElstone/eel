<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlUntransmittedHistoryCleanupService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlUntransmittedHistoryCleanupService $service): void {
        $harness->check($service::class, 'retains current and transmitted filing history while removing obsolete Tax Audit payloads', static function () use ($harness): void {
            $historySource = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'IxbrlUntransmittedHistoryCleanupService.php');
            $missingRunSource = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'IxbrlGenerationRunCleanupService.php');

            $harness->assertFalse(str_contains($missingRunSource, 'DELETE FROM filing_evidence_bundles'));
            $harness->assertTrue(str_contains($historySource, 'DELETE FROM ixbrl_accounts_filing_approvals'));
            $harness->assertTrue(str_contains($historySource, 'SET filing_approval_id = NULL, filing_approval_hash = NULL'));
            $harness->assertTrue(str_contains($historySource, 'submission.submitted_at IS NOT NULL'));
            $harness->assertTrue(str_contains($historySource, 'latestApprovalId'));
            $harness->assertTrue(str_contains($historySource, 'approval.id <> :retain_approval_id'));
            $harness->assertTrue(str_contains($historySource, 'ixbrl_accounts_artifacts authority_artifact'));
            $harness->assertTrue(str_contains($historySource, 'hmrc_ct_filing_approvals hmrc_approval'));
            $harness->assertTrue(str_contains($historySource, 'cleanupUnusedHistoricForAccountingPeriod'));
            $harness->assertTrue(str_contains($historySource, 'deleteObsoleteTaxAuditSnapshots'));
            $harness->assertTrue(str_contains($historySource, 'DELETE FROM corporation_tax_audit_areas'));
            $harness->assertTrue(str_contains($historySource, 'DELETE FROM corporation_tax_audit_snapshots'));
            $harness->assertTrue(str_contains($historySource, 'filing_evidence_ct_snapshots evidence'));
            $harness->assertTrue(str_contains($historySource, "UPDATE corporation_tax_computation_runs AS run"));
            $harness->assertTrue(str_contains($historySource, "SET ixbrl_status = 'not_generated'"));
            $harness->assertTrue(str_contains($historySource, 'FROM ct_period_filing_bases basis'));
            $harness->assertTrue(str_contains($historySource, 'FROM hmrc_ct600_submissions submission'));
        });
    }
);
