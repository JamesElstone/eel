<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(TransactionAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof TransactionAction) {
        throw new RuntimeException('Unexpected TransactionAction instance.');
    }

    $harness->check('TransactionAction', 'implements the action interface', function () use ($harness, $instance): void {
        $harness->assertSame(true, $instance instanceof ActionInterfaceFramework);
    });

    $harness->check('TransactionAction', 'select_transaction_month returns normalised card context', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'select_transaction_month',
                'month_key' => '2026-03-01',
                'category_filter' => 'manual',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(['page.context'], $result->changedFacts());
        $harness->assertSame('2026-03-01', (string)($result->context()['month_key'] ?? ''));
        $harness->assertSame('manual', (string)($result->context()['category_filter'] ?? ''));
        $harness->assertSame('2026-03-01', (string)($result->query()['month_key'] ?? ''));
        $harness->assertSame('manual', (string)($result->query()['category_filter'] ?? ''));
    });

    $harness->check('TransactionAction', 'imported transaction filters only invalidate the imported card', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'select_transaction_month',
                'selection_source' => 'transactions_imported_filters',
                'month_key' => '2026-03-01',
                'category_filter' => 'manual',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(['transactions.imported'], $result->changedFacts());
        $harness->assertSame('2026-03-01', (string)($result->context()['month_key'] ?? ''));
        $harness->assertSame('manual', (string)($result->context()['category_filter'] ?? ''));
    });

    $harness->check('TransactionAction', 'edit_categorisation_rule preserves selected rule id', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'edit_categorisation_rule',
                'rule_id' => '42',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(42, (int)($result->context()['editing_rule_id'] ?? 0));
    });

    $harness->check('TransactionAction cards', 'transaction cards render Transaction card action forms', function () use ($harness): void {
        $context = [
            'company' => [
                'id' => 1,
                'tax_year_id' => 2,
            ],
            'page' => [
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
                'selected_transaction_filter' => 'all',
                'editing_rule_id' => 0,
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'month' => 'Mar',
                    'year' => '2026',
                    'status' => 'good',
                    'transactions' => 1,
                    'uncategorised' => 0,
                    'deferred' => 0,
                    'ready_to_post' => 1,
                ]],
                'transactions_by_month' => [],
                'nominal_accounts' => [],
                'company_accounts' => [],
                'categorisation_rules' => [[
                    'id' => 3,
                    'priority' => 100,
                    'match_type' => 'contains',
                    'match_value' => 'Test',
                    'nominal_name' => 'Sales',
                    'is_active' => 1,
                ]],
                'blank_rule_form' => [
                    'priority' => 100,
                    'match_type' => 'contains',
                    'match_value' => '',
                    'nominal_account_id' => '',
                    'is_active' => true,
                ],
                'editing_rule' => null,
                'transaction_audit_rows' => [],
            ],
        ];

        $html = (new _transactions_monthly_statusCard())->render($context)
            . (new _transactions_importedCard())->render($context)
            . (new _transactions_rulesCard())->render($context)
            . (new _transactions_rule_formCard())->render($context)
            . (new _transaction_category_audit_logCard())->render($context);

        $harness->assertSame(true, str_contains($html, 'name="card_action" value="Transaction"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="select_transaction_month"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="run_auto_rules"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="save_categorisation_rule"'));
        $harness->assertSame(true, str_contains($html, 'No transaction categorisation audit events'));
    });
});
