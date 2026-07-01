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

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'links repayment for the full transaction amount using default expense nominal', function () use ($harness, $instance): void {
        expenseClaimServiceWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $transactionId = expenseClaimServiceInsertTransaction($fixture, -123.45, 'repayment-full');

            $result = $instance->linkPayment((int)$fixture['company_id'], (int)$fixture['claim_id'], [
                'transaction_id' => $transactionId,
                'default_expense_nominal_id' => (int)$fixture['expense_nominal_id'],
                'default_bank_nominal_id' => (int)$fixture['bank_nominal_id'],
            ]);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
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

    $harness->check(\eel_accounts\Service\ExpenseClaimService::class, 'posts claim credit to expense claims payable nominal', function () use ($harness, $instance): void {
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
            \InterfaceDB::prepareExecute(
                'UPDATE expense_claims
                 SET claimed_amount = 94.99,
                     carried_forward_amount = 94.99
                 WHERE id = :id',
                ['id' => (int)$fixture['claim_id']]
            );

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
                'asset_description' => 'Cordless drill',
                'asset_useful_life_years' => 3,
                'asset_depreciation_method' => 'straight_line',
                'asset_residual_value' => '0.00',
            ]);
            $harness->assertSame(true, (bool)($assetResult['success'] ?? false));

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
                'SELECT id, linked_journal_id, linked_expense_claim_line_id, category, cost
                 FROM asset_register
                 WHERE linked_expense_claim_line_id = :line_id
                 LIMIT 1',
                ['line_id' => $assetLineId]
            );
            $harness->assertSame($journalId, (int)($asset['linked_journal_id'] ?? 0));
            $harness->assertSame($assetLineId, (int)($asset['linked_expense_claim_line_id'] ?? 0));
            $harness->assertSame('tools_equipment', (string)($asset['category'] ?? ''));
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
            'accounting_period_id' => (int)$fixture['period_id'],
            'claimant_id' => (int)$fixture['claimant_id'],
            'claim_year' => $year,
            'claim_month' => $month,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'claim_reference_code' => 'EXP-' . (string)$fixture['marker'] . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT),
        ]
    );

    return $claimId;
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
