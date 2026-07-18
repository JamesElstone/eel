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

$harness->run(_uploads::class, static function (GeneratedServiceClassTestHarness $harness, _uploads $page): void {
    $harness->check(_uploads::class, 'provides first-render upload and mapping context defaults', static function () use ($harness, $page): void {
        $context = uploadMappingInvokeModuleContext(
            $page,
            new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], []),
            ActionResultFramework::none()
        );

        $harness->assertSame(0, (int)($context['uploads']['id'] ?? -1));
        $harness->assertSame('ready', (string)($context['uploads']['filter'] ?? ''));
        $harness->assertSame(1, (int)($context['uploads']['page'] ?? 0));
        $harness->assertSame(0, (int)($context['field_mapping']['account_id'] ?? -1));
    });

    $harness->check(_uploads::class, 'preserves selected upload action context', static function () use ($harness, $page): void {
        $context = uploadMappingInvokeModuleContext(
            $page,
            new RequestFramework([], [], ['REQUEST_METHOD' => 'POST'], [], []),
            ActionResultFramework::success(context: [
                'uploads' => [
                    'id' => 42,
                    'filter' => 'all',
                    'page' => 3,
                ],
            ])
        );

        $harness->assertSame(42, (int)($context['uploads']['id'] ?? 0));
        $harness->assertSame('all', (string)($context['uploads']['filter'] ?? ''));
        $harness->assertSame(3, (int)($context['uploads']['page'] ?? 0));
    });
});

$harness->run(_source_accounts::class, static function (GeneratedServiceClassTestHarness $harness, _source_accounts $page): void {
    $harness->check(_source_accounts::class, 'provides upload sentinel for reusable field mapping card', static function () use ($harness, $page): void {
        $context = uploadMappingInvokeModuleContext(
            $page,
            new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], []),
            ActionResultFramework::none()
        );

        $harness->assertSame(0, (int)($context['uploads']['id'] ?? -1));
        $harness->assertSame(0, (int)($context['field_mapping']['account_id'] ?? -1));
    });
});

$harness->run(_statement_field_mappingCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _statement_field_mappingCard $card
): void {
    $harness->check(_statement_field_mappingCard::class, 'resolves first-render upload mapping services without missing context', static function () use ($harness, $card): void {
        $cardContext = uploadMappingBuildCardContext($card, [
            'page' => ['page_id' => 'uploads'],
            'company' => [
                'id' => 0,
                'accounting_period_id' => 0,
                'settings' => [],
            ],
            'uploads' => [
                'id' => 0,
                'filter' => 'ready',
                'page' => 1,
            ],
            'field_mapping' => [
                'account_id' => 0,
            ],
        ]);

        uploadMappingAssertNoMissingParamErrors($harness, $cardContext);
    });

    $harness->check(_statement_field_mappingCard::class, 'resolves source account mapping services without missing context', static function () use ($harness, $card): void {
        $cardContext = uploadMappingBuildCardContext($card, [
            'page' => ['page_id' => 'source_accounts'],
            'company' => [
                'id' => 0,
                'accounting_period_id' => 0,
                'settings' => [],
            ],
            'uploads' => [
                'id' => 0,
            ],
            'field_mapping' => [
                'account_id' => 0,
            ],
        ]);

        uploadMappingAssertNoMissingParamErrors($harness, $cardContext);
    });
});

$harness->run(_uploads_validate_commitCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _uploads_validate_commitCard $card
): void {
    $harness->check(_uploads_validate_commitCard::class, 'resolves first-render selected upload preview without missing context', static function () use ($harness, $card): void {
        $cardContext = uploadMappingBuildCardContext($card, [
            'page' => ['page_id' => 'uploads'],
            'company' => [
                'id' => 0,
                'accounting_period_id' => 0,
                'settings' => [],
            ],
            'uploads' => [
                'id' => 0,
                'filter' => 'ready',
                'page' => 1,
            ],
        ]);

        uploadMappingAssertNoMissingParamErrors($harness, $cardContext);
    });

    $harness->check(_uploads_validate_commitCard::class, 'warns when ready rows affect empty-month confirmations', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
                'accounting_period_id' => 34,
            ],
            'uploads' => [
                'id' => 56,
                'filter' => 'ready',
                'page' => 1,
            ],
            'services' => [
                'selected_upload_preview' => [
                    'upload' => [
                        'id' => 56,
                        'account_id' => 78,
                        'original_filename' => 'march.csv',
                    ],
                    'summary' => [
                        'rows_parsed' => 1,
                        'rows_valid' => 1,
                        'rows_invalid' => 0,
                        'rows_duplicate_within_upload' => 0,
                        'rows_duplicate_existing' => 0,
                        'rows_ready_to_import' => 1,
                    ],
                    'rows' => [
                        [
                            'row_number' => 1,
                            'chosen_txn_date' => '2026-03-12',
                            'accounting_period_label' => '2026',
                            'normalised_description' => 'Receipt',
                            'normalised_amount' => '12.34',
                            'normalised_balance' => '12.34',
                            'normalised_currency' => 'GBP',
                            'source_account' => 'Current account',
                            'source_category' => '',
                            'validation_status' => 'valid',
                        ],
                    ],
                ],
                'empty_month_upload_impact' => [
                    [
                        'month_start' => '2026-03-01',
                        'month_label' => '03/2026',
                        'row_count' => 1,
                    ],
                ],
                'accounting_guidance' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'This import will clear no-activity confirmation'));
        $harness->assertSame(true, str_contains($html, '03/2026 - 1 ready row'));
    });
});

function uploadMappingInvokeModuleContext(
    PageContextFramework $page,
    RequestFramework $request,
    ActionResultFramework $actionResult
): array {
    $method = new ReflectionMethod($page, 'moduleContext');
    $method->setAccessible(true);

    return (array)$method->invoke(
        $page,
        $request,
        new PageServiceFramework(new AppService(test_tmp_directory())),
        $actionResult,
        [
            'page' => ['page_id' => $page->id()],
            'company' => [
                'id' => 0,
                'accounting_period_id' => 0,
                'settings' => [],
            ],
        ]
    );
}

function uploadMappingBuildCardContext(CardInterfaceFramework $card, array $pageContext): array
{
    $renderer = new CardRendererFramework(new CardFactoryFramework());

    return $renderer->buildContextForCard(
        $card,
        $pageContext,
        new PageServiceFramework(new AppService(test_tmp_directory()))
    );
}

function uploadMappingAssertNoMissingParamErrors(GeneratedServiceClassTestHarness $harness, array $cardContext): void
{
    foreach ((array)($cardContext['service_errors'] ?? []) as $error) {
        if (!is_array($error)) {
            continue;
        }

        $harness->assertSame(false, (string)($error['type'] ?? '') === 'missing_param');
    }
}
