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

$harness->run(\eel_accounts\Service\ExpenseClaimService::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof \eel_accounts\Service\ExpenseClaimService) {
        $harness->skip('Expense claim service did not instantiate.');
    }

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'parses historical Word table claim lines', function () use ($harness, $instance): void {
        $result = expenseClaimServiceParseBulkLines($instance, expenseClaimServiceHistoricalPaste(), 'd/m/Y');

        $harness->assertSame(true, (bool)($result['success'] ?? false));
        $harness->assertCount(13, (array)($result['rows'] ?? []));
        $harness->assertSame(1272.40, (float)($result['total'] ?? 0));

        $rows = (array)$result['rows'];
        $harness->assertSame('2022-10-05', (string)($rows[0]['expense_date'] ?? ''));
        $harness->assertSame('05/10/2022', (string)($rows[0]['expense_date_display'] ?? ''));
        $harness->assertSame('ElectricFix, Wall Chaser', (string)($rows[0]['description'] ?? ''));
        $harness->assertSame(94.99, (float)($rows[0]['amount'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'formats bulk preview dates with company display format', function () use ($harness, $instance): void {
        $source = "DATE\tDESCRIPTION\tAMOUNT CLAIMED\n5/10/2022\tElectricFix, Wall Chaser\t£94.99";

        $ymd = expenseClaimServiceParseBulkLines($instance, $source, 'Y-m-d');
        $slash = expenseClaimServiceParseBulkLines($instance, $source, 'd/m/Y');
        $dash = expenseClaimServiceParseBulkLines($instance, $source, 'd-m-Y');

        $harness->assertSame('2022-10-05', (string)(($ymd['rows'][0] ?? [])['expense_date_display'] ?? ''));
        $harness->assertSame('05/10/2022', (string)(($slash['rows'][0] ?? [])['expense_date_display'] ?? ''));
        $harness->assertSame('05-10-2022', (string)(($dash['rows'][0] ?? [])['expense_date_display'] ?? ''));
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'ignores non-line rows in pasted claim forms', function () use ($harness, $instance): void {
        $source = "Claimant\tAlex Example\nYear\t2022\tMonth\tOctober\nDATE\tDESCRIPTION\tAMOUNT CLAIMED\n-\t-\t-\n5/10/2022\tElectricFix, Wall Chaser\t£94.99\nTotal Amount Claimed (sum of above lines)\tB\t£94.99\nDirector's Signature\t\nAmount Paid\t\tDate Paid\t\tFA Proc. Date\t\tFA Ref #\t";
        $result = expenseClaimServiceParseBulkLines($instance, $source, 'd/m/Y');

        $harness->assertSame(true, (bool)($result['success'] ?? false));
        $harness->assertCount(1, (array)($result['rows'] ?? []));
        $harness->assertSame(94.99, (float)($result['total'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'bulk import skips duplicate claim lines and imports new rows', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $source = "DATE\tDESCRIPTION\tAMOUNT CLAIMED\n"
                . "5/5/2026\tMaterials\t£10.00\n"
                . "6/5/2026\tFuel\t£20.00";

            $first = $instance->bulkSaveLines((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'pasted_lines' => $source,
                'date_format' => 'd/m/Y',
            ]);
            $harness->assertSame(true, (bool)($first['success'] ?? false));
            $harness->assertSame('2 expense lines imported.', (string)(($first['messages'] ?? [])[0] ?? ''));
            $harness->assertSame(2, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claim_lines WHERE expense_claim_id = :claim_id',
                ['claim_id' => (int)$fixture['claim_id']]
            ));

            $second = $instance->bulkSaveLines((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'pasted_lines' => $source,
                'date_format' => 'd/m/Y',
            ]);
            $harness->assertSame(true, (bool)($second['success'] ?? false));
            $harness->assertSame('0 expense lines imported; 2 duplicate lines skipped.', (string)(($second['messages'] ?? [])[0] ?? ''));
            $harness->assertSame(2, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claim_lines WHERE expense_claim_id = :claim_id',
                ['claim_id' => (int)$fixture['claim_id']]
            ));

            $mixed = $source . "\n7/5/2026\tParking\t£3.50";
            $third = $instance->bulkSaveLines((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'pasted_lines' => $mixed,
                'date_format' => 'd/m/Y',
            ]);
            $harness->assertSame(true, (bool)($third['success'] ?? false));
            $harness->assertSame('1 expense line imported; 2 duplicate lines skipped.', (string)(($third['messages'] ?? [])[0] ?? ''));
            $harness->assertSame(3, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claim_lines WHERE expense_claim_id = :claim_id',
                ['claim_id' => (int)$fixture['claim_id']]
            ));

            $rounded = $instance->bulkSaveLines((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'pasted_lines' => "8/5/2026\t  Rounded duplicate  \t£12.345",
                'date_format' => 'd/m/Y',
            ]);
            $harness->assertSame(true, (bool)($rounded['success'] ?? false));
            $harness->assertSame('1 expense line imported.', (string)(($rounded['messages'] ?? [])[0] ?? ''));

            $roundedDuplicate = $instance->bulkSaveLines((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'pasted_lines' => "8/5/2026\tRounded duplicate\t£12.35",
                'date_format' => 'd/m/Y',
            ]);
            $harness->assertSame(true, (bool)($roundedDuplicate['success'] ?? false));
            $harness->assertSame('0 expense lines imported; 1 duplicate line skipped.', (string)(($roundedDuplicate['messages'] ?? [])[0] ?? ''));
            $harness->assertSame(4, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claim_lines WHERE expense_claim_id = :claim_id',
                ['claim_id' => (int)$fixture['claim_id']]
            ));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'confirms no-lines draft claims and rejects invalid confirmation states', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $confirmed = $instance->confirmNoLines((int)$fixture['company_id'], (int)$fixture['claim_id'], 'tester');

            $harness->assertSame(true, (bool)($confirmed['success'] ?? false));
            $harness->assertSame(true, (bool)(($confirmed['claim'] ?? [])['no_lines_confirmed'] ?? false));
            $harness->assertSame('tester', (string)(($confirmed['claim'] ?? [])['no_lines_confirmed_by'] ?? ''));
            $harness->assertSame('No-lines month confirmed.', (string)(($confirmed['messages'] ?? [])[0] ?? ''));

            expenseClaimServiceInsertLine($fixture, (int)$fixture['claim_id'], 1, '2026-05-05', 'Materials', 10.00);
            $withLinesRejected = $instance->confirmNoLines((int)$fixture['company_id'], (int)$fixture['claim_id'], 'tester');
            $harness->assertSame(false, (bool)($withLinesRejected['success'] ?? true));
            $harness->assertSame('This claim already has lines, so submit the claim instead.', (string)(($withLinesRejected['errors'] ?? [])[0] ?? ''));

            $postedClaimId = expenseClaimServiceInsertClaim($fixture, 2026, 6, '2026-06-01', '2026-06-30');
            \InterfaceDB::prepareExecute(
                'UPDATE expense_claims SET status = :status WHERE id = :id',
                ['status' => 'posted', 'id' => $postedClaimId]
            );
            $postedRejected = $instance->confirmNoLines((int)$fixture['company_id'], $postedClaimId, 'tester');
            $harness->assertSame(false, (bool)($postedRejected['success'] ?? true));
            $harness->assertSame('Posted claims are already locked.', (string)(($postedRejected['errors'] ?? [])[0] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'adding or importing lines clears no-lines confirmation', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $instance->confirmNoLines((int)$fixture['company_id'], (int)$fixture['claim_id'], 'tester');
            $saved = $instance->saveLine((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'expense_date' => '2026-05-05',
                'description' => 'Materials',
                'amount' => '10.00',
                'nominal_account_id' => (int)$fixture['line_nominal_id'],
            ]);

            $harness->assertSame(true, (bool)($saved['success'] ?? false));
            $harness->assertSame(false, (bool)(($saved['claim'] ?? [])['no_lines_confirmed'] ?? true));
            $harness->assertSame('', (string)(($saved['claim'] ?? [])['no_lines_confirmed_at'] ?? 'not-empty'));

            $bulkClaimId = expenseClaimServiceInsertClaim($fixture, 2026, 6, '2026-06-01', '2026-06-30');
            $instance->confirmNoLines((int)$fixture['company_id'], $bulkClaimId, 'tester');
            $imported = $instance->bulkSaveLines((int)$fixture['company_id'], $bulkClaimId, [
                'pasted_lines' => "5/6/2026\tFuel\t£20.00",
                'date_format' => 'd/m/Y',
            ]);

            $harness->assertSame(true, (bool)($imported['success'] ?? false));
            $harness->assertSame(false, (bool)(($imported['claim'] ?? [])['no_lines_confirmed'] ?? true));
            $harness->assertSame('', (string)(($imported['claim'] ?? [])['no_lines_confirmed_at'] ?? 'not-empty'));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'links repayment for the full transaction amount using default expense nominal', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $transactionId = expenseClaimServiceInsertTransaction($fixture, -123.45, 'repayment-full');

            $result = $instance->linkPayment((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'transaction_id' => $transactionId,
                'default_expense_nominal_id' => (int)$fixture['expense_nominal_id'],
                'default_bank_nominal_id' => (int)$fixture['bank_nominal_id'],
            ]);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame('Repayment Only', (string)(($result['claim'] ?? [])['status_label'] ?? ''));
            $harness->assertSame(0, (int)(($result['claim'] ?? [])['line_count'] ?? -1));
            $harness->assertSame(1, (int)(($result['claim'] ?? [])['payment_link_count'] ?? 0));
            $summary = array_values(array_filter(
                (array)($result['claims'] ?? []),
                static fn(array $claim): bool => (int)($claim['id'] ?? 0) === (int)$fixture['claim_id']
            ))[0] ?? [];
            $harness->assertSame('Repayment Only', (string)($summary['status_label'] ?? ''));
            $harness->assertSame(0, (int)($summary['line_count'] ?? -1));
            $harness->assertSame(1, (int)($summary['payment_link_count'] ?? 0));
            $harness->assertSame(123.45, (float)\InterfaceDB::fetchColumn(
                'SELECT linked_amount FROM expense_claim_payment_links WHERE expense_claim_id = :claim_id AND transaction_id = :transaction_id',
                ['claim_id' => (int)$fixture['claim_id'], 'transaction_id' => $transactionId]
            ));
            $transaction = \InterfaceDB::fetchOne(
                'SELECT nominal_account_id, category_status FROM transactions WHERE id = :id',
                ['id' => $transactionId]
            );
            $harness->assertSame((int)$fixture['expense_nominal_id'], (int)($transaction['nominal_account_id'] ?? 0));
            $harness->assertSame('manual', (string)($transaction['category_status'] ?? ''));

            $otherClaimId = expenseClaimServiceInsertClaim($fixture, 2026, 6, '2026-06-01', '2026-06-30');
            $otherTransactionId = expenseClaimServiceInsertTransaction($fixture, -50.00, 'repayment-linked-elsewhere');
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claim_payment_links (expense_claim_id, transaction_id, linked_amount)
                 VALUES (:expense_claim_id, :transaction_id, :linked_amount)',
                [
                    'expense_claim_id' => $otherClaimId,
                    'transaction_id' => $otherTransactionId,
                    'linked_amount' => 50.00,
                ]
            );

            $rejected = $instance->linkPayment((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'transaction_id' => $otherTransactionId,
                'default_expense_nominal_id' => (int)$fixture['expense_nominal_id'],
                'default_bank_nominal_id' => (int)$fixture['bank_nominal_id'],
            ]);

            $harness->assertSame(false, (bool)($rejected['success'] ?? true));
            $harness->assertSame('That transaction is already linked to another expense claim.', (string)(($rejected['errors'] ?? [])[0] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'links repayment to posted claim without changing original claim journal', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            expenseClaimServiceInsertLine($fixture, (int)$fixture['claim_id'], 1, '2026-05-05', 'Materials', 94.99);

            $posted = $instance->postClaim((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'default_expense_nominal_id' => (int)$fixture['expense_nominal_id'],
            ]);

            $harness->assertSame(true, (bool)($posted['success'] ?? false));
            $postedJournalId = (int)\InterfaceDB::fetchColumn(
                'SELECT posted_journal_id FROM expense_claims WHERE id = :id',
                ['id' => (int)$fixture['claim_id']]
            );
            $harness->assertTrue($postedJournalId > 0);

            $transactionId = expenseClaimServiceInsertTransaction($fixture, -94.99, 'posted-claim-repayment');
            $linked = $instance->linkPayment((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'transaction_id' => $transactionId,
                'default_expense_nominal_id' => (int)$fixture['expense_nominal_id'],
                'default_bank_nominal_id' => (int)$fixture['bank_nominal_id'],
            ]);

            $harness->assertSame(true, (bool)($linked['success'] ?? false));
            $harness->assertSame(true, (bool)(($linked['claim'] ?? [])['is_posted'] ?? false));
            $harness->assertSame(1, (int)(($linked['claim'] ?? [])['payment_link_count'] ?? 0));
            $harness->assertSame([0.00, 94.99, 94.99, 0.00], expenseClaimServiceClaimTotals((int)$fixture['claim_id']));
            $harness->assertSame(94.99, (float)\InterfaceDB::fetchColumn(
                'SELECT linked_amount FROM expense_claim_payment_links WHERE expense_claim_id = :claim_id AND transaction_id = :transaction_id',
                ['claim_id' => (int)$fixture['claim_id'], 'transaction_id' => $transactionId]
            ));

            $harness->assertSame($postedJournalId, (int)\InterfaceDB::fetchColumn(
                'SELECT posted_journal_id FROM expense_claims WHERE id = :id',
                ['id' => (int)$fixture['claim_id']]
            ));
            $journalTotals = \InterfaceDB::fetchOne(
                'SELECT COUNT(*) AS line_count,
                        COALESCE(SUM(debit), 0) AS debit_total,
                        COALESCE(SUM(credit), 0) AS credit_total
                 FROM journal_lines
                 WHERE journal_id = :journal_id',
                ['journal_id' => $postedJournalId]
            );
            $harness->assertSame(2, (int)($journalTotals['line_count'] ?? 0));
            $harness->assertSame(94.99, (float)($journalTotals['debit_total'] ?? 0));
            $harness->assertSame(94.99, (float)($journalTotals['credit_total'] ?? 0));

            $unlinkRejected = $instance->unlinkPayment(
                (int)$fixture['company_id'],
                (int)$fixture['claim_id'],
                (int)\InterfaceDB::fetchColumn(
                    'SELECT id FROM expense_claim_payment_links WHERE expense_claim_id = :claim_id AND transaction_id = :transaction_id',
                    ['claim_id' => (int)$fixture['claim_id'], 'transaction_id' => $transactionId]
                )
            );
            $harness->assertSame(false, (bool)($unlinkRejected['success'] ?? true));
            $harness->assertSame('Posted claims are locked.', (string)(($unlinkRejected['errors'] ?? [])[0] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'recalculates claimant series in date order including payments and posted later claims', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $mayClaimId = (int)$fixture['claim_id'];
            $juneClaimId = expenseClaimServiceInsertClaim($fixture, 2026, 6, '2026-06-01', '2026-06-30');
            $julyClaimId = expenseClaimServiceInsertClaim($fixture, 2026, 7, '2026-07-01', '2026-07-31');
            $paymentTransactionId = expenseClaimServiceInsertTransaction($fixture, -25.00, 'series-payment');

            expenseClaimServiceInsertLine($fixture, $mayClaimId, 1, '2026-05-05', 'May materials', 100.00);
            expenseClaimServiceInsertLine($fixture, $juneClaimId, 1, '2026-06-05', 'June materials', 50.00);
            expenseClaimServiceInsertLine($fixture, $julyClaimId, 1, '2026-07-05', 'July materials', 75.00);
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claim_payment_links (expense_claim_id, transaction_id, linked_amount)
                 VALUES (:expense_claim_id, :transaction_id, :linked_amount)',
                [
                    'expense_claim_id' => $juneClaimId,
                    'transaction_id' => $paymentTransactionId,
                    'linked_amount' => 25.00,
                ]
            );
            \InterfaceDB::prepareExecute(
                'UPDATE expense_claims
                 SET status = :status,
                     brought_forward_amount = 999.00,
                     claimed_amount = 999.00,
                     payments_amount = 999.00,
                     carried_forward_amount = 999.00
                 WHERE id = :id',
                ['status' => 'posted', 'id' => $julyClaimId]
            );

            $instance->recalculateClaimSeries((int)$fixture['company_id'], (int)$fixture['claimant_id']);

            $harness->assertSame([0.00, 100.00, 0.00, 100.00], expenseClaimServiceClaimTotals($mayClaimId));
            $harness->assertSame([100.00, 50.00, 25.00, 125.00], expenseClaimServiceClaimTotals($juneClaimId));
            $harness->assertSame([125.00, 75.00, 0.00, 200.00], expenseClaimServiceClaimTotals($julyClaimId));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'recalculates only the requested claimant series until company repair is requested', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $otherClaimantId = (int)('89' . (string)$fixture['marker']);
            $otherClaimId = (int)('90' . (string)$fixture['marker']);
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claimants (id, company_id, claimant_name, is_active)
                 VALUES (:id, :company_id, :claimant_name, 1)',
                [
                    'id' => $otherClaimantId,
                    'company_id' => (int)$fixture['company_id'],
                    'claimant_name' => 'Other Claimant ' . (string)$fixture['marker'],
                ]
            );
            expenseClaimServiceInsertClaimWithId($fixture, $otherClaimId, $otherClaimantId, 2026, 5, '2026-05-01', '2026-05-31');

            expenseClaimServiceInsertLine($fixture, (int)$fixture['claim_id'], 1, '2026-05-05', 'Main materials', 10.00);
            expenseClaimServiceInsertLine($fixture, $otherClaimId, 1, '2026-05-05', 'Other materials', 20.00);
            \InterfaceDB::prepareExecute(
                'UPDATE expense_claims
                 SET brought_forward_amount = 777.00,
                     claimed_amount = 777.00,
                     payments_amount = 777.00,
                     carried_forward_amount = 777.00
                 WHERE id = :id',
                ['id' => $otherClaimId]
            );

            $instance->recalculateClaimSeries((int)$fixture['company_id'], (int)$fixture['claimant_id']);

            $harness->assertSame([0.00, 10.00, 0.00, 10.00], expenseClaimServiceClaimTotals((int)$fixture['claim_id']));
            $harness->assertSame([777.00, 777.00, 777.00, 777.00], expenseClaimServiceClaimTotals($otherClaimId));

            $instance->recalculateCompanyClaimSeries((int)$fixture['company_id']);

            $harness->assertSame([0.00, 20.00, 0.00, 20.00], expenseClaimServiceClaimTotals($otherClaimId));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'recalculates a large claimant series with grouped totals', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $largeClaimantId = (int)('91' . (string)$fixture['marker']);
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claimants (id, company_id, claimant_name, is_active)
                 VALUES (:id, :company_id, :claimant_name, 1)',
                [
                    'id' => $largeClaimantId,
                    'company_id' => (int)$fixture['company_id'],
                    'claimant_name' => 'Large Claimant ' . (string)$fixture['marker'],
                ]
            );

            $baseClaimId = $largeClaimantId * 10000;
            $claimStmt = \InterfaceDB::prepare(
                'INSERT INTO expense_claims (
                    id,
                    company_id,
                    accounting_period_id,
                    claimant_id,
                    claim_year,
                    claim_month,
                    period_start,
                    period_end,
                    claim_reference_code
                 ) VALUES (
                    :id,
                    :company_id,
                    :accounting_period_id,
                    :claimant_id,
                    :claim_year,
                    :claim_month,
                    :period_start,
                    :period_end,
                    :claim_reference_code
                 )'
            );
            $lineStmt = \InterfaceDB::prepare(
                'INSERT INTO expense_claim_lines (expense_claim_id, line_number, expense_date, description, amount, nominal_account_id)
                 VALUES (:expense_claim_id, 1, :expense_date, :description, :amount, :nominal_account_id)'
            );

            for ($index = 0; $index < 1000; $index++) {
                $claimId = $baseClaimId + $index + 1;
                $date = (new DateTimeImmutable('2020-01-01'))->modify('+' . $index . ' months');
                $claimStmt->execute([
                    'id' => $claimId,
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['period_id'],
                    'claimant_id' => $largeClaimantId,
                    'claim_year' => (int)$date->format('Y'),
                    'claim_month' => (int)$date->format('n'),
                    'period_start' => $date->format('Y-m-01'),
                    'period_end' => $date->format('Y-m-t'),
                    'claim_reference_code' => 'EXP-LARGE-' . (string)$fixture['marker'] . '-' . str_pad((string)($index + 1), 4, '0', STR_PAD_LEFT),
                ]);
                $lineStmt->execute([
                    'expense_claim_id' => $claimId,
                    'expense_date' => $date->format('Y-m-05'),
                    'description' => 'Large series line ' . (string)($index + 1),
                    'amount' => (float)($index + 1),
                    'nominal_account_id' => (int)$fixture['line_nominal_id'],
                ]);
            }

            $instance->recalculateClaimSeries((int)$fixture['company_id'], $largeClaimantId);

            $harness->assertSame([0.00, 1.00, 0.00, 1.00], expenseClaimServiceClaimTotals($baseClaimId + 1));
            $harness->assertSame([124750.00, 500.00, 0.00, 125250.00], expenseClaimServiceClaimTotals($baseClaimId + 500));
            $harness->assertSame([499500.00, 1000.00, 0.00, 500500.00], expenseClaimServiceClaimTotals($baseClaimId + 1000));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'posts claim credit using current line total', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claim_lines (expense_claim_id, line_number, expense_date, description, amount, nominal_account_id)
                 VALUES (:expense_claim_id, 1, :expense_date, :description, :amount, :nominal_account_id)',
                [
                    'expense_claim_id' => (int)$fixture['claim_id'],
                    'expense_date' => '2026-05-05',
                    'description' => 'Materials',
                    'amount' => 94.99,
                    'nominal_account_id' => (int)$fixture['line_nominal_id'],
                ]
            );
            $harness->assertSame(0.0, (float)\InterfaceDB::fetchColumn(
                'SELECT claimed_amount FROM expense_claims WHERE id = :id',
                ['id' => (int)$fixture['claim_id']]
            ));

            $result = $instance->postClaim((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'default_expense_nominal_id' => (int)$fixture['expense_nominal_id'],
            ]);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $creditLine = \InterfaceDB::fetchOne(
                'SELECT jl.nominal_account_id, jl.credit, jl.line_description
                 FROM expense_claims ec
                 INNER JOIN journal_lines jl ON jl.journal_id = ec.posted_journal_id
                 WHERE ec.id = :claim_id
                   AND jl.credit > 0
                 LIMIT 1',
                ['claim_id' => (int)$fixture['claim_id']]
            );
            $harness->assertSame((int)$fixture['expense_nominal_id'], (int)($creditLine['nominal_account_id'] ?? 0));
            $harness->assertSame(94.99, (float)($creditLine['credit'] ?? 0));
            $harness->assertSame('Expense claim payable', (string)($creditLine['line_description'] ?? ''));
            $harness->assertSame(94.99, (float)\InterfaceDB::fetchColumn(
                'SELECT claimed_amount FROM expense_claims WHERE id = :id',
                ['id' => (int)$fixture['claim_id']]
            ));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'posts mixed expense and asset claim lines', function () use ($harness, $instance): void {
        if (!\InterfaceDB::tableExists('expense_claim_line_assets')) {
            $harness->skip('Expense claim line asset table is not available.');
        }

        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $expenseLineId = (int)($fixture['claim_id'] * 10 + 1);
            $assetLineId = (int)($fixture['claim_id'] * 10 + 2);
            foreach ([
                [$expenseLineId, 1, 'Materials', 94.99, (int)$fixture['line_nominal_id']],
                [$assetLineId, 2, 'Cordless drill', 180.00, null],
            ] as $line) {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO expense_claim_lines (id, expense_claim_id, line_number, expense_date, description, amount, nominal_account_id)
                     VALUES (:id, :expense_claim_id, :line_number, :expense_date, :description, :amount, :nominal_account_id)',
                    [
                        'id' => (int)$line[0],
                        'expense_claim_id' => (int)$fixture['claim_id'],
                        'line_number' => (int)$line[1],
                        'expense_date' => '2026-05-05',
                        'description' => (string)$line[2],
                        'amount' => (float)$line[3],
                        'nominal_account_id' => $line[4],
                    ]
                );
            }
            \InterfaceDB::prepareExecute(
                'UPDATE expense_claims
                 SET claimed_amount = 274.99,
                     carried_forward_amount = 274.99
                 WHERE id = :id',
                ['id' => (int)$fixture['claim_id']]
            );

            $typeResult = $instance->updateLineType((int)$fixture['company_id'], (int)$fixture['claim_id'], $assetLineId, 'asset');
            $harness->assertSame(true, (bool)($typeResult['success'] ?? false));
            $assetResult = $instance->saveLineAssetDetails((int)$fixture['company_id'], (int)$fixture['claim_id'], $assetLineId, [
                'asset_category' => 'tools_equipment',
                'asset_useful_life_years' => 3,
                'asset_depreciation_method' => 'straight_line',
                'asset_residual_value' => '0.00',
            ]);
            $harness->assertSame(true, (bool)($assetResult['success'] ?? false));
            $harness->assertSame('Cordless drill', (string)\InterfaceDB::fetchColumn(
                'SELECT description FROM expense_claim_line_assets WHERE expense_claim_line_id = :line_id',
                ['line_id' => $assetLineId]
            ));

            $result = $instance->postClaim((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'default_expense_nominal_id' => (int)$fixture['expense_nominal_id'],
            ]);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $journalId = (int)\InterfaceDB::fetchColumn(
                'SELECT posted_journal_id FROM expense_claims WHERE id = :id',
                ['id' => (int)$fixture['claim_id']]
            );
            $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM journal_lines WHERE journal_id = :journal_id AND nominal_account_id = :nominal_account_id AND debit = 94.99',
                ['journal_id' => $journalId, 'nominal_account_id' => (int)$fixture['line_nominal_id']]
            ));
            $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM journal_lines WHERE journal_id = :journal_id AND nominal_account_id = :nominal_account_id AND debit = 180.00',
                ['journal_id' => $journalId, 'nominal_account_id' => (int)$fixture['asset_cost_nominal_id']]
            ));
            $asset = \InterfaceDB::fetchOne(
                'SELECT id, linked_journal_id, linked_expense_claim_line_id, category, description, cost
                 FROM asset_register
                 WHERE linked_expense_claim_line_id = :line_id
                 LIMIT 1',
                ['line_id' => $assetLineId]
            );
            $harness->assertSame($journalId, (int)($asset['linked_journal_id'] ?? 0));
            $harness->assertSame($assetLineId, (int)($asset['linked_expense_claim_line_id'] ?? 0));
            $harness->assertSame('tools_equipment', (string)($asset['category'] ?? ''));
            $harness->assertSame('Cordless drill', (string)($asset['description'] ?? ''));
            $harness->assertSame(180.00, (float)($asset['cost'] ?? 0));
            $harness->assertSame((int)($asset['id'] ?? 0), (int)\InterfaceDB::fetchColumn(
                'SELECT generated_asset_id FROM expense_claim_line_assets WHERE expense_claim_line_id = :line_id',
                ['line_id' => $assetLineId]
            ));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'rejects claim creation outside valid date bounds', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $outsidePeriod = $instance->createClaim((int)$fixture['company_id'], [
                'claimant_id' => (int)$fixture['claimant_id'],
                'claim_year' => 2026,
                'claim_month' => 3,
            ]);

            $harness->assertSame(false, (bool)($outsidePeriod['success'] ?? true));
            $harness->assertTrue(in_array('Claim month must fall inside an accounting period.', (array)($outsidePeriod['errors'] ?? []), true));

            \InterfaceDB::prepareExecute(
                'UPDATE companies
                 SET incorporation_date = :incorporation_date
                 WHERE id = :id',
                [
                    'incorporation_date' => '2026-06-15',
                    'id' => (int)$fixture['company_id'],
                ]
            );

            $beforeFormation = $instance->createClaim((int)$fixture['company_id'], [
                'claimant_id' => (int)$fixture['claimant_id'],
                'claim_year' => 2026,
                'claim_month' => 5,
            ]);

            $harness->assertSame(false, (bool)($beforeFormation['success'] ?? true));
            $harness->assertTrue(in_array('Claim month cannot be earlier than the company incorporation date.', (array)($beforeFormation['errors'] ?? []), true));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'lists claims only for selected accounting period', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $previousPeriodId = (int)$fixture['period_id'] + 1;
            \InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $previousPeriodId,
                    'company_id' => (int)$fixture['company_id'],
                    'label' => 'Previous Fixture ' . (string)$fixture['marker'],
                    'period_start' => '2025-04-01',
                    'period_end' => '2026-03-31',
                ]
            );

            expenseClaimServiceInsertClaimWithId(
                array_merge($fixture, ['period_id' => $previousPeriodId]),
                (int)$fixture['claimant_id'] + 3,
                (int)$fixture['claimant_id'],
                2026,
                3,
                '2026-03-01',
                '2026-03-31'
            );

            $currentClaims = $instance->listClaims((int)$fixture['company_id'], [
                'heatmap_claimant_id' => (int)$fixture['claimant_id'],
                'accounting_period_id' => (int)$fixture['period_id'],
            ]);
            $previousClaims = $instance->listClaims((int)$fixture['company_id'], [
                'heatmap_claimant_id' => (int)$fixture['claimant_id'],
                'accounting_period_id' => $previousPeriodId,
            ]);
            $fallbackClaims = $instance->listClaims((int)$fixture['company_id'], [
                'heatmap_claimant_id' => (int)$fixture['claimant_id'],
                'accounting_period_start' => '2025-04-01',
                'accounting_period_end' => '2026-03-31',
            ]);

            $harness->assertSame(1, count($currentClaims));
            $harness->assertSame(1, count($previousClaims));
            $harness->assertSame(1, count($fallbackClaims));
            $harness->assertSame('EXP-' . (string)$fixture['marker'] . '-' . (string)$fixture['claimant_id'] . '-202605', (string)($currentClaims[0]['claim_reference_code'] ?? ''));
            $harness->assertSame('EXP-' . (string)$fixture['marker'] . '-' . (string)$fixture['claimant_id'] . '-202603', (string)($previousClaims[0]['claim_reference_code'] ?? ''));
            $harness->assertSame((string)($previousClaims[0]['claim_reference_code'] ?? ''), (string)($fallbackClaims[0]['claim_reference_code'] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'deletes only draft claims without repayment links', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claim_lines (expense_claim_id, line_number, expense_date, description, amount, nominal_account_id)
                 VALUES (:expense_claim_id, 1, :expense_date, :description, :amount, :nominal_account_id)',
                [
                    'expense_claim_id' => (int)$fixture['claim_id'],
                    'expense_date' => '2026-05-05',
                    'description' => 'Materials',
                    'amount' => 94.99,
                    'nominal_account_id' => (int)$fixture['line_nominal_id'],
                ]
            );

            $deleted = $instance->deleteClaim((int)$fixture['company_id'], (int)$fixture['claim_id']);
            $harness->assertSame(true, (bool)($deleted['success'] ?? false));
            $harness->assertSame(0, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claims WHERE id = :id',
                ['id' => (int)$fixture['claim_id']]
            ));
            $harness->assertSame(0, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claim_lines WHERE expense_claim_id = :claim_id',
                ['claim_id' => (int)$fixture['claim_id']]
            ));

            $linkedClaimId = expenseClaimServiceInsertClaim($fixture, 2026, 6, '2026-06-01', '2026-06-30');
            $transactionId = expenseClaimServiceInsertTransaction($fixture, -50.00, 'delete-linked-claim');
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claim_payment_links (expense_claim_id, transaction_id, linked_amount)
                 VALUES (:expense_claim_id, :transaction_id, :linked_amount)',
                [
                    'expense_claim_id' => $linkedClaimId,
                    'transaction_id' => $transactionId,
                    'linked_amount' => 50.00,
                ]
            );

            $linkedRejected = $instance->deleteClaim((int)$fixture['company_id'], $linkedClaimId);
            $harness->assertSame(false, (bool)($linkedRejected['success'] ?? true));
            $harness->assertSame('Remove repayment links before deleting this claim.', (string)(($linkedRejected['errors'] ?? [])[0] ?? ''));
            $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claims WHERE id = :id',
                ['id' => $linkedClaimId]
            ));

            $postedClaimId = expenseClaimServiceInsertClaim($fixture, 2026, 7, '2026-07-01', '2026-07-31');
            \InterfaceDB::prepareExecute(
                'UPDATE expense_claims SET status = :status WHERE id = :id',
                ['status' => 'posted', 'id' => $postedClaimId]
            );

            $postedRejected = $instance->deleteClaim((int)$fixture['company_id'], $postedClaimId);
            $harness->assertSame(false, (bool)($postedRejected['success'] ?? true));
            $harness->assertSame('Posted claims are locked.', (string)(($postedRejected['errors'] ?? [])[0] ?? ''));
            $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claims WHERE id = :id',
                ['id' => $postedClaimId]
            ));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'deletes only claimants without claims', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $unusedClaimantId = (int)$fixture['claimant_id'] + 900;

            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claimants (id, company_id, claimant_name, is_active)
                 VALUES (:id, :company_id, :claimant_name, 1)',
                [
                    'id' => $unusedClaimantId,
                    'company_id' => (int)$fixture['company_id'],
                    'claimant_name' => 'Unused Claimant ' . (string)$fixture['marker'],
                ]
            );

            $claimantsById = [];
            foreach ($instance->fetchClaimants((int)$fixture['company_id'], false) as $claimant) {
                $claimantsById[(int)($claimant['id'] ?? 0)] = $claimant;
            }

            $harness->assertSame(1, (int)($claimantsById[(int)$fixture['claimant_id']]['claim_count'] ?? -1));
            $harness->assertSame(0, (int)($claimantsById[$unusedClaimantId]['claim_count'] ?? -1));

            $rejected = $instance->deleteClaimant((int)$fixture['company_id'], (int)$fixture['claimant_id']);
            $harness->assertSame(false, (bool)($rejected['success'] ?? true));
            $harness->assertSame('Claimants with existing claims cannot be deleted.', (string)(($rejected['errors'] ?? [])[0] ?? ''));
            $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claimants WHERE id = :id',
                ['id' => (int)$fixture['claimant_id']]
            ));

            $deleted = $instance->deleteClaimant((int)$fixture['company_id'], $unusedClaimantId);
            $harness->assertSame(true, (bool)($deleted['success'] ?? false));
            $harness->assertSame(0, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM expense_claimants WHERE id = :id',
                ['id' => $unusedClaimantId]
            ));
            $harness->assertSame('Claimant deleted.', (string)(($deleted['messages'] ?? [])[0] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'aggregates selected period expense statistics', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $secondClaimantId = (int)$fixture['claimant_id'] + 100;
            $previousPeriodId = (int)$fixture['period_id'] + 100;
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claimants (id, company_id, claimant_name, is_active)
                 VALUES (:id, :company_id, :claimant_name, 1)',
                [
                    'id' => $secondClaimantId,
                    'company_id' => (int)$fixture['company_id'],
                    'claimant_name' => 'Second Claimant',
                ]
            );
            \InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $previousPeriodId,
                    'company_id' => (int)$fixture['company_id'],
                    'label' => 'Previous Fixture ' . (string)$fixture['marker'],
                    'period_start' => '2025-04-01',
                    'period_end' => '2026-03-31',
                ]
            );

            expenseClaimServiceInsertClaimWithId($fixture, (int)$fixture['claim_id'] + 30, (int)$fixture['claimant_id'], 2026, 3, '2026-03-01', '2026-03-31', $previousPeriodId);
            expenseClaimServiceInsertClaimWithId($fixture, (int)$fixture['claim_id'] + 10, (int)$fixture['claimant_id'], 2026, 6, '2026-06-01', '2026-06-30');
            expenseClaimServiceInsertClaimWithId($fixture, (int)$fixture['claim_id'] + 20, $secondClaimantId, 2026, 5, '2026-05-01', '2026-05-31');

            expenseClaimServiceInsertStatisticsLine((int)$fixture['claim_id'] + 30, 1, '2026-03-05', 'Previous period materials', 500.00, (int)$fixture['line_nominal_id'], '');
            expenseClaimServiceInsertStatisticsLine((int)$fixture['claim_id'], 1, '2026-05-05', 'Materials', 100.00, (int)$fixture['line_nominal_id'], 'receipt-1');
            expenseClaimServiceInsertStatisticsLine((int)$fixture['claim_id'], 2, '2026-05-06', 'Unassigned', 50.00, null, '');
            expenseClaimServiceInsertStatisticsLine((int)$fixture['claim_id'] + 10, 1, '2026-06-07', 'Tools', 25.00, (int)$fixture['line_nominal_id'], 'receipt-2');
            expenseClaimServiceInsertStatisticsLine((int)$fixture['claim_id'] + 20, 1, '2026-05-08', 'Fuel', 80.00, (int)$fixture['line_nominal_id'], '');

            $transactionId = expenseClaimServiceInsertTransaction($fixture, -40.00, 'stats-primary');
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claim_payment_links (expense_claim_id, transaction_id, linked_amount)
                 VALUES (:expense_claim_id, :transaction_id, :linked_amount)',
                [
                    'expense_claim_id' => (int)$fixture['claim_id'],
                    'transaction_id' => $transactionId,
                    'linked_amount' => 40.00,
                ]
            );
            $secondTransactionId = expenseClaimServiceInsertTransaction($fixture, -20.00, 'stats-second');
            \InterfaceDB::prepareExecute(
                'INSERT INTO expense_claim_payment_links (expense_claim_id, transaction_id, linked_amount)
                 VALUES (:expense_claim_id, :transaction_id, :linked_amount)',
                [
                    'expense_claim_id' => (int)$fixture['claim_id'] + 20,
                    'transaction_id' => $secondTransactionId,
                    'linked_amount' => 20.00,
                ]
            );
            \InterfaceDB::prepareExecute(
                'UPDATE expense_claims SET status = :status WHERE id = :id',
                [
                    'status' => 'posted',
                    'id' => (int)$fixture['claim_id'] + 10,
                ]
            );

            $instance->recalculateCompanyClaimSeries((int)$fixture['company_id']);

            $statistics = $instance->fetchStatistics((int)$fixture['company_id'], [
                'accounting_period_id' => (int)$fixture['period_id'],
                'accounting_period_start' => '2026-04-01',
                'accounting_period_end' => '2027-03-31',
            ]);

            $claimants = (array)($statistics['claimants'] ?? []);
            $harness->assertCount(2, $claimants);
            $primary = expenseClaimServiceFindStatisticsRow($claimants, 'claimant_id', (int)$fixture['claimant_id']);
            $harness->assertSame(2, (int)($primary['claim_count'] ?? 0));
            $harness->assertSame(3, (int)($primary['item_count'] ?? 0));
            $harness->assertSame(1, (int)($primary['unassigned_item_count'] ?? 0));
            $harness->assertSame(500.00, (float)($primary['brought_forward'] ?? 0));
            $harness->assertSame(175.00, (float)($primary['claimed_total'] ?? 0));
            $harness->assertSame(40.00, (float)($primary['payments_made'] ?? 0));
            $harness->assertSame(635.00, (float)($primary['carried_forward'] ?? 0));

            $unassignedEntries = (array)($statistics['unassigned_entries'] ?? []);
            $harness->assertCount(1, $unassignedEntries);
            $harness->assertSame((int)$fixture['claim_id'], (int)(($unassignedEntries[0] ?? [])['claim_id'] ?? 0));
            $harness->assertSame('May 2026', (string)(($unassignedEntries[0] ?? [])['month'] ?? ''));
            $harness->assertSame('2026-05-06', (string)(($unassignedEntries[0] ?? [])['expense_date'] ?? ''));
            $harness->assertSame(50.00, (float)(($unassignedEntries[0] ?? [])['amount'] ?? 0));

            $nominals = (array)($statistics['nominals'] ?? []);
            $unassigned = expenseClaimServiceFindStatisticsRow($nominals, 'name', 'Unassigned');
            $materials = expenseClaimServiceFindStatisticsRow($nominals, 'nominal_account_id', (int)$fixture['line_nominal_id']);
            $harness->assertSame(50.00, (float)($unassigned['claimed_total'] ?? 0));
            $harness->assertSame(205.00, (float)($materials['claimed_total'] ?? 0));

            $trend = (array)($statistics['monthly_trend'] ?? []);
            $harness->assertCount(12, $trend);
            $may = expenseClaimServiceFindStatisticsRow($trend, 'period', '2026-05');
            $june = expenseClaimServiceFindStatisticsRow($trend, 'period', '2026-06');
            $harness->assertSame(230.00, (float)($may['claimed_total'] ?? 0));
            $harness->assertSame(25.00, (float)($june['claimed_total'] ?? 0));

            $health = (array)($statistics['health_checks'] ?? []);
            $harness->assertSame(2, (int)(($health['draft'] ?? [])['claim_count'] ?? 0));
            $harness->assertSame(230.00, (float)(($health['draft'] ?? [])['claimed_total'] ?? 0));
            $harness->assertSame(1, (int)(($health['posted'] ?? [])['claim_count'] ?? 0));
            $harness->assertSame(25.00, (float)(($health['posted'] ?? [])['claimed_total'] ?? 0));
            $harness->assertSame(2, (int)(($health['missing_receipts'] ?? [])['count'] ?? 0));
            $harness->assertSame(130.00, (float)(($health['missing_receipts'] ?? [])['value'] ?? 0));
            $harness->assertSame(1, (int)(($health['missing_nominals'] ?? [])['count'] ?? 0));
            $harness->assertSame(50.00, (float)(($health['missing_nominals'] ?? [])['value'] ?? 0));
            $harness->assertSame('Fixture Claimant ' . (string)$fixture['marker'], (string)(($health['oldest_outstanding_claim'] ?? [])['claimant_name'] ?? ''));
            $harness->assertSame('Fixture Claimant ' . (string)$fixture['marker'], (string)(($health['largest_outstanding_claimant'] ?? [])['claimant_name'] ?? ''));
            $harness->assertSame(110.00, (float)(($health['oldest_outstanding_claim'] ?? [])['carried_forward'] ?? 0));
            $harness->assertSame(135.00, (float)(($health['largest_outstanding_claimant'] ?? [])['carried_forward'] ?? 0));
        });
    });
});

