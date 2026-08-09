<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$h = new GeneratedServiceClassTestHarness();
$h->run(_year_end::class, static function (GeneratedServiceClassTestHarness $h, _year_end $page): void {
    $h->check($page::class, 'registers the selected-period evidence tab', static function () use ($h, $page): void {
        $h->assertTrue(in_array('year_end_evidence_bundles', $page->cards(), true));
        $h->assertTrue(in_array('Evidence', array_column($page->cardLayout(), 'tab'), true));
    });
});
$h->run(_year_end_evidence_bundlesCard::class, static function (GeneratedServiceClassTestHarness $h, _year_end_evidence_bundlesCard $card): void {
    $h->check($card::class, 'renders retained and unused historic evidence distinctly', static function () use ($h, $card): void {
        $html = $card->render(['company' => ['id' => 49, 'accounting_period_id' => 79], 'services' => ['year_end_evidence_bundles' => [
            'eligible_count' => 1,
            'bundles' => [
                ['id' => 21, 'display_id' => 'EEL-FE-0000', 'lifecycle_status' => 'superseded', 'locked_at' => '2026-07-27', 'locked_by' => 'James', 'snapshot_count' => 2, 'artifact_count' => 0, 'active_artifact_count' => 0, 'eligible_for_cleanup' => true, 'retained_reasons' => []],
                ['id' => 22, 'display_id' => 'EEL-FE-1111', 'lifecycle_status' => 'current', 'locked_at' => '2026-07-28', 'locked_by' => 'James', 'snapshot_count' => 2, 'artifact_count' => 8, 'active_artifact_count' => 0, 'eligible_for_cleanup' => false, 'retained_reasons' => ['Latest version', 'Transmitted filing artifact']],
            ],
        ]]]);
        $h->assertTrue(str_contains($html, 'Unused historic'));
        $h->assertTrue(str_contains($html, 'Latest version, Transmitted filing artifact'));
        $h->assertTrue(str_contains($html, '0 active artifacts'));
        $h->assertFalse(str_contains($html, '8 artifacts'));
        $h->assertTrue(str_contains($html, 'Frozen Year End filing evidence'));
        $h->assertTrue(str_contains($html, 'local filing approvals or submissions'));
        $h->assertTrue(str_contains($html, 'a full database backup is created immediately before and after cleanup'));
    });
});
$h->run(\eel_accounts\Service\FilingEvidenceService::class, static function (GeneratedServiceClassTestHarness $h): void {
    $h->check(\eel_accounts\Service\FilingEvidenceService::class, 'defines safe historic cleanup retention checks and successor re-parenting', static function () use ($h): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'FilingEvidenceService.php');
        $h->assertTrue(str_contains($source, "artifact_status IN ('generated', 'validated', 'historical')"));
        $h->assertTrue(str_contains($source, 'is_file($path)'));
        $h->assertTrue(str_contains($source, 'submission.submitted_at IS NOT NULL'));
        $h->assertTrue(str_contains($source, 'ixbrl_generation_runs'));
        $h->assertTrue(str_contains($source, 'UPDATE filing_evidence_bundles'));
        $h->assertTrue(str_contains($source, 'cleanupUnusedHistoricForAccountingPeriod'));
        $h->assertTrue(str_contains($source, 'is_current_for_locked_period'));
        $h->assertTrue(str_contains($source, "Linked filing approval"));
        $h->assertTrue(str_contains($source, "Linked HMRC submission"));
        $h->assertTrue(str_contains($source, "Linked Companies House submission"));
        $h->assertTrue(str_contains($source, "approval_count'] > 0"));
        $h->assertTrue(str_contains($source, "hmrc_submission_count'] > 0"));
        $h->assertTrue(str_contains($source, "companies_house_submission_count'] > 0"));
        $h->assertTrue(str_contains($source, 'approval.evidence_bundle_id = filing_evidence_bundles.id'));
        $h->assertTrue(str_contains($source, 'FROM hmrc_ct600_submissions submission'));
        $h->assertTrue(str_contains($source, 'FROM companies_house_accounts_submissions submission'));
        $h->assertTrue(str_contains($source, "AND lifecycle_status <> :current_status"));
    });
});
$h->run(YearEndAction::class, static function (GeneratedServiceClassTestHarness $h): void {
    $h->check(YearEndAction::class, 'server-guards the destructive evidence cleanup behind developer options', static function () use ($h): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'YearEndAction.php');
        $h->assertTrue(str_contains($source, "cleanup_unused_historic_filing_evidence"));
        $h->assertTrue(str_contains($source, "cleanup_unsubmitted_tax_history"));
        $h->assertTrue(str_contains($source, "AppConfigurationStore::get('developer_options', false)"));
        $h->assertTrue(str_contains($source, 'IxbrlUntransmittedHistoryCleanupService'));
        $h->assertTrue(str_contains($source, "'year.end.filing.evidence'"));
        $cleanupStart = strpos($source, 'private function cleanupUnusedHistoricEvidenceWithBackups(');
        $cleanupEnd = strpos($source, 'private function assertVerifiedBackup(', $cleanupStart !== false ? $cleanupStart : 0);
        $h->assertTrue($cleanupStart !== false && $cleanupEnd !== false);
        $cleanupMethod = substr($source, (int)$cleanupStart, (int)$cleanupEnd - (int)$cleanupStart);
        $eligibility = strpos($cleanupMethod, "['eligible_count']");
        $before = strpos($cleanupMethod, 'TRIGGER_EVIDENCE_PRE_CLEANUP');
        $mutation = strpos($cleanupMethod, 'cleanupUnusedHistoricForAccountingPeriod(');
        $after = strpos($cleanupMethod, 'TRIGGER_EVIDENCE_POST_CLEANUP');
        $h->assertTrue($eligibility !== false && $before !== false && $mutation !== false && $after !== false);
        $h->assertTrue($eligibility < $before && $before < $mutation && $mutation < $after);
    });
});
