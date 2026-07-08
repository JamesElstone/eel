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

$harness->run(\eel_accounts\Service\EmptyMonthConfirmationService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\EmptyMonthConfirmationService $service
): void {
    $harness->check(\eel_accounts\Service\EmptyMonthConfirmationService::class, 'detects eligible first incorporation month and supports revoke', static function () use ($harness, $service): void {
        empty_month_confirmation_with_fixture($harness, ['statement_opening_balance' => 0.00], static function (array $fixture) use ($harness, $service): void {
            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $month = (array)($context['months'][0] ?? []);

            $harness->assertSame(true, (bool)($context['available'] ?? false));
            $harness->assertSame('2022-09-01', (string)($month['month_start'] ?? ''));
            $harness->assertSame('initial_opening_month', (string)($month['confirmation_basis'] ?? ''));
            $harness->assertSame(true, (bool)($month['can_confirm'] ?? false));
            $harness->assertSame(0, (int)($month['evidence']['activity_counts']['transactions'] ?? -1));
            $harness->assertSame(0, (int)($month['evidence']['activity_counts']['raw_rows'] ?? -1));
            $harness->assertSame('No company financial activity existed in this month.', (string)($month['evidence']['assertion'] ?? ''));

            $confirm = $service->confirmMonth((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2022-09-01', 'Bank account opened in October.', 'unit_test');
            $harness->assertSame(true, (bool)($confirm['success'] ?? false));
            $harness->assertSame(true, isset($service->activeConfirmationMap((int)$fixture['company_id'], (int)$fixture['accounting_period_id'])['2022-09-01']));

            $confirmedContext = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame('confirmed', (string)($confirmedContext['months'][0]['status'] ?? ''));

            $revoke = $service->revokeMonth((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2022-09-01', 'unit_test');
            $harness->assertSame(true, (bool)($revoke['success'] ?? false));
            $harness->assertSame(false, isset($service->activeConfirmationMap((int)$fixture['company_id'], (int)$fixture['accounting_period_id'])['2022-09-01']));
        });
    });

    $harness->check(\eel_accounts\Service\EmptyMonthConfirmationService::class, 'confirms ordinary in-period empty months', static function () use ($harness, $service): void {
        empty_month_confirmation_with_fixture($harness, ['statement_opening_balance' => 0.00], static function (array $fixture) use ($harness, $service): void {
            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $months = empty_month_confirmation_month_map((array)($context['months'] ?? []));

            $harness->assertSame(true, isset($months['2022-11-01']));
            $harness->assertSame('no_activity_month', (string)($months['2022-11-01']['confirmation_basis'] ?? ''));
            $harness->assertSame(true, (bool)($months['2022-11-01']['can_confirm'] ?? false));

            $result = $service->confirmMonth((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2022-11-01', 'No November transactions.', 'unit_test');
            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(true, isset($service->activeConfirmationMap((int)$fixture['company_id'], (int)$fixture['accounting_period_id'])['2022-11-01']));
        });
    });

    $harness->check(\eel_accounts\Service\EmptyMonthConfirmationService::class, 'rejects months with staged raw rows and outside-period months', static function () use ($harness, $service): void {
        empty_month_confirmation_with_fixture($harness, ['statement_opening_balance' => 0.00], static function (array $fixture) use ($harness, $service): void {
            $withRows = $service->confirmMonth((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2022-10-01');
            $outside = $service->confirmMonth((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2024-01-01');

            $harness->assertSame(false, (bool)($withRows['success'] ?? true));
            $harness->assertSame(true, str_contains((string)($withRows['errors'][0] ?? ''), 'Source activity'));
            $harness->assertSame(false, (bool)($outside['success'] ?? true));
            $harness->assertSame(true, str_contains((string)($outside['errors'][0] ?? ''), 'outside the selected accounting period'));
        });
    });

    $harness->check(\eel_accounts\Service\EmptyMonthConfirmationService::class, 'requires later zero opening balance evidence', static function () use ($harness, $service): void {
        empty_month_confirmation_with_fixture($harness, ['statement_opening_balance' => 12.34], static function (array $fixture) use ($harness, $service): void {
            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $month = (array)($context['months'][0] ?? []);

            $harness->assertSame(false, (bool)($month['can_confirm'] ?? true));
            $harness->assertSame(true, str_contains((string)($month['reason'] ?? ''), 'does not open at 0.00'));
        });

        empty_month_confirmation_with_fixture($harness, ['with_statement_row' => false], static function (array $fixture) use ($harness, $service): void {
            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $month = (array)($context['months'][0] ?? []);

            $harness->assertSame(false, (bool)($month['can_confirm'] ?? true));
            $harness->assertSame(true, str_contains((string)($month['reason'] ?? ''), 'No later statement row'));
        });
    });

    $harness->check(\eel_accounts\Service\EmptyMonthConfirmationService::class, 'supersedes old confirmation when source activity appears', static function () use ($harness, $service): void {
        empty_month_confirmation_with_fixture($harness, ['statement_opening_balance' => 0.00], static function (array $fixture) use ($harness, $service): void {
            $confirm = $service->confirmMonth((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2022-11-01');
            $harness->assertSame(true, (bool)($confirm['success'] ?? false));

            InterfaceDB::prepareExecute(
                'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                 VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'source_type' => 'manual',
                    'source_ref' => 'empty-month-confirmation-test:' . (string)$fixture['marker'],
                    'journal_date' => '2022-11-15',
                    'description' => 'Fixture November activity',
                ]
            );

            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $months = empty_month_confirmation_month_map((array)($context['months'] ?? []));
            $harness->assertSame('superseded', (string)($months['2022-11-01']['status'] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\EmptyMonthConfirmationService::class, 'only checks initial opening month in earliest accounting period', static function () use ($harness, $service): void {
        empty_month_confirmation_with_fixture($harness, ['statement_opening_balance' => 0.00], static function (array $fixture) use ($harness, $service): void {
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'label' => 'EMC second ' . (string)$fixture['marker'],
                    'period_start' => '2023-09-01',
                    'period_end' => '2024-08-31',
                ]
            );
            $secondPeriodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'label' => 'EMC second ' . (string)$fixture['marker'],
                ]
            );

            $context = $service->fetchContext((int)$fixture['company_id'], $secondPeriodId);
            $months = empty_month_confirmation_month_map((array)($context['months'] ?? []));

            $harness->assertSame(false, isset($months['2022-09-01']));
            $harness->assertSame(true, isset($months['2023-09-01']));
            $harness->assertSame('no_activity_month', (string)($months['2023-09-01']['confirmation_basis'] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\EmptyMonthConfirmationService::class, 'detects upload impact without revoking and can revoke affected months', static function () use ($harness, $service): void {
        empty_month_confirmation_with_fixture($harness, ['statement_opening_balance' => 0.00], static function (array $fixture) use ($harness, $service): void {
            $confirm = $service->confirmMonth((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2022-11-01');
            $harness->assertSame(true, (bool)($confirm['success'] ?? false));

            $uploadId = empty_month_confirmation_insert_upload_row(
                (string)$fixture['marker'],
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                (int)$fixture['account_id'],
                '2022-11-10'
            );
            $impact = $service->activeConfirmationsAffectedByUpload((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], $uploadId);

            $harness->assertSame('2022-11-01', (string)($impact[0]['month_start'] ?? ''));
            $harness->assertSame(true, isset($service->activeConfirmationMap((int)$fixture['company_id'], (int)$fixture['accounting_period_id'])['2022-11-01']));

            $revoke = $service->revokeActiveConfirmationsForMonths((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], ['2022-11-01'], 'unit_test_upload');
            $harness->assertSame(1, (int)($revoke['revoked_count'] ?? 0));
            $harness->assertSame(false, isset($service->activeConfirmationMap((int)$fixture['company_id'], (int)$fixture['accounting_period_id'])['2022-11-01']));
        });
    });
});

function empty_month_confirmation_month_map(array $months): array
{
    $map = [];
    foreach ($months as $month) {
        if (!is_array($month)) {
            continue;
        }

        $monthStart = (string)($month['month_start'] ?? '');
        if ($monthStart !== '') {
            $map[$monthStart] = $month;
        }
    }

    return $map;
}

function empty_month_confirmation_with_fixture(GeneratedServiceClassTestHarness $harness, array $options, callable $callback): void
{
    foreach (['companies', 'accounting_periods', 'company_accounts', 'statement_uploads', 'statement_import_rows', 'transactions', 'journals', 'accounting_period_month_confirmations'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available on the default InterfaceDB connection.');
        }
    }

    InterfaceDB::beginTransaction();
    try {
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number, incorporation_date)
             VALUES (:company_name, :company_number, :incorporation_date)',
            [
                'company_name' => 'Empty Month Fixture Limited',
                'company_number' => 'EMC' . $marker,
                'incorporation_date' => '2022-09-14',
            ]
        );
        $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'EMC' . $marker]);

        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'EMC ' . $marker,
                'period_start' => '2022-09-01',
                'period_end' => '2023-08-31',
            ]
        );
        $periodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'EMC ' . $marker]
        );

        InterfaceDB::prepareExecute(
            'INSERT INTO company_accounts (company_id, account_name, account_type, is_active)
             VALUES (:company_id, :account_name, :account_type, 1)',
            [
                'company_id' => $companyId,
                'account_name' => 'Fixture Current Account ' . $marker,
                'account_type' => 'bank',
            ]
        );
        $accountId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_accounts WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId]
        );

        if (($options['with_statement_row'] ?? true) !== false) {
            empty_month_confirmation_insert_later_statement($marker, $companyId, $periodId, $accountId, (float)($options['statement_opening_balance'] ?? 0.00));
        }

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'account_id' => $accountId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function empty_month_confirmation_insert_later_statement(string $marker, int $companyId, int $periodId, int $accountId, float $openingBalance): void
{
    $amount = 27.50;
    $balance = round($openingBalance + $amount, 2);

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id,
            accounting_period_id,
            account_id,
            source_type,
            workflow_status,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256,
            date_range_start,
            date_range_end,
            source_headers_json,
            rows_parsed,
            rows_ready_to_import
        ) VALUES (
            :company_id,
            :accounting_period_id,
            :account_id,
            :source_type,
            :workflow_status,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256,
            :date_range_start,
            :date_range_end,
            :source_headers_json,
            1,
            1
        )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'account_id' => $accountId,
            'source_type' => 'bank_account',
            'workflow_status' => 'staged',
            'statement_month' => '2022-10-01',
            'original_filename' => 'empty-month-' . $marker . '.csv',
            'stored_filename' => 'empty-month-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'empty-month-' . $marker),
            'date_range_start' => '2022-10-01',
            'date_range_end' => '2022-10-31',
            'source_headers_json' => '[]',
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_import_rows (
            upload_id,
            row_number,
            raw_json,
            source_account,
            source_created,
            source_description,
            source_amount,
            source_balance,
            source_currency,
            accounting_period_id,
            chosen_txn_date,
            chosen_date_source,
            normalised_description,
            normalised_amount,
            normalised_balance,
            normalised_currency,
            row_hash,
            validation_status
        ) VALUES (
            :upload_id,
            1,
            :raw_json,
            :source_account,
            :source_created,
            :source_description,
            :source_amount,
            :source_balance,
            :source_currency,
            :accounting_period_id,
            :chosen_txn_date,
            :chosen_date_source,
            :normalised_description,
            :normalised_amount,
            :normalised_balance,
            :normalised_currency,
            :row_hash,
            :validation_status
        )',
        [
            'upload_id' => $uploadId,
            'raw_json' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
            'source_account' => 'Fixture Current Account',
            'source_created' => '2022-10-05',
            'source_description' => 'First later transaction',
            'source_amount' => number_format($amount, 2, '.', ''),
            'source_balance' => number_format($balance, 2, '.', ''),
            'source_currency' => 'GBP',
            'accounting_period_id' => $periodId,
            'chosen_txn_date' => '2022-10-05',
            'chosen_date_source' => 'created',
            'normalised_description' => 'First later transaction',
            'normalised_amount' => number_format($amount, 2, '.', ''),
            'normalised_balance' => number_format($balance, 2, '.', ''),
            'normalised_currency' => 'GBP',
            'row_hash' => hash('sha256', 'empty-month-row-' . $marker),
            'validation_status' => 'valid',
        ]
    );
}

function empty_month_confirmation_insert_upload_row(string $marker, int $companyId, int $periodId, int $accountId, string $txnDate): int
{
    $monthStart = (new DateTimeImmutable($txnDate))->format('Y-m-01');
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id,
            accounting_period_id,
            account_id,
            source_type,
            workflow_status,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256,
            date_range_start,
            date_range_end,
            source_headers_json,
            rows_parsed,
            rows_ready_to_import
        ) VALUES (
            :company_id,
            :accounting_period_id,
            :account_id,
            :source_type,
            :workflow_status,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256,
            :date_range_start,
            :date_range_end,
            :source_headers_json,
            1,
            1
        )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'account_id' => $accountId,
            'source_type' => 'bank_account',
            'workflow_status' => 'staged',
            'statement_month' => $monthStart,
            'original_filename' => 'empty-month-impact-' . $marker . '.csv',
            'stored_filename' => 'empty-month-impact-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'empty-month-impact-' . $marker . $txnDate),
            'date_range_start' => $txnDate,
            'date_range_end' => $txnDate,
            'source_headers_json' => '[]',
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_import_rows (
            upload_id,
            row_number,
            raw_json,
            source_account,
            source_created,
            source_description,
            source_amount,
            source_balance,
            source_currency,
            accounting_period_id,
            chosen_txn_date,
            chosen_date_source,
            normalised_description,
            normalised_amount,
            normalised_balance,
            normalised_currency,
            row_hash,
            validation_status,
            is_duplicate_within_upload,
            is_duplicate_existing
        ) VALUES (
            :upload_id,
            1,
            :raw_json,
            :source_account,
            :source_created,
            :source_description,
            :source_amount,
            :source_balance,
            :source_currency,
            :accounting_period_id,
            :chosen_txn_date,
            :chosen_date_source,
            :normalised_description,
            :normalised_amount,
            :normalised_balance,
            :normalised_currency,
            :row_hash,
            :validation_status,
            0,
            0
        )',
        [
            'upload_id' => $uploadId,
            'raw_json' => json_encode(['fixture' => true, 'impact' => true], JSON_THROW_ON_ERROR),
            'source_account' => 'Fixture Current Account',
            'source_created' => $txnDate,
            'source_description' => 'Transaction after empty-month approval',
            'source_amount' => '9.99',
            'source_balance' => '9.99',
            'source_currency' => 'GBP',
            'accounting_period_id' => $periodId,
            'chosen_txn_date' => $txnDate,
            'chosen_date_source' => 'created',
            'normalised_description' => 'Transaction after empty-month approval',
            'normalised_amount' => '9.99',
            'normalised_balance' => '9.99',
            'normalised_currency' => 'GBP',
            'row_hash' => hash('sha256', 'empty-month-impact-row-' . $marker . $txnDate),
            'validation_status' => 'valid',
        ]
    );

    return $uploadId;
}
