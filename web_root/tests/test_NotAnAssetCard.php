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
$harness->run(_not_an_assetCard::class, static function (GeneratedServiceClassTestHarness $harness, _not_an_assetCard $card): void {
    $harness->check(_not_an_assetCard::class, 'declares focused non-asset review service without loading the full checklist', static function () use ($harness, $card): void {
        $services = $card->services();
        $definition = (array)($services[0] ?? []);
        $params = (array)($definition['params'] ?? []);

        $harness->assertSame(1, count($services));
        $harness->assertSame('nonAssetReview', $definition['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\NonAssetReviewService::class, $definition['service'] ?? null);
        $harness->assertSame('fetchContext', $definition['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
        $harness->assertSame(':company.settings.tools_small_equipment_nominal_id', $params['toolsSmallEquipmentNominalId'] ?? null);
        $harness->assertSame(':company.settings.potential_asset_threshold', $params['threshold'] ?? null);
    });

    $harness->check(_not_an_assetCard::class, 'renders threshold select paginated table and export controls', static function () use ($harness, $card): void {
        $rows = [];
        for ($index = 1; $index <= 16; $index++) {
            $rows[] = [
                'date' => '2026-07-' . str_pad((string)$index, 2, '0', STR_PAD_LEFT),
                'source' => 'Transaction',
                'description' => 'Cordless drill ' . $index,
                'reference' => 'INV-' . $index,
                'amount' => 300 + $index,
            ];
        }

        $context = [
            'page' => [
                'page_id' => 'assets',
                'page_cards' => ['not_an_asset'],
            ],
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'tools_small_equipment_nominal_id' => 18,
                    'potential_asset_threshold' => 250,
                    'default_currency_symbol' => '$',
                ],
            ],
            'services' => [
                'nonAssetReview' => [
                    'candidates' => [
                        'available' => true,
                        'threshold' => 250,
                        'rows' => $rows,
                    ],
                    'data_entry' => ['permitted' => true, 'is_locked' => false, 'reason_code' => '', 'reason' => ''],
                ],
            ],
        ];

        $html = $card->render($context);
        $tables = $card->tables($context);
        $csv = $tables[0]->exportCsv();

        $harness->assertTrue(str_contains($html, 'name="intent" value="save_potential_asset_threshold"'));
        $harness->assertTrue(str_contains($html, '<option value="250" selected>$ 250.00</option>'));
        $harness->assertFalse(str_contains($html, '>Save Threshold</button>'));
        $harness->assertSame(
            1,
            preg_match(
                '/<div class="card-toolbar">\s*<div class="actions-row">(?:(?!<\/div>).)*name="potential_asset_threshold"(?:(?!<\/div>).)*<\/div>\s*<div class="actions-row">(?:(?!<\/div>).)*_table_export_prepare/s',
                $html
            )
        );
        $harness->assertTrue(str_contains($html, '$ 301.00'));
        $harness->assertTrue(str_contains($html, 'Cordless drill 1'));
        $harness->assertTrue(str_contains($html, 'Cordless drill 15'));
        $harness->assertFalse(str_contains($html, 'Cordless drill 16'));
        $harness->assertTrue(str_contains($html, 'Potential asset items'));
        $harness->assertTrue(str_contains($html, '_table_export_prepare'));
        $harness->assertTrue(str_contains($csv, 'Cordless drill 16'));
        $harness->assertTrue(str_contains($csv, 'INV-16'));
    });

    $harness->check(_not_an_assetCard::class, 'renders candidate row values', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'tools_small_equipment_nominal_id' => 18,
                    'potential_asset_threshold' => 250,
                    'default_currency_symbol' => '$',
                ],
            ],
            'services' => [
                'nonAssetReview' => [
                    'candidates' => [
                        'available' => true,
                        'threshold' => 250,
                        'rows' => [[
                            'date' => '2026-07-02',
                            'source' => 'Transaction',
                            'description' => 'Cordless drill',
                            'reference' => 'INV-7',
                            'amount' => 312.50,
                        ]],
                    ],
                    'data_entry' => ['permitted' => true, 'is_locked' => false, 'reason_code' => '', 'reason' => ''],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'name="intent" value="save_potential_asset_threshold"'));
        $harness->assertTrue(str_contains($html, '<option value="250" selected>$ 250.00</option>'));
        $harness->assertTrue(str_contains($html, 'Cordless drill'));
        $harness->assertTrue(str_contains($html, 'INV-7'));
        $harness->assertTrue(str_contains($html, '$ 312.50'));
    });

    $harness->check(_not_an_assetCard::class, 'renders open source actions for transactions and expense claims', static function () use ($harness, $card): void {
        $context = [
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'tools_small_equipment_nominal_id' => 18,
                    'potential_asset_threshold' => 250,
                    'default_currency_symbol' => '$',
                ],
            ],
            'services' => [
                'nonAssetReview' => [
                    'candidates' => [
                        'available' => true,
                        'threshold' => 250,
                        'rows' => [
                        [
                            'source_type' => 'transaction',
                            'source_id' => 51,
                            'date' => '2026-07-02',
                            'source' => 'Transaction',
                            'description' => 'Cordless drill',
                            'reference' => 'INV-7',
                            'amount' => 312.50,
                        ],
                        [
                            'source_type' => 'expense_claim',
                            'source_id' => 61,
                            'source_claim_id' => 71,
                            'date' => '2026-07-03',
                            'source' => 'Expense claim',
                            'description' => 'Tool bag',
                            'reference' => 'EXP-7',
                            'amount' => 275.00,
                        ],
                        ],
                    ],
                    'data_entry' => ['permitted' => true, 'is_locked' => false, 'reason_code' => '', 'reason' => ''],
                ],
            ],
        ];

        $html = $card->render($context);
        $csv = $card->tables($context)[0]->exportCsv();

        $harness->assertSame(2, substr_count($html, '>Open Related Workflow</button>'));
        $harness->assertSame(2, substr_count($html, '>Convert to Asset</button>'));
        $harness->assertSame(2, substr_count($html, 'name="intent" value="convert_non_asset_to_asset"'));
        $harness->assertTrue(str_contains($html, 'name="page" value="transactions"'));
        $harness->assertTrue(str_contains($html, 'name="show_card" value="transactions_imported"'));
        $harness->assertTrue(str_contains($html, 'name="month_key" value="2026-07-01"'));
        $harness->assertTrue(str_contains($html, 'name="category_filter" value="all"'));
        $harness->assertTrue(str_contains($html, 'name="source_type" value="transaction"'));
        $harness->assertTrue(str_contains($html, 'name="source_id" value="51"'));
        $harness->assertTrue(str_contains($html, 'This will recategorise the transaction and rebuild its journal'));
        $harness->assertTrue(str_contains($html, 'name="page" value="expense_claims"'));
        $harness->assertTrue(str_contains($html, 'name="show_card" value="expense_claim_editor"'));
        $harness->assertTrue(str_contains($html, 'name="claim_id" value="71"'));
        $harness->assertTrue(str_contains($html, 'name="source_type" value="expense_claim"'));
        $harness->assertTrue(str_contains($html, 'name="source_id" value="61"'));
        $harness->assertTrue(str_contains($html, 'The claim will remain posted'));
        $harness->assertTrue(str_contains($html, '<option value="tools_equipment" selected>Tools &amp; Equipment</option>'));
        $harness->assertTrue(str_contains($html, '<option value="3" selected>3 Years</option>'));
        $harness->assertTrue(str_contains($html, '<option value="straight_line" selected>Straight line</option>'));
        $harness->assertFalse(str_contains($csv, 'Open Related Workflow'));
        $harness->assertFalse(str_contains($csv, 'Convert to Asset'));
    });

    $harness->check(_not_an_assetCard::class, 'renders nominal setup helper when Tools and Small Equipment is unconfigured', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'potential_asset_threshold' => 500,
                    'default_currency_symbol' => '$',
                ],
            ],
            'services' => [
                'nonAssetReview' => [
                    'candidates' => [
                        'available' => false,
                        'threshold' => 500,
                        'rows' => [],
                    ],
                    'data_entry' => ['permitted' => true, 'is_locked' => false, 'reason_code' => '', 'reason' => ''],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '<option value="500" selected>$ 500.00</option>'));
        $harness->assertTrue(str_contains($html, 'Set the Tools &amp; Small Equipment nominal on Company Nominals'));
    });

    $harness->check(_not_an_assetCard::class, 'keeps locked periods viewable while removing mutation and approval controls', static function () use ($harness, $card): void {
        $context = [
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'tools_small_equipment_nominal_id' => 18,
                    'potential_asset_threshold' => 250,
                    'default_currency_symbol' => '$',
                ],
            ],
            'services' => [
                'nonAssetReview' => [
                    'candidates' => [
                        'available' => true,
                        'threshold' => 250,
                        'rows' => [[
                            'source_type' => 'transaction',
                            'source_id' => 51,
                            'date' => '2026-07-02',
                            'source' => 'Transaction',
                            'description' => 'Cordless drill',
                            'reference' => 'INV-7',
                            'amount' => 312.50,
                        ]],
                    ],
                    'data_entry' => [
                        'permitted' => false,
                        'is_locked' => true,
                        'reason_code' => 'period_locked',
                        'reason' => 'This accounting period is locked, so data entry is not permitted.',
                    ],
                    'acknowledgement' => null,
                ],
            ],
        ];

        $html = $card->render($context);
        $csv = $card->tables($context)[0]->exportCsv();

        $harness->assertTrue(str_contains($html, 'Period locked'));
        $harness->assertTrue(str_contains($html, 'Non-asset thresholds and conversions are read only.'));
        $harness->assertTrue(str_contains($html, '>Open Related Workflow</button>'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="save_potential_asset_threshold"'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="convert_non_asset_to_asset"'));
        $harness->assertTrue(str_contains($html, 'name="approval_confirmed" value="1" required data-year-end-ack-checkbox disabled'));
        $harness->assertTrue(str_contains($html, 'This accounting period is locked, so data entry is not permitted.'));
        $harness->assertTrue(str_contains($csv, 'Cordless drill'));

        $context['services']['nonAssetReview']['acknowledgement'] = [
            'state' => 'current',
            'current' => true,
            'acknowledged_at' => '2026-07-13 12:00:00',
            'acknowledged_by' => 'reviewer',
            'note' => 'Reviewed before locking.',
        ];
        $approvedHtml = $card->render($context);
        $harness->assertTrue(str_contains($approvedHtml, 'This accounting period is locked, so this approval cannot be revoked.'));
        $harness->assertFalse(str_contains($approvedHtml, '>Revoke approval</button>'));
    });
});
