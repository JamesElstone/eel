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
$harness->run(_incorporation_add_sharesCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _incorporation_add_sharesCard $card
): void {
    $harness->check(_incorporation_add_sharesCard::class, 'renders NEWINC PDF draft button and populates the new share form from context', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 7],
            'incorporation_add_shares' => [
                'draft_share_class' => [
                    'share_class' => 'ORDINARY',
                    'currency' => 'GBP',
                    'quantity' => '100',
                    'aggregate_nominal_value' => '500',
                    'total_aggregate_unpaid' => '0',
                    'document_reference' => '12344321_newinc_2022-09-05.pdf',
                    'source_note' => '',
                ],
            ],
            'services' => [
                'incorporationShares' => [
                    'available' => true,
                    'share_classes' => [],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'populate_incorporation_shares_from_newinc'));
        $harness->assertSame(true, str_contains($html, 'Pull data from Companies House NEWINC Filled Document'));
        $harness->assertSame(true, str_contains($html, 'id="incorporation-share-form-new"'));
        $harness->assertSame(true, str_contains($html, 'name="share_class" value="ORDINARY"'));
        $harness->assertSame(true, str_contains($html, 'name="currency" value="GBP"'));
        $harness->assertSame(true, str_contains($html, 'name="quantity" value="100"'));
        $harness->assertSame(true, str_contains($html, 'name="aggregate_nominal_value" value="500"'));
        $harness->assertSame(true, str_contains($html, 'name="total_aggregate_unpaid" value="0"'));
        $harness->assertSame(true, str_contains($html, 'name="document_reference" value="12344321_newinc_2022-09-05.pdf"'));
    });
});