function expenseClaimServiceParseBulkLines(\eel_accounts\Service\ExpenseClaimService $service, string $source, string $dateFormat): array
{
    $method = new ReflectionMethod($service, 'parseBulkLineText');
    $method->setAccessible(true);

    return (array)$method->invoke($service, $source, $dateFormat);
}

function expenseClaimServiceHistoricalPaste(): string
{
    return "Claimant\tAlex Example\n"
        . "Year\t2022\tMonth\tOctober\n"
        . "DATE\tDESCRIPTION\tAMOUNT CLAIMED\n"
        . "5/10/2022\tElectricFix, Wall Chaser\t£94.99\n"
        . "6/10/2022\tVirgin Media Broadband Connection\t£47.60\n"
        . "8/10/2022\tTrade Skills 4 U Limited, Course Materials\t£140.00\n"
        . "10/10/2022\tRS Components, VDE Plyers\t£116.24\n"
        . "11/10/2022\tKnaphill Print Co Ltd, Business Cards\t£54.00\n"
        . "14/10/2022\tCEF, Cable and Labels\t£62.09\n"
        . "17/10/2022\tElectricFix, Training Equipment\t£74.95\n"
        . "17/10/2022\tExample Trade Supplier, Training Equipment\t£357.42\n"
        . "22/10/2022\tWickes, USB LED Light\t£16.65\n"
        . "22/10/2022\tCEF, Heat Gun & Battery, Wiring Regulations\t£205.68\n"
        . "23/10/2022\tCEF, Area Light\t£71.94\n"
        . "23/10/2022\tFuel\t£18.69\n"
        . "28/10/2022\tWickes, Plywood for Training Equipment\t£12.15\n"
        . "-\t-\t-\n"
        . "Total Amount Claimed (sum of above lines)\tB\t£1,272.40\n"
        . "Claimant's Signature\t\n"
        . "----- OFFICE USE ONLY BELOW THIS LINE -----\n"
        . "A\t(Unpaid) Balance outstanding to claimant brought forwards from previous period claim form\tNIL (First Claim)\n"
        . "B\tAmount claimed during month\t£1,272.40\n"
        . "C\tPayments made to claimant during this month\t£0.00\n"
        . "D\tBalance outstanding to claimant\t£1,272.40";
}

