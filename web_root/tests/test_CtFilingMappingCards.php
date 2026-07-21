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

$harness->run(_tax_ct_computation_mappingsCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_ct_computation_mappingsCard $card): void {
    $harness->check(_tax_ct_computation_mappingsCard::class, 'renders lifecycle actions without inline styles', static function () use ($harness, $card): void {
        $html = $card->render([
            'services' => [
                'packages' => [],
                'profiles' => [ct_filing_mapping_card_profile('draft')],
            ],
        ]);

        ct_filing_mapping_card_assert_action_form($harness, $html, 'Validate');
    });
});

$harness->run(_tax_ct600_rim_mappingsCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_ct600_rim_mappingsCard $card): void {
    $harness->check(_tax_ct600_rim_mappingsCard::class, 'renders lifecycle actions without inline styles', static function () use ($harness, $card): void {
        $html = $card->render([
            'services' => [
                'packages' => [],
                'profiles' => [ct_filing_mapping_card_profile('draft')],
            ],
        ]);

        ct_filing_mapping_card_assert_action_form($harness, $html, 'Validate');
    });
});

function ct_filing_mapping_card_profile(string $status): array
{
    return [
        'id' => 17,
        'profile_name' => 'Test profile',
        'revision_no' => 2,
        'status' => $status,
        'taxonomy_version' => '2025',
        'taxonomy_artifact_version' => 'V1.0',
        'rim_version' => 'V3',
        'rim_artifact_version' => 'V1.994',
        'compatibility_status' => 'compatible',
        'compatibility_json' => '{}',
        'content_hash' => '1234567890abcdef',
    ];
}

function ct_filing_mapping_card_assert_action_form(GeneratedServiceClassTestHarness $harness, string $html, string $label): void
{
    $harness->assertTrue(str_contains($html, 'class="ct-filing-mapping-action-form"'));
    $harness->assertTrue(str_contains($html, '>' . $label . '</button>'));
    $harness->assertFalse(str_contains($html, 'style='));
}
