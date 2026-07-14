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

$harness->run(_hmrc_obligations_summaryCard::class, static function (GeneratedServiceClassTestHarness $harness, _hmrc_obligations_summaryCard $card): void {
    $html = $card->render([
        'company' => ['settings' => []],
        'hmrc_obligations' => ['summary' => []],
    ]);

    $harness->check(_hmrc_obligations_summaryCard::class, 'renders official HMRC penalty and interest guidance links', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'href="https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim38515"'));
        $harness->assertTrue(str_contains($html, 'HMRC BIM38515: fines and penalties'));
        $harness->assertTrue(str_contains($html, 'href="https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm92190"'));
        $harness->assertTrue(str_contains($html, 'HMRC CTM92190: late CT interest'));
        $harness->assertTrue(str_contains($html, 'href="https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim45740"'));
        $harness->assertTrue(str_contains($html, 'HMRC BIM45740: late-paid tax interest'));
        $harness->assertSame(3, substr_count($html, 'class="button button-inline"'));
        $harness->assertSame(3, substr_count($html, 'target="_blank" rel="noopener noreferrer"'));
    });
});