function expenseClaimServiceWithFixture(callable $callback): void
{
    if (!\InterfaceDB::tableExists('expense_claims') || !\InterfaceDB::tableExists('transactions')) {
        throw new RuntimeException('Expense claim database tables are not available.');
    }

    \InterfaceDB::beginTransaction();

    try {
        $marker = (string)random_int(1000, 9999);
        $companyId = (int)('81' . $marker);
        $periodId = (int)('82' . $marker);
        $claimantId = (int)('83' . $marker);
        $expenseNominalId = (int)('84' . $marker);
        $lineNominalId = (int)('85' . $marker);
        $bankNominalId = (int)('86' . $marker);
        $bankAccountId = (int)('87' . $marker);
        $uploadId = (int)('88' . $marker);

        \InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, is_active)
             VALUES (:id, :company_name, :company_number, 1)',
            [
                'id' => $companyId,
                'company_name' => 'Expense Claim Fixture ' . $marker,
                'company_number' => 'EC' . $marker,
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :period_start, :period_end)',
            [
                'id' => $periodId,
                'company_id' => $companyId,
                'label' => 'Fixture ' . $marker,
                'period_start' => '2026-04-01',
                'period_end' => '2027-03-31',
            ]
        );

        foreach ([
            [$expenseNominalId, 'EXP' . $marker, 'Expense Claims Payable', 'liability'],
            [$lineNominalId, 'MAT' . $marker, 'Materials', 'expense'],
            [$bankNominalId, 'BNK' . $marker, 'Bank', 'asset'],
        ] as $nominal) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
                 VALUES (:id, :code, :name, :account_type, :tax_treatment, 1, 100)',
                [
                    'id' => (int)$nominal[0],
                    'code' => (string)$nominal[1],
                    'name' => (string)$nominal[2],
                    'account_type' => (string)$nominal[3],
                    'tax_treatment' => 'allowable',
                ]
            );
        }
        $assetCostNominalId = expenseClaimServiceEnsureNominalByCode('1300', 'Tools and Equipment', 'asset');
        expenseClaimServiceEnsureNominalByCode('1330', 'Accumulated Depreciation - Tools', 'asset');

        \InterfaceDB::prepareExecute(
            'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
             VALUES (:id, :company_id, :account_name, :account_type, :nominal_account_id, 1)',
            [
                'id' => $bankAccountId,
                'company_id' => $companyId,
                'account_name' => 'Fixture Bank ' . $marker,
                'account_type' => 'bank',
                'nominal_account_id' => $bankNominalId,
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO expense_claimants (id, company_id, claimant_name, is_active)
             VALUES (:id, :company_id, :claimant_name, 1)',
            [
                'id' => $claimantId,
                'company_id' => $companyId,
                'claimant_name' => 'Fixture Claimant ' . $marker,
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO statement_uploads (
                id,
                company_id,
                accounting_period_id,
                account_id,
                statement_month,
                original_filename,
                stored_filename,
                file_sha256
             ) VALUES (
                :id,
                :company_id,
                :accounting_period_id,
                :account_id,
                :statement_month,
                :original_filename,
                :stored_filename,
                :file_sha256
             )',
            [
                'id' => $uploadId,
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'account_id' => $bankAccountId,
                'statement_month' => '2026-05-01',
                'original_filename' => 'fixture.csv',
                'stored_filename' => 'fixture-' . $marker . '.csv',
                'file_sha256' => hash('sha256', 'fixture-upload-' . $marker),
            ]
        );

        $claimId = expenseClaimServiceInsertClaim([
            'company_id' => $companyId,
            'period_id' => $periodId,
            'claimant_id' => $claimantId,
            'marker' => $marker,
        ], 2026, 5, '2026-05-01', '2026-05-31');

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'period_id' => $periodId,
            'claimant_id' => $claimantId,
            'claim_id' => $claimId,
            'expense_nominal_id' => $expenseNominalId,
            'line_nominal_id' => $lineNominalId,
            'asset_cost_nominal_id' => $assetCostNominalId,
            'bank_nominal_id' => $bankNominalId,
            'bank_account_id' => $bankAccountId,
            'upload_id' => $uploadId,
        ]);
    } finally {
        if (\InterfaceDB::inTransaction()) {
            \InterfaceDB::rollBack();
        }
    }
}

