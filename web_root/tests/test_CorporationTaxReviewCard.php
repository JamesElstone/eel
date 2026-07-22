<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(_corporation_tax_reviewCard::class, static function (GeneratedServiceClassTestHarness $h, _corporation_tax_reviewCard $card): void {
    $context = [
        'company' => ['id' => 1, 'accounting_period_id' => 2, 'settings' => ['default_currency_symbol' => '£']],
        'tax' => ['selected_ct_period_id' => 3],
        'services' => ['corporationTaxReview' => [
            'available' => true,
            'unresolved_count' => 1,
            'resolved_count' => 0,
            'items' => [[
                'journal_id' => 10, 'journal_line_id' => 11, 'journal_date' => '2025-07-02',
                'description' => 'Professional services', 'nominal_code' => '7600', 'nominal_name' => 'Professional Fees',
                'amount' => 140, 'tax_treatment' => '', 'state' => 'requires_review',
                'guidance_url' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim35500',
                'source_label' => 'Transaction #12', 'source_url' => '?page=transactions&transaction_id=12',
            ]],
        ]],
    ];
    $html = $card->render($context);
    $h->assertTrue(str_contains($html, 'Allowable'));
    $h->assertTrue(str_contains($html, 'Disallowable'));
    $h->assertTrue(str_contains($html, 'Capital'));
    $h->assertTrue(str_contains($html, 'BIM guidance'));
    $h->assertTrue(str_contains($html, 'Transaction #12'));
    $h->assertSame(false, str_contains($html, '>OK<'));
});
