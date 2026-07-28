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
                ['id' => 21, 'display_id' => 'EEL-FE-0000', 'lifecycle_status' => 'superseded', 'locked_at' => '2026-07-27', 'locked_by' => 'James', 'snapshot_count' => 2, 'artifact_count' => 0, 'eligible_for_cleanup' => true, 'retained_reasons' => []],
                ['id' => 22, 'display_id' => 'EEL-FE-1111', 'lifecycle_status' => 'current', 'locked_at' => '2026-07-28', 'locked_by' => 'James', 'snapshot_count' => 2, 'artifact_count' => 1, 'eligible_for_cleanup' => false, 'retained_reasons' => ['Latest version', 'Completed filing artifact']],
            ],
        ]]]);
        $h->assertTrue(str_contains($html, 'Unused historic'));
        $h->assertTrue(str_contains($html, 'Latest version, Completed filing artifact'));
        $h->assertTrue(str_contains($html, 'Frozen Year End filing evidence'));
    });
});
$h->run(\eel_accounts\Service\FilingEvidenceService::class, static function (GeneratedServiceClassTestHarness $h): void {
    $h->check(\eel_accounts\Service\FilingEvidenceService::class, 'defines safe historic cleanup retention checks and successor re-parenting', static function () use ($h): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'FilingEvidenceService.php');
        $h->assertTrue(str_contains($source, "artifact_status IN ('generated', 'validated', 'historical')"));
        $h->assertTrue(str_contains($source, 'UPDATE filing_evidence_bundles'));
        $h->assertTrue(str_contains($source, 'cleanupUnusedHistoricForAccountingPeriod'));
        $h->assertTrue(str_contains($source, 'is_current_for_locked_period'));
        $h->assertTrue(str_contains($source, "AND lifecycle_status <> :current_status"));
    });
});
$h->run(YearEndAction::class, static function (GeneratedServiceClassTestHarness $h): void {
    $h->check(YearEndAction::class, 'server-guards the destructive evidence cleanup behind developer options', static function () use ($h): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'YearEndAction.php');
        $h->assertTrue(str_contains($source, "cleanup_unused_historic_filing_evidence"));
        $h->assertTrue(str_contains($source, "AppConfigurationStore::get('developer_options', false)"));
        $h->assertTrue(str_contains($source, "'year.end.filing.evidence'"));
    });
});
