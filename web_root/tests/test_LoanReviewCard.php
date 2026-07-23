<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(_loan_reviewCard::class, static function (GeneratedServiceClassTestHarness $h, _loan_reviewCard $card): void {
    $context = [
        'company' => ['id' => 4, 'accounting_period_id' => 80],
        'services' => ['loanReview' => [
            'available' => true,
            'items' => [],
            'unresolved_count' => 0,
            'future_attribution_warning' => [
                'count' => 1,
                'acknowledged' => false,
                'tax_relevant' => true,
                'movements' => [[
                    'transaction_id' => 4961,
                    'accounting_period_id' => 81,
                    'txn_date' => '2024-10-02',
                    'cash_direction' => 'payment',
                    'source_label' => 'Transaction #4961',
                    'source_url' => '?page=transactions&show_card=transactions_imported&transaction_id=4961',
                ]],
            ],
        ]],
    ];
    $html = $card->render($context);
    $h->assertTrue(str_contains($html, 'Optional future repayment attribution'));
    $h->assertTrue(str_contains($html, 'Not a blocker'));
    $h->assertTrue(str_contains($html, "Ignore - I don't want to claim S464 Tax Relief"));
    $h->assertSame(false, str_contains($html, 'Assign Participant'));
    $h->assertSame(false, str_contains($html, 'accounting_period_id=81'));

    $context['services']['loanReview']['future_attribution_warning']['tax_relevant'] = false;
    $hiddenHtml = $card->render($context);
    $h->assertSame(false, str_contains($hiddenHtml, 'Optional future repayment attribution'));
});
