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

$harness->run(_transactions_importedCard::class, static function (GeneratedServiceClassTestHarness $harness, _transactions_importedCard $card): void {
    $harness->assertFalse(in_array(TransactionAction::CATEGORISATION_SUMMARY_FACT, $card->invalidationFacts(), true));

    $html = $card->render([
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
            'settings' => [],
        ],
        'page' => [
            'page_id' => 'transactions',
            'page_cards' => ['transactions_imported'],
            'month_key' => '2026-01',
            'category_filter' => 'not_posted',
            'csrf_token' => 'test-csrf',
        ],
        'services' => [
            'month_status' => [
                [
                    'month_key' => '2026-01',
                    'label' => 'Jan 2026',
                ],
            ],
            'transactions_by_month' => [],
            'nominal_accounts' => [],
            'company_accounts' => [],
            'year_end_review' => [
                'is_locked' => false,
            ],
            'pending_auto_approval_count' => 0,
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'data-auto-approval-batch-form="true"'));
    $harness->assertTrue(str_contains($html, 'data-transactions-imported-post-form="true"'));
    $harness->assertTrue(str_contains($html, 'data-initial-pending-auto-approval-count="0"'));
    $harness->assertTrue(str_contains($html, 'name="confirm_auto_categorisations" value="0"'));
    $harness->assertTrue(str_contains($html, 'name="card_action" value="Transaction"'));
    $harness->assertTrue(str_contains($html, 'name="global_action" value="sync_auto_approval_state"'));
    $harness->assertTrue(str_contains($html, 'name="csrf_token"'));
    $harness->assertTrue(str_contains($html, '<option value="auto_assigned">Auto - Assigned</option>'));
    $harness->assertTrue(str_contains($html, '<option value="auto_unreviewed">Auto - Not Reviewed</option>'));
    $harness->assertTrue(str_contains($html, '<option value="auto_unposted">Auto - Not posted</option>'));
    $harness->assertTrue(str_contains($html, '<option value="auto_confirmed">Auto - Confirmed</option>'));

    $transactions = [];
    for ($i = 1; $i <= 21; $i++) {
        $transactions[] = [
            'id' => $i,
            'txn_date' => '2026-01-' . str_pad((string)min($i, 28), 2, '0', STR_PAD_LEFT),
            'description' => 'Imported transaction ' . $i,
            'source_account' => 'Current account',
            'source_category' => 'Materials',
            'amount' => -1 * $i,
            'document_download_status' => 'missing',
            'category_status' => 'uncategorised',
            'has_derived_journal' => 0,
        ];
    }

    $pageTwoHtml = $card->render([
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
            'settings' => [],
        ],
        'page' => [
            'page_id' => 'transactions',
            'page_cards' => ['transactions_imported'],
            'month_key' => '2026-01',
            'category_filter' => 'not_posted',
            'transactions_imported_page' => 2,
            'csrf_token' => 'test-csrf',
        ],
        'services' => [
            'month_status' => [
                [
                    'month_key' => '2026-01',
                    'label' => 'Jan 2026',
                ],
            ],
            'transactions_by_month' => $transactions,
            'nominal_accounts' => [],
            'company_accounts' => [],
            'year_end_review' => [
                'is_locked' => false,
            ],
            'pending_auto_approval_count' => 0,
        ],
    ]);

    $harness->assertTrue(str_contains($pageTwoHtml, 'data-table-pagination-field="transactions_imported_page"'));
    $harness->assertTrue(str_contains($pageTwoHtml, 'data-table-pagination-page="2"'));
    $harness->assertTrue(str_contains($pageTwoHtml, 'Imported transactions 21 of 21'));
    $harness->assertFalse(str_contains($pageTwoHtml, 'data-preserve-table-pagination="false"'));

    $normalHtml = $card->render([
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
            'settings' => [],
        ],
        'page' => [
            'page_id' => 'transactions',
            'page_cards' => ['transactions_imported'],
            'month_key' => '2026-01',
            'category_filter' => 'not_posted',
            'csrf_token' => 'test-csrf',
        ],
        'services' => [
            'month_status' => [[
                'month_key' => '2026-01',
                'label' => 'Jan 2026',
            ]],
            'transactions_by_month' => [[
                'id' => 91,
                'txn_date' => '2026-01-08',
                'description' => 'Normal unsplit transaction',
                'source_account' => 'Current account',
                'source_category' => '',
                'amount' => -146.36,
                'document_download_status' => 'skipped',
                'category_status' => 'uncategorised',
                'has_derived_journal' => 0,
                'has_transaction_split' => 0,
            ]],
            'nominal_accounts' => [[
                'id' => 32,
                'code' => '1300',
                'name' => 'Tools & Equipment (FA)',
            ]],
            'company_accounts' => [],
            'year_end_review' => [
                'is_locked' => false,
            ],
            'pending_auto_approval_count' => 0,
        ],
    ]);

    $harness->assertTrue(str_contains($normalHtml, 'name="global_action" value="start_transaction_split"'));
    $harness->assertTrue(str_contains($normalHtml, '>Split</button>'));

    $interAccountBaseContext = [
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
            'settings' => [],
        ],
        'page' => [
            'page_id' => 'transactions',
            'page_cards' => ['transactions_imported'],
            'month_key' => '2026-01',
            'category_filter' => 'not_posted',
            'csrf_token' => 'test-csrf',
        ],
        'services' => [
            'month_status' => [[
                'month_key' => '2026-01',
                'label' => 'Jan 2026',
            ]],
            'transactions_by_month' => [[
                'id' => 5802,
                'account_id' => 100,
                'txn_date' => '2026-01-08',
                'description' => 'Example Bank payment to Example Trade Supplier',
                'source_account' => 'Example Bank - Current Account',
                'source_category' => '',
                'amount' => -241.46,
                'document_download_status' => 'skipped',
                'category_status' => 'uncategorised',
                'has_derived_journal' => 0,
                'has_transaction_split' => 0,
            ]],
            'nominal_accounts' => [],
            'company_accounts' => [[
                'id' => 100,
                'account_name' => 'Example Bank - Current Account',
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                'is_active' => 1,
            ]],
            'year_end_review' => [
                'is_locked' => false,
            ],
            'pending_auto_approval_count' => 0,
        ],
    ];

    $oneAccountHtml = $card->render($interAccountBaseContext);
    $harness->assertFalse(str_contains($oneAccountHtml, 'Inter A/C Trans.'));

    $twoAccountContext = $interAccountBaseContext;
    $twoAccountContext['services']['company_accounts'][] = [
        'id' => 200,
        'account_name' => 'Example Trade Supplier',
        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
        'is_active' => 1,
    ];
    $twoAccountHtml = $card->render($twoAccountContext);
    $harness->assertTrue(str_contains($twoAccountHtml, 'name="global_action" value="toggle_inter_ac_transaction"'));
    $harness->assertTrue(str_contains($twoAccountHtml, 'Inter A/C Trans.'));

    $pendingContext = $twoAccountContext;
    $pendingContext['page']['inter_ac_transaction_id'] = 5802;
    $pendingHtml = $card->render($pendingContext);
    $harness->assertTrue(str_contains($pendingHtml, 'js-transaction-inter-ac-candidate'));
    $harness->assertTrue(str_contains($pendingHtml, 'name="matched_transaction_id"'));
    $harness->assertTrue(str_contains($pendingHtml, 'name="global_action" value="save_inter_ac_transaction"'));
    $harness->assertTrue(str_contains($pendingHtml, 'class="button primary" type="submit" name="global_action" value="toggle_inter_ac_transaction"'));

    $savedContext = $twoAccountContext;
    $savedContext['services']['transactions_by_month'][0]['inter_ac_marker_id'] = 77;
    $savedContext['services']['transactions_by_month'][0]['inter_ac_marker_role'] = 'source';
    $savedContext['services']['transactions_by_month'][0]['inter_ac_peer_transaction_id'] = 5803;
    $savedContext['services']['transactions_by_month'][0]['inter_ac_peer_account_name'] = 'Example Trade Supplier';
    $savedContext['services']['transactions_by_month'][0]['inter_ac_peer_txn_date'] = '2026-01-09';
    $savedContext['services']['transactions_by_month'][0]['inter_ac_peer_description'] = 'Example Trade Supplier payment received';
    $savedContext['services']['transactions_by_month'][0]['inter_ac_peer_amount'] = '241.46';
    $savedContext['services']['transactions_by_month'][0]['category_status'] = 'auto';
    $savedContext['services']['transactions_by_month'][0]['auto_rule_id'] = 3;
    $savedContext['services']['transactions_by_month'][0]['auto_rule_match_value'] = 'Example Trade Supplier';
    $savedContext['services']['transactions_by_month'][0]['auto_approval_checked_current'] = 0;
    $savedContext['services']['transactions_by_month'][0]['auto_approval_confirmed_current'] = 0;
    $savedHtml = $card->render($savedContext);
    $harness->assertTrue(str_contains($savedHtml, '5803: Example Trade Supplier 09/01/26 Example Trade Supplier payment received 241.46'));
    $harness->assertTrue(str_contains($savedHtml, 'Posting Source'));
    $harness->assertTrue(str_contains($savedHtml, 'Inter A/C Src'));
    $harness->assertTrue(str_contains($savedHtml, 'name="global_action" value="cancel_inter_ac_transaction"'));
    $harness->assertFalse(str_contains($savedHtml, 'Matched by rule #3'));
    $harness->assertFalse(str_contains($savedHtml, 'Rule #3'));
    $harness->assertFalse(str_contains($savedHtml, 'Unconfirmed'));
    $harness->assertFalse(str_contains($savedHtml, 'name="global_action" value="mark_director_loan"'));
    $harness->assertFalse(str_contains($savedHtml, 'name="global_action" value="defer_transaction"'));
    $harness->assertFalse(str_contains($savedHtml, 'transaction-asset-form-5802'));

    $markerMatchedContext = $savedContext;
    $markerMatchedContext['services']['transactions_by_month'][0]['inter_ac_created_by'] = 'transfer_marker:auto';
    $markerMatchedHtml = $card->render($markerMatchedContext);
    $harness->assertTrue(str_contains($markerMatchedHtml, 'Matched by transfer marker'));
    $harness->assertFalse(str_contains($markerMatchedHtml, 'Posting Source'));

    $lockedSavedContext = $savedContext;
    $lockedSavedContext['services']['year_end_review']['is_locked'] = true;
    $lockedSavedHtml = $card->render($lockedSavedContext);
    $harness->assertTrue(str_contains($lockedSavedHtml, 'Posting Source'));
    $harness->assertTrue(str_contains($lockedSavedHtml, 'disabled title="Period locked"'));

    $matchedContext = $savedContext;
    $matchedContext['services']['transactions_by_month'][0]['inter_ac_marker_role'] = 'matched';
    $matchedHtml = $card->render($matchedContext);
    $harness->assertTrue(str_contains($matchedHtml, 'Inter A/C Dest'));
    $harness->assertTrue(str_contains($matchedHtml, 'name="global_action" value="cancel_inter_ac_transaction"'));

    $lockedMatchedContext = $matchedContext;
    $lockedMatchedContext['services']['year_end_review']['is_locked'] = true;
    $lockedMatchedHtml = $card->render($lockedMatchedContext);
    $harness->assertTrue(str_contains($lockedMatchedHtml, 'Inter A/C Dest'));
    $harness->assertTrue(str_contains($lockedMatchedHtml, 'disabled title="Period locked"'));

    $candidateLabelMethod = new ReflectionMethod($card, 'interAccountCandidateLabel');
    $candidateLabelMethod->setAccessible(true);
    $harness->assertSame(
        '5804: Example Bank - Current Account 10/01/26 matched transfer -241.46',
        $candidateLabelMethod->invoke($card, [
            'id' => 5804,
            'account_name' => 'Example Bank - Current Account',
            'txn_date' => '2026-01-10',
            'description' => 'matched transfer',
            'amount' => '-241.46',
        ])
    );

    $splitTransaction = [
        'id' => 92,
        'txn_date' => '2026-01-09',
        'description' => 'AMZNMKTPLACE',
        'source_account' => 'Example Bank - Current Account',
        'source_category' => '',
        'amount' => -146.36,
        'document_download_status' => 'skipped',
        'category_status' => 'manual',
        'has_derived_journal' => 0,
        'has_transaction_split' => 1,
        'transaction_split_ready' => 1,
        'transaction_split_difference' => '0.00',
        'transaction_split_lines' => [[
            'id' => 9001,
            'line_number' => 1,
            'description' => 'AMZNMKTPLACE tool item',
            'amount' => '89.99',
            'nominal_account_id' => 32,
            'notes' => 'asset line',
            'is_deferred' => 0,
            'is_complete' => 1,
            'nominal_code' => '1300',
            'nominal_name' => 'Tools & Equipment (FA)',
        ], [
            'id' => 9002,
            'line_number' => 2,
            'description' => 'AMZNMKTPLACE materials',
            'amount' => '56.37',
            'nominal_account_id' => 8,
            'notes' => '',
            'is_deferred' => 0,
            'is_complete' => 1,
            'nominal_code' => '5000',
            'nominal_name' => 'Materials',
        ]],
    ];
    $splitHtml = $card->render([
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
            'settings' => [],
        ],
        'page' => [
            'page_id' => 'transactions',
            'page_cards' => ['transactions_imported'],
            'month_key' => '2026-01',
            'category_filter' => 'not_posted',
            'csrf_token' => 'test-csrf',
        ],
        'services' => [
            'month_status' => [[
                'month_key' => '2026-01',
                'label' => 'Jan 2026',
            ]],
            'transactions_by_month' => [$splitTransaction],
            'nominal_accounts' => [[
                'id' => 32,
                'code' => '1300',
                'name' => 'Tools & Equipment (FA)',
            ], [
                'id' => 8,
                'code' => '5000',
                'name' => 'Materials',
            ]],
            'company_accounts' => [],
            'year_end_review' => [
                'is_locked' => false,
            ],
            'pending_auto_approval_count' => 0,
        ],
    ]);

    $harness->assertTrue(str_contains($splitHtml, 'name="global_action" value="merge_transaction_split"'));
    $harness->assertTrue(str_contains($splitHtml, 'name="global_action" value="add_transaction_split_line"'));
    $harness->assertTrue(str_contains($splitHtml, 'transaction-split-line-form-9001'));
    $harness->assertTrue(str_contains($splitHtml, 'name="split_line_description" value="AMZNMKTPLACE tool item"'));
    $harness->assertTrue(str_contains($splitHtml, 'name="split_line_amount" value="89.99"'));
    $harness->assertTrue(str_contains($splitHtml, 'name="split_line_description" value="AMZNMKTPLACE tool item" aria-label="Split line description" form="transaction-split-line-form-9001" data-initial-value="AMZNMKTPLACE tool item" data-autosave-submit-target=".js-transaction-split-line-autosave-submit"'));
    $harness->assertTrue(str_contains($splitHtml, 'class="input transaction-split-line-amount" type="text" inputmode="decimal" pattern="[0-9]+\.[0-9]{2}"'));
    $harness->assertTrue(str_contains($splitHtml, 'name="split_line_amount" value="89.99" aria-label="Split line amount" form="transaction-split-line-form-9001" data-initial-value="89.99" data-autosave-submit-target=".js-transaction-split-line-autosave-submit"'));
    $harness->assertTrue(str_contains($splitHtml, 'name="split_line_notes" value="asset line" aria-label="Split line note" form="transaction-split-line-form-9001" data-initial-value="asset line" data-autosave-submit-target=".js-transaction-split-line-autosave-submit"'));
    $harness->assertTrue(str_contains($splitHtml, '<select class="select transaction-split-line-nominal" name="nominal_account_id" form="transaction-split-line-form-9001" data-autosave-submit-target=".js-transaction-split-line-autosave-submit" data-autosave-require-value="1">'));
    $harness->assertTrue(str_contains($splitHtml, 'name="transaction_split_line_id" value="9001"'));
    $harness->assertTrue(str_contains($splitHtml, 'name="global_action" value="save_transaction_split_line"'));
    $harness->assertTrue(str_contains($splitHtml, 'value="save_transaction_split_line" data-blur-scope="none" hidden>Autosave split line</button>'));
    $harness->assertTrue(str_contains($splitHtml, 'name="global_action" value="defer_transaction_split_line"'));
    $harness->assertTrue(str_contains($splitHtml, 'name="global_action" value="remove_transaction_split_line"'));
    $harness->assertTrue(str_contains($splitHtml, 'Difference:'));
    $harness->assertTrue(str_contains($splitHtml, 'Ready to post'));

    $lockedHtml = $card->render([
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
            'settings' => [],
        ],
        'page' => [
            'page_id' => 'transactions',
            'page_cards' => ['transactions_imported'],
            'month_key' => '2026-01',
            'category_filter' => 'not_posted',
            'csrf_token' => 'test-csrf',
        ],
        'services' => [
            'month_status' => [[
                'month_key' => '2026-01',
                'label' => 'Jan 2026',
            ]],
            'transactions_by_month' => [$splitTransaction],
            'nominal_accounts' => [[
                'id' => 32,
                'code' => '1300',
                'name' => 'Tools & Equipment (FA)',
            ], [
                'id' => 8,
                'code' => '5000',
                'name' => 'Materials',
            ]],
            'company_accounts' => [],
            'year_end_review' => [
                'is_locked' => true,
            ],
            'pending_auto_approval_count' => 0,
        ],
    ]);

    $harness->assertTrue(str_contains($lockedHtml, 'Period locked'));
    $harness->assertTrue(str_contains($lockedHtml, 'disabled title="Period locked"'));
});
