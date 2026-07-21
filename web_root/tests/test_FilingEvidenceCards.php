<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$h = new GeneratedServiceClassTestHarness();
$h->run(_filing_evidence::class, static function (GeneratedServiceClassTestHarness $h, _filing_evidence $page): void {
    $h->check($page::class, 'registers the lookup overview artifact and calculation cards', static function () use ($h, $page): void {
        $h->assertSame([
            'filing_evidence_lookup', 'filing_evidence_overview', 'filing_evidence_artifacts',
            'filing_evidence_calculations', 'filing_evidence_calculation_detail',
        ], $page->cards());
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
