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

$harness->run(_tax_treatment_rulesCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_treatment_rulesCard $card): void {
    $context = [
        'page' => [
            'page_id' => 'tax_artifacts',
            'page_cards' => ['tax_treatment_rules'],
        ],
        'tax_treatment_rules' => [
            'rules' => [[
                'id' => 1,
                'rule_code' => 'client_entertainment_disallowable',
                'rule_version' => 'hmrc-bim45000-2026-05-26',
                'priority' => 10,
                'nominal_account_id' => 31,
                'nominal_code' => '6130',
                'account_type' => null,
                'name_contains' => null,
                'tax_treatment' => 'disallowable',
                'effective_from' => null,
                'effective_to' => null,
                'source_url' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim45000',
                'source_checked_at' => '2026-05-26',
                'review_status' => 'seeded',
                'is_active' => 1,
            ], [
                'id' => 2,
                'rule_code' => 'old_rule',
                'rule_version' => 'fixture',
                'priority' => 20,
                'nominal_code' => '9999',
                'tax_treatment' => 'other',
                'source_url' => 'https://example.test/rule',
                'source_checked_at' => '2026-05-26',
                'review_status' => 'needs_review',
                'is_active' => 0,
            ]],
        ],
    ];

    $harness->check(_tax_treatment_rulesCard::class, 'renders active treatment rules with toggle controls', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, 'client_entertainment_disallowable'));
        $harness->assertTrue(str_contains($html, 'Nominal 6130'));
        $harness->assertTrue(str_contains($html, 'Disallowable'));
        $harness->assertTrue(str_contains($html, 'bim45000'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="toggle_tax_treatment_rule"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="update_tax_treatment_rule_review_status"'));
        $harness->assertTrue(str_contains($html, '<option value="seeded" selected>Seeded</option>'));
        $harness->assertTrue(str_contains($html, '<option value="needs_review">Needs Review</option>'));
        $harness->assertTrue(str_contains($html, '<option value="reviewed">Reviewed</option>'));
        $harness->assertTrue(str_contains($html, 'name="target_is_active" value="0"'));
        $harness->assertTrue(str_contains($html, 'Disable'));
        $harness->assertSame(false, str_contains($html, 'old_rule'));
    });

    $harness->check(_tax_treatment_rulesCard::class, 'all filter includes disabled treatment rules', static function () use ($harness, $card, $context): void {
        $allContext = $context;
        $allContext['tax_treatment_rules']['status_filter'] = 'all';
        $html = $card->render($allContext);

        $harness->assertTrue(str_contains($html, 'old_rule'));
        $harness->assertTrue(str_contains($html, 'Disabled'));
        $harness->assertTrue(str_contains($html, '<option value="needs_review" selected>Needs Review</option>'));
        $harness->assertTrue(str_contains($html, 'name="target_is_active" value="1"'));
        $harness->assertTrue(str_contains($html, 'Enable'));
    });

    $harness->check(_tax_treatment_rulesCard::class, 'handle stores normalised filter input', static function () use ($harness, $card, $context): void {
        $request = new RequestFramework(
            ['page' => 'tax_artifacts'],
            ['tax_treatment_rules_status' => 'all'],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $services = new PageServiceFramework(new AppService(test_tmp_directory()));
        $handled = $card->handle($request, $services, $context, ActionResultFramework::none());

        $harness->assertSame('all', (string)$handled['tax_treatment_rules']['status_filter']);
    });
});
