<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$h = new GeneratedServiceClassTestHarness();
$h->run(_filing_evidence::class, static function (GeneratedServiceClassTestHarness $h, _filing_evidence $page): void {
    $h->check($page::class, 'registers coverage and frozen section cards beside specialist evidence', static function () use ($h, $page): void {
        $h->assertSame([
            'filing_evidence_lookup', 'filing_evidence_overview', 'filing_evidence_artifacts',
            'filing_evidence_coverage', 'filing_evidence_section_detail', 'filing_evidence_calculations',
            'filing_evidence_loans', 'filing_evidence_calculation_detail',
        ], $page->cards());
    });
});
$h->run(_filing_evidence_coverageCard::class, static function (GeneratedServiceClassTestHarness $h, _filing_evidence_coverageCard $card): void {
    $h->check($card::class, 'labels legacy section gaps without reconstructing live records', static function () use ($h, $card): void {
        $html = $card->render(['filing_evidence' => ['bundle_id' => 17, 'reference' => 'EEL-FE-TEST'], 'services' => ['filingEvidenceCoverage' => [
            'available' => true, 'sections' => [
                ['section_code' => 'transactions', 'captured' => true, 'lock_snapshot' => ['record_count' => 3, 'snapshot_hash' => str_repeat('a', 64)]],
                ['section_code' => 'assets', 'captured' => false, 'lock_snapshot' => null],
            ],
        ]]]);
        $h->assertTrue(str_contains($html, 'Show frozen records'));
        $h->assertTrue(str_contains($html, 'Not captured for this historic bundle.'));
        $h->assertTrue(str_contains($html, 'select-filing-evidence-section'));
    });
});
$h->run(_filing_evidence_section_detailCard::class, static function (GeneratedServiceClassTestHarness $h, _filing_evidence_section_detailCard $card): void {
    $h->check($card::class, 'renders a read-only frozen section payload', static function () use ($h, $card): void {
        $html = $card->render(['services' => ['filingEvidenceSectionDetail' => [
            'available' => true, 'section' => ['section_code' => 'transactions', 'snapshot_hash' => str_repeat('b', 64)],
            'rows' => [['id' => 9, 'amount' => '12.00']], 'pagination' => ['page' => 1, 'page_count' => 2],
            'lifecycle' => [['created_at' => '2026-07-28 15:00:00', 'snapshot_hash' => str_repeat('c', 64)]],
        ]]]);
        $h->assertTrue(str_contains($html, 'Frozen evidence'));
        $h->assertTrue(str_contains($html, '&quot;id&quot;'));
        $h->assertTrue(str_contains($html, 'Next'));
        $h->assertTrue(str_contains($html, 'Append-only filing lifecycle snapshots'));
    });
});
$h->run(_filing_evidence_loansCard::class, static function (GeneratedServiceClassTestHarness $h, _filing_evidence_loansCard $card): void {
    $h->check($card::class, 'renders only a frozen loan snapshot', static function () use ($h, $card): void {
        $html = $card->render(['company' => ['settings' => []], 'services' => ['filingEvidenceLoans' => [
            'available' => true, 'snapshot_version' => 'loan-filing-evidence-v1', 'snapshot_hash' => str_repeat('a', 64), 'created_at' => '2026-07-28 10:00:00',
            'snapshot' => ['applicable' => false, 'ct_periods' => [], 'ct600a' => [], 'section_413' => []],
        ]]]);
        $h->assertTrue(str_contains($html, 'Snapshot version'));
        $h->assertTrue(str_contains($html, 'No loan activity'));
    });
});
$h->run(_director_loan_filing_evidenceCard::class, static function (GeneratedServiceClassTestHarness $h, _director_loan_filing_evidenceCard $card): void {
    $h->check($card::class, 'links the current locked bundle to its immutable evidence', static function () use ($h, $card): void {
        $html = $card->render(['services' => ['loanFilingEvidenceBundles' => ['bundles' => [[
            'id' => 31, 'evidence_id' => 'EEL-FE-0123456789ABCDEF0123456789ABCDEF',
            'display_id' => 'EEL-FE-0123', 'is_current_for_locked_period' => true,
        ]]]]]);
        $h->assertTrue(str_contains($html, 'evidence_bundle_id=31'));
        $h->assertTrue(str_contains($html, 'Frozen'));
    });
});
$h->run(_filing_evidence_lookupCard::class, static function (GeneratedServiceClassTestHarness $h, _filing_evidence_lookupCard $card): void {
    $h->check($card::class, 'renders a company scoped read-only lookup action', static function () use ($h, $card): void {
        $html = $card->render(['company' => ['id' => 49], 'filing_evidence' => ['reference' => '']]);
        $h->assertTrue(str_contains($html, 'lookup-filing-evidence'));
        $h->assertTrue(str_contains($html, 'name="company_id" value="49"'));
        $h->assertSame(false, str_contains($html, 'card_action'));
    });
});
$h->run(_filing_evidence_calculation_detailCard::class, static function (GeneratedServiceClassTestHarness $h, _filing_evidence_calculation_detailCard $card): void {
    $h->check($card::class, 'labels live journal handoff separately from frozen values', static function () use ($h, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['filingEvidenceCalculationDetail' => [
                'available' => true, 'amount' => 100, 'expected_amount' => 100,
                'rows' => [[
                    'source_date' => '2025-01-31', 'label' => 'Frozen sale', 'source_label' => 'Journal #8',
                    'nominal_code' => '4000', 'nominal_name' => 'Sales', 'accounting_amount' => 100,
                    'tax_adjustment_amount' => 0, 'rule_code' => 'trading_profit', 'rule_version' => '1',
                    'journal_id' => 8,
                ]],
            ]],
        ]);
        $h->assertTrue(str_contains($html, 'Frozen evidence'));
        $h->assertTrue(str_contains($html, 'Current journal'));
        $h->assertTrue(str_contains($html, 'journal_id'));
    });
});