function expenseClaimServiceEnsureNominalByCode(string $code, string $name, string $accountType): int
{
    $existingId = (int)\InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
    if ($existingId > 0) {
        return $existingId;
    }

    \InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, 100)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => 'capital',
        ]
    );

    return (int)\InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}

function expenseClaimServiceInsertClaim(array $fixture, int $year, int $month, string $periodStart, string $periodEnd): int
{
    $claimId = (int)((int)$fixture['claimant_id'] + $month);
    expenseClaimServiceInsertClaimWithId($fixture, $claimId, (int)$fixture['claimant_id'], $year, $month, $periodStart, $periodEnd);

    return $claimId;
}

function expenseClaimServiceInsertClaimWithId(array $fixture, int $claimId, int $claimantId, int $year, int $month, string $periodStart, string $periodEnd, ?int $accountingPeriodId = null): void
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO expense_claims (
            id,
            company_id,
            accounting_period_id,
            claimant_id,
            claim_year,
            claim_month,
            period_start,
            period_end,
            claim_reference_code
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :claimant_id,
            :claim_year,
            :claim_month,
            :period_start,
            :period_end,
            :claim_reference_code
         )',
        [
            'id' => $claimId,
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => $accountingPeriodId ?? (int)$fixture['period_id'],
            'claimant_id' => $claimantId,
            'claim_year' => $year,
            'claim_month' => $month,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'claim_reference_code' => 'EXP-' . (string)$fixture['marker'] . '-' . (string)$claimantId . '-' . sprintf('%04d%02d', $year, $month),
        ]
    );
}

