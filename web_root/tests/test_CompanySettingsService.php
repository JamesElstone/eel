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
$harness->run(\eel_accounts\Service\CompanySettingsService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompanySettingsService $service): void {
    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'strips whitespace from pasted HMRC UTR values', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'normaliseUtr');
        $method->setAccessible(true);

        $harness->assertSame('2794616478', $method->invoke($service, '27946 16478'));
        $harness->assertSame('2794616478', $method->invoke($service, " 27946\t16478\n"));
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'accepts only blank or strict ISO qualifying activity cessation dates', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'normaliseQualifyingActivityCessationDate');
        $method->setAccessible(true);

        $harness->assertSame('', $method->invoke($service, '  '));
        $harness->assertSame('2026-02-28', $method->invoke($service, '2026-02-28'));

        try {
            $method->invoke($service, '2026-02-30');
            throw new RuntimeException('Expected an invalid cessation date to be rejected.');
        } catch (ReflectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $harness->assertTrue(str_contains($exception->getMessage(), 'YYYY-MM-DD'));
        }
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'saves a cessation date and rebuilds capital allowance pools for affected open periods', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $companyId = 98201;
            $periodId = 982011;
            companySettingsServiceSeedCompany($companyId);
            companySettingsServiceSeedPeriod($companyId, $periodId, '2099-01-01', '2099-12-31');

            $store = new \eel_accounts\Store\CompanySettingsStore($companyId);
            $service->saveCompanySection($store, companySettingsServicePayload($companyId, '2099-06-30'));

            $harness->assertSame('2099-06-30', (string)$store->get('qualifying_activity_ceased_on', ''));
            $harness->assertSame(
                2,
                (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM capital_allowance_pool_runs
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                )
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'rejects cessation changes that affect a locked accounting period', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $companyId = 98202;
            $periodId = 982021;
            companySettingsServiceSeedCompany($companyId);
            companySettingsServiceSeedPeriod($companyId, $periodId, '2099-01-01', '2099-12-31');
            InterfaceDB::prepareExecute(
                'INSERT INTO year_end_reviews (
                    company_id, accounting_period_id, is_locked, locked_at, locked_by
                 ) VALUES (
                    :company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $periodId,
                    'locked_by' => 'company-settings-test',
                ]
            );

            try {
                $service->saveCompanySection(
                    new \eel_accounts\Store\CompanySettingsStore($companyId),
                    companySettingsServicePayload($companyId, '2099-06-30')
                );
                throw new RuntimeException('Expected the locked affected period to reject the cessation change.');
            } catch (Throwable $exception) {
                $harness->assertTrue(str_contains($exception->getMessage(), 'locked'));
            }

            $harness->assertSame(
                0,
                (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM company_settings
                     WHERE company_id = :company_id
                       AND setting = :setting',
                    ['company_id' => $companyId, 'setting' => 'qualifying_activity_ceased_on']
                )
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'rejects cessation changes that affect submitted Corporation Tax evidence', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $companyId = 98204;
            $periodId = 982041;
            companySettingsServiceSeedCompany($companyId);
            companySettingsServiceSeedPeriod($companyId, $periodId, '2099-01-01', '2099-12-31');
            $sync = (new \eel_accounts\Service\CorporationTaxPeriodService())
                ->syncForAccountingPeriod($companyId, $periodId);
            $harness->assertSame(true, (bool)($sync['success'] ?? false));
            InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET status = :status
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                [
                    'status' => 'submitted',
                    'company_id' => $companyId,
                    'accounting_period_id' => $periodId,
                ]
            );

            try {
                $service->saveCompanySection(
                    new \eel_accounts\Store\CompanySettingsStore($companyId),
                    companySettingsServicePayload($companyId, '2099-06-30')
                );
                throw new RuntimeException('Expected submitted CT evidence to reject the cessation change.');
            } catch (Throwable $exception) {
                $harness->assertTrue(str_contains($exception->getMessage(), 'submitted or accepted'));
            }

            $harness->assertSame(
                0,
                (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM company_settings
                     WHERE company_id = :company_id
                       AND setting = :setting',
                    ['company_id' => $companyId, 'setting' => 'qualifying_activity_ceased_on']
                )
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'rejects cessation changes while the VAT support policy makes Tax and Year End read only', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $companyId = 98203;
            companySettingsServiceSeedCompany($companyId, true);

            try {
                $service->saveCompanySection(
                    new \eel_accounts\Store\CompanySettingsStore($companyId),
                    companySettingsServicePayload($companyId, '2099-06-30', true)
                );
                throw new RuntimeException('Expected the VAT support scope to reject the cessation change.');
            } catch (Throwable $exception) {
                $harness->assertTrue(str_contains($exception->getMessage(), 'read only'));
            }

            $harness->assertSame(
                0,
                (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM company_settings
                     WHERE company_id = :company_id
                       AND setting = :setting',
                    ['company_id' => $companyId, 'setting' => 'qualifying_activity_ceased_on']
                )
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'suggests a default trade nominal from trade creditor liabilities', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'buildNominalDefaultSuggestions');
        $method->setAccessible(true);

        $suggestions = $method->invoke($service, [
            ['id' => 10, 'code' => '1200', 'name' => 'Bank', 'account_type' => 'asset', 'subtype_code' => 'bank'],
            ['id' => 11, 'code' => '4000', 'name' => 'Sales', 'account_type' => 'income', 'subtype_code' => 'turnover'],
            ['id' => 14, 'code' => '2110', 'name' => 'Expense Claims Payable', 'account_type' => 'liability', 'subtype_code' => 'expense_payable'],
            ['id' => 15, 'code' => '2300', 'name' => 'Trade Creditors', 'account_type' => 'liability', 'subtype_code' => 'trade_creditor'],
            ['id' => 20, 'code' => '5000', 'name' => 'Materials', 'account_type' => 'expense', 'subtype_code' => ''],
        ]);

        $harness->assertSame(15, (int)($suggestions['default_trade_nominal_id']['id'] ?? 0));
        $harness->assertSame(11, (int)($suggestions['default_sales_nominal_id']['id'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'suggests expense claims payable as the default expense nominal', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'buildNominalDefaultSuggestions');
        $method->setAccessible(true);

        $suggestions = $method->invoke($service, [
            ['id' => 14, 'code' => '2110', 'name' => 'Expense Claims Payable', 'account_type' => 'liability', 'subtype_code' => 'expense_payable'],
            ['id' => 20, 'code' => '5000', 'name' => 'Materials', 'account_type' => 'expense', 'subtype_code' => ''],
        ]);

        $harness->assertSame(14, (int)($suggestions['default_expense_nominal_id']['id'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'suggests participator loan asset and liability nominals separately', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'buildNominalDefaultSuggestions');
        $method->setAccessible(true);

        $suggestions = $method->invoke($service, [
            ['id' => 3, 'code' => '1200', 'name' => 'Director Loan Asset', 'account_type' => 'asset', 'subtype_code' => 'director_loan_asset'],
            ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability', 'account_type' => 'liability', 'subtype_code' => 'director_loan_liability'],
        ]);

        $harness->assertSame(3, (int)($suggestions['participator_loan_asset_nominal_id']['id'] ?? 0));
        $harness->assertSame(5, (int)($suggestions['participator_loan_liability_nominal_id']['id'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'maps legacy director loan setting to liability setting', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'normaliseDirectorLoanNominalSettings');
        $method->setAccessible(true);

        $settings = $method->invoke($service, [
            'director_loan_nominal_id' => '42',
        ]);

        $harness->assertSame('42', (string)($settings['director_loan_liability_nominal_id'] ?? ''));
        $harness->assertSame('42', (string)($settings['director_loan_nominal_id'] ?? ''));
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'formats money with the configured currency symbol', static function () use ($harness, $service): void {
        $harness->assertSame('€ 123.45', $service->money(['default_currency_symbol' => '&#8364;'], 123.45));
        $harness->assertSame('£ 10.00', $service->money(['default_currency_symbol' => ''], 10));
        $harness->assertSame('$ 50.00', $service->money(['default_currency_symbol' => '$'], 50));
        $harness->assertSame('-$ 50.00', $service->money(['default_currency_symbol' => '$'], -50));
        $harness->assertSame('-', $service->money(['default_currency_symbol' => '$'], 'not money'));
        $harness->assertSame('<span class="amount-negative">-$ 50.00</span>', $service->moneyHtml(['default_currency_symbol' => '$'], -50));
    });
});

function companySettingsServiceSeedCompany(int $companyId, bool $liveHmrcVat = false): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (
            id, company_name, company_number, is_vat_registered,
            vat_country_code, vat_number, vat_validation_status,
            vat_validation_source, vat_validation_mode
         ) VALUES (
            :id, :company_name, :company_number, :is_vat_registered,
            :vat_country_code, :vat_number, :vat_validation_status,
            :vat_validation_source, :vat_validation_mode
         )',
        [
            'id' => $companyId,
            'company_name' => 'Company settings test ' . $companyId,
            'company_number' => 'CST' . $companyId,
            'is_vat_registered' => $liveHmrcVat ? 1 : 0,
            'vat_country_code' => $liveHmrcVat ? 'GB' : null,
            'vat_number' => $liveHmrcVat ? '123456789' : null,
            'vat_validation_status' => $liveHmrcVat ? 'valid' : null,
            'vat_validation_source' => $liveHmrcVat ? 'hmrc' : null,
            'vat_validation_mode' => $liveHmrcVat ? 'LIVE' : null,
        ]
    );
}

function companySettingsServiceSeedPeriod(
    int $companyId,
    int $periodId,
    string $start,
    string $end
): void {
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (
            id, company_id, label, period_start, period_end
         ) VALUES (
            :id, :company_id, :label, :period_start, :period_end
         )',
        [
            'id' => $periodId,
            'company_id' => $companyId,
            'label' => $start . ' to ' . $end,
            'period_start' => $start,
            'period_end' => $end,
        ]
    );
}

function companySettingsServicePayload(
    int $companyId,
    string $cessationDate,
    bool $liveHmrcVat = false
): array {
    return [
        'company_id' => (string)$companyId,
        'company_name' => 'Company settings test ' . $companyId,
        'companies_house_number' => 'CST' . $companyId,
        'utr' => '',
        'associated_company_count' => '0',
        'qualifying_activity_ceased_on' => $cessationDate,
        'default_currency' => 'GBP',
        'date_format' => 'd/m/Y',
        'is_vat_registered' => $liveHmrcVat,
        'vat_country_code' => $liveHmrcVat ? 'GB' : '',
        'vat_number' => $liveHmrcVat ? '123456789' : '',
        'vat_validation_status' => $liveHmrcVat ? 'valid' : '',
        'vat_validated_at' => '',
        'vat_validation_source' => $liveHmrcVat ? 'hmrc' : '',
        'vat_validation_mode' => $liveHmrcVat ? 'LIVE' : '',
        'vat_validation_name' => '',
        'vat_validation_address_line1' => '',
        'vat_validation_postcode' => '',
        'vat_validation_country_code' => '',
        'vat_last_error' => '',
    ];
}
