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
$harness->run(\eel_accounts\Service\CompanyMinutesService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompanyMinutesService $service): void {
    $harness->check(\eel_accounts\Service\CompanyMinutesService::class, 'returns no minutes when company or period is missing', static function () use ($harness, $service): void {
        $harness->assertSame([], $service->listMinutes(0, 1));
        $harness->assertSame([], $service->listMinutes(1, 0));
    });

    $harness->check(\eel_accounts\Service\CompanyMinutesService::class, 'lists voucher minutes no later than the current date inside an open accounting period', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'journals', 'dividend_vouchers'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip('Company minutes fixture tables are not available on the default InterfaceDB connection.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = company_minutes_service_fixture();
            company_minutes_service_voucher($fixture, '2026-06-30', 'Minutes inside the accounting period.');
            company_minutes_service_voucher($fixture, '2026-08-05', 'Minutes after the current date.');
            company_minutes_service_voucher($fixture, '2027-01-05', 'Minutes outside the accounting period.');

            $rows = $service->listMinutes($fixture['company_id'], $fixture['accounting_period_id'], 500, '2026-07-01');

            $harness->assertCount(1, $rows);
            $harness->assertSame('2026-06-30', (string)($rows[0]['date'] ?? ''));
            $harness->assertSame('Minutes inside the accounting period.', (string)($rows[0]['minutes'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\CompanyMinutesService::class, 'lists voided dividends as separate minutes records through the cutoff date', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'journals', 'dividend_vouchers'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip('Company minutes fixture tables are not available on the default InterfaceDB connection.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = company_minutes_service_fixture();
            $voucherId = company_minutes_service_voucher($fixture, '2026-06-30', 'Original declaration minutes.');
            InterfaceDB::prepareExecute(
                'UPDATE dividend_vouchers
                 SET voided_at = :voided_at,
                     void_reason = :void_reason
                 WHERE id = :id',
                [
                    'voided_at' => '2026-07-01 12:34:56',
                    'void_reason' => 'Dividend capacity was uncertain.',
                    'id' => $voucherId,
                ]
            );

            $rows = $service->listMinutes($fixture['company_id'], $fixture['accounting_period_id'], 500, '2026-07-01');

            $harness->assertCount(2, $rows);
            $harness->assertSame('2026-07-01', (string)($rows[0]['date'] ?? ''));
            $harness->assertSame('dividend_voucher_void', (string)($rows[0]['source_type'] ?? ''));
            $harness->assertSame(true, str_contains((string)($rows[0]['minutes'] ?? ''), 'declaration minutes dated 2026-06-30'));
            $harness->assertSame(true, str_contains((string)($rows[0]['minutes'] ?? ''), 'Reason: Dividend capacity was uncertain.'));
            $harness->assertSame('2026-06-30', (string)($rows[1]['date'] ?? ''));
            $harness->assertSame('Original declaration minutes.', (string)($rows[1]['minutes'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\CompanyMinutesService::class, 'excludes void minutes after the accounting period end when it is the earlier cutoff', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'journals', 'dividend_vouchers'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip('Company minutes fixture tables are not available on the default InterfaceDB connection.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = company_minutes_service_fixture();
            $voucherId = company_minutes_service_voucher($fixture, '2026-06-30', 'Original declaration minutes.');
            InterfaceDB::prepareExecute(
                'UPDATE dividend_vouchers SET voided_at = :voided_at WHERE id = :id',
                ['voided_at' => '2027-01-05 12:34:56', 'id' => $voucherId]
            );

            $rows = $service->listMinutes($fixture['company_id'], $fixture['accounting_period_id'], 500, '2027-02-01');

            $harness->assertCount(1, $rows);
            $harness->assertSame('2026-06-30', (string)($rows[0]['date'] ?? ''));
            $harness->assertSame('dividend_voucher', (string)($rows[0]['source_type'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function company_minutes_service_fixture(): array
{
    $marker = 'MIN' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12));

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Company Minutes Fixture ' . $marker,
            'company_number' => $marker,
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number ORDER BY id DESC LIMIT 1',
        ['company_number' => $marker]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'label' => 'FY ' . $marker,
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'marker' => $marker,
    ];
}

function company_minutes_service_voucher(array $fixture, string $date, string $minutes): int
{
    $sourceRef = 'company-minutes-test:' . $fixture['marker'] . ':' . $date;

    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id,
            accounting_period_id,
            source_type,
            source_ref,
            journal_date,
            description,
            is_posted,
            created_at,
            updated_at
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :source_type,
            :source_ref,
            :journal_date,
            :description,
            1,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
         )',
        [
            'company_id' => $fixture['company_id'],
            'accounting_period_id' => $fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $date,
            'description' => 'Company minutes fixture',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $fixture['company_id'],
            'source_ref' => $sourceRef,
        ]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO dividend_vouchers (
            company_id,
            accounting_period_id,
            journal_id,
            company_name,
            shareholder_name,
            director_name,
            declaration_date,
            payment_date,
            amount,
            description,
            voucher_text,
            minutes_text,
            issued_by
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :journal_id,
            :company_name,
            :shareholder_name,
            :director_name,
            :declaration_date,
            :payment_date,
            :amount,
            :description,
            :voucher_text,
            :minutes_text,
            :issued_by
         )',
        [
            'company_id' => $fixture['company_id'],
            'accounting_period_id' => $fixture['accounting_period_id'],
            'journal_id' => $journalId,
            'company_name' => 'Company Minutes Fixture',
            'shareholder_name' => 'Fixture Shareholder',
            'director_name' => 'Fixture Director',
            'declaration_date' => $date,
            'payment_date' => $date,
            'amount' => '10.00',
            'description' => 'Fixture dividend',
            'voucher_text' => 'Fixture voucher.',
            'minutes_text' => $minutes,
            'issued_by' => 'test',
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM dividend_vouchers WHERE journal_id = :journal_id ORDER BY id DESC LIMIT 1',
        ['journal_id' => $journalId]
    );
}