function expenseClaimServiceInsertLine(array $fixture, int $claimId, int $lineNumber, string $expenseDate, string $description, float $amount): void
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO expense_claim_lines (expense_claim_id, line_number, expense_date, description, amount, nominal_account_id)
         VALUES (:expense_claim_id, :line_number, :expense_date, :description, :amount, :nominal_account_id)',
        [
            'expense_claim_id' => $claimId,
            'line_number' => $lineNumber,
            'expense_date' => $expenseDate,
            'description' => $description,
            'amount' => $amount,
            'nominal_account_id' => (int)$fixture['line_nominal_id'],
        ]
    );
}

function expenseClaimServiceInsertStatisticsLine(int $claimId, int $lineNumber, string $expenseDate, string $description, float $amount, ?int $nominalAccountId, string $receiptReference): void
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO expense_claim_lines (expense_claim_id, line_number, expense_date, description, amount, nominal_account_id, receipt_reference)
         VALUES (:expense_claim_id, :line_number, :expense_date, :description, :amount, :nominal_account_id, :receipt_reference)',
        [
            'expense_claim_id' => $claimId,
            'line_number' => $lineNumber,
            'expense_date' => $expenseDate,
            'description' => $description,
            'amount' => $amount,
            'nominal_account_id' => $nominalAccountId,
            'receipt_reference' => $receiptReference !== '' ? $receiptReference : null,
        ]
    );
}

