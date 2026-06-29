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
                'accounting_period_id' => 2,
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

    $harness->check('_transactions_importedCard', 'renders imported transactions with table builder columns', function () use ($harness): void {
        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ]],
                'transactions_by_month' => [[
                    'id' => 42,
                    'txn_date' => '2026-03-15',
                    'description' => 'Test transaction',
                    'source_account' => 'Current account',
                    'source_category' => 'Materials',
                    'amount' => -12.34,
                    'document_download_status' => 'downloaded',
                    'local_document_path' => 'uploads/company/1/receipt.pdf',
                    'nominal_account_id' => 7,
                    'category_status' => 'manual',
                    'has_derived_journal' => 0,
                    'auto_rule_id' => 3,
                    'auto_rule_match_value' => 'Test',
                ]],
                'nominal_accounts' => [[
                    'id' => 7,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                ]],
                'company_accounts' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, '<div class="table-scroll"><table>'));
        $harness->assertSame(true, str_contains($html, 'card-toolbar transactions-imported-controls'));
        $harness->assertSame(true, str_contains($html, 'id="transaction_month_key" name="month_key"'));
        $harness->assertSame(true, str_contains($html, 'id="table-filter-transactions_imported-category_filter" name="category_filter"'));
        $harness->assertSame(false, str_contains($html, 'id="transaction_category_filter"'));
        $harness->assertSame(true, str_contains($html, 'Condensed View'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
        $autoApplyPosition = strpos($html, 'Auto Apply');
        $postCategorisedPosition = strpos($html, 'Post Categorised Transactions');
        $categoryFilterPosition = strpos($html, 'Category filter');
        $condensedViewPosition = strpos($html, 'Condensed View');
        $harness->assertSame(true, $autoApplyPosition !== false);
        $harness->assertSame(true, $postCategorisedPosition !== false);
        $harness->assertSame(true, $categoryFilterPosition !== false);
        $harness->assertSame(true, $condensedViewPosition !== false);
        $harness->assertSame(true, $autoApplyPosition < $categoryFilterPosition);
        $harness->assertSame(true, $postCategorisedPosition < $categoryFilterPosition);
        $harness->assertSame(true, $categoryFilterPosition < $condensedViewPosition);
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="run_auto_rules"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="post_categorised_transactions"'));
        $harness->assertSame(true, str_contains($html, 'name="month_key" value="2026-03-01"'));
        $harness->assertSame(true, str_contains($html, '<th>Date</th>'));
        $harness->assertSame(true, str_contains($html, 'Test transaction'));
        $harness->assertSame(true, str_contains($html, 'Matched by rule #3 (Test)'));
        $harness->assertSame(true, str_contains($html, 'View Receipt'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="save_transaction_category"'));
        $harness->assertSame(true, str_contains($html, '<span class="badge success">Manual categorised</span>'));
    });

    $harness->check('_transactions_rulesCard', 'renders categorisation rules with table builder exports', function () use ($harness): void {
        $html = (new _transactions_rulesCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'page_id' => 'transactions',
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'categorisation_rules' => [[
                    'id' => 3,
                    'priority' => 100,
                    'match_type' => 'contains',
                    'match_value' => 'Test',
                    'nominal_code' => '4000',
                    'nominal_name' => 'Sales',
                    'is_active' => 1,
                ]],
            ],
        ]);

        $exportRulesPosition = strpos($html, 'Export Rules');
        $condensedViewPosition = strpos($html, 'Condensed View');

        $harness->assertSame(true, str_contains($html, '<div class="table-scroll"><table>'));
        $harness->assertSame(true, str_contains($html, '<th>Priority</th>'));
        $harness->assertSame(true, str_contains($html, '<th>Match</th>'));
        $harness->assertSame(true, str_contains($html, '<th>Nominal</th>'));
        $harness->assertSame(true, str_contains($html, 'Contains &quot;Test&quot;'));
        $harness->assertSame(true, str_contains($html, '4000 - Sales'));
        $harness->assertSame(true, str_contains($html, '<span class="badge success">Active</span>'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="export_categorisation_rules"'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
        $harness->assertSame(true, $exportRulesPosition !== false);
        $harness->assertSame(true, $condensedViewPosition !== false);
        $harness->assertSame(true, $exportRulesPosition < $condensedViewPosition);
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="edit_categorisation_rule"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="toggle_categorisation_rule"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="delete_categorisation_rule"'));
        $harness->assertSame(2, substr_count($html, '<section class="panel-soft">'));
        $harness->assertSame(true, str_contains($html, '<h3 class="card-title">Categorisation rules</h3>'));
        $harness->assertSame(true, str_contains($html, '<h3 class="card-title">Upload exported JSON rules</h3>'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="import_categorisation_rules"'));
    });

    $harness->check('_transactions_rulesCard', 'paginates categorisation rules at fifteen rows', function () use ($harness): void {
        $rules = [];
        for ($i = 1; $i <= 16; $i++) {
            $rules[] = [
                'id' => $i,
                'priority' => $i,
                'match_type' => 'contains',
                'match_value' => 'Rule ' . $i,
                'nominal_code' => '4000',
                'nominal_name' => 'Sales',
                'is_active' => 1,
            ];
        }

        $html = (new _transactions_rulesCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'page_id' => 'transactions',
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'categorisation_rules' => $rules,
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Categorisation rules 1-15 of 16'));
        $harness->assertSame(true, str_contains($html, 'Rule 15'));
        $harness->assertSame(false, str_contains($html, 'Rule 16'));
        $harness->assertSame(true, str_contains($html, 'name="transactions_rules_page" value="2"'));
    });

    $harness->check('_transactions_importedCard', 'paginates imported transactions at twenty rows', function () use ($harness): void {
        $transactions = [];
        for ($i = 1; $i <= 21; $i++) {
            $transactions[] = [
                'id' => $i,
                'txn_date' => '2026-03-' . str_pad((string)min($i, 28), 2, '0', STR_PAD_LEFT),
                'description' => 'Imported transaction ' . $i,
                'source_account' => 'Current account',
                'source_category' => 'Materials',
                'amount' => -1 * $i,
                'document_download_status' => 'missing',
                'category_status' => 'uncategorised',
                'has_derived_journal' => 0,
            ];
        }

        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'page_id' => 'transactions',
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ]],
                'transactions_by_month' => $transactions,
                'nominal_accounts' => [],
                'company_accounts' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Imported transactions 1-20 of 21'));
        $harness->assertSame(true, str_contains($html, 'Imported transaction 20'));
        $harness->assertSame(false, str_contains($html, 'Imported transaction 21'));
        $harness->assertSame(true, str_contains($html, 'name="transactions_imported_page" value="2"'));
    });
});