function expenseClaimServiceFindStatisticsRow(array $rows, string $key, mixed $expected): array
{
    foreach ($rows as $row) {
        if (is_array($row) && ($row[$key] ?? null) === $expected) {
            return $row;
        }
    }

    return [];
}

function expenseClaimServiceClaimTotals(int $claimId): array
{
    $row = \InterfaceDB::fetchOne(
        'SELECT brought_forward_amount,
                claimed_amount,
                payments_amount,
                carried_forward_amount
         FROM expense_claims
         WHERE id = :id
         LIMIT 1',
        ['id' => $claimId]
    );

    return [
        round((float)($row['brought_forward_amount'] ?? 0), 2),
        round((float)($row['claimed_amount'] ?? 0), 2),
        round((float)($row['payments_amount'] ?? 0), 2),
        round((float)($row['carried_forward_amount'] ?? 0), 2),
    ];
}

function expenseClaimServiceInsertTransaction(array $fixture, float $amount, string $suffix): int
{
    $transactionId = (int)((int)$fixture['upload_id'] + abs(crc32($suffix)) % 1000 + 1);
    \InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id,
            company_id,
            accounting_period_id,
            statement_upload_id,
            account_id,
            txn_date,
            description,
            amount,
            currency,
            source_type,
            dedupe_hash
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :account_id,
            :txn_date,
            :description,
            :amount,
            :currency,
            :source_type,
            :dedupe_hash
         )',
        [
            'id' => $transactionId,
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['period_id'],
            'statement_upload_id' => (int)$fixture['upload_id'],
            'account_id' => (int)$fixture['bank_account_id'],
            'txn_date' => '2026-05-31',
            'description' => 'Expense repayment ' . $suffix,
            'amount' => $amount,
            'currency' => 'GBP',
            'source_type' => 'statement_csv',
            'dedupe_hash' => hash('sha256', (string)$fixture['marker'] . '-' . $suffix),
        ]
    );

    return $transactionId;
}
