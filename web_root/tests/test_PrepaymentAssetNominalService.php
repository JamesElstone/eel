<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(
    \eel_accounts\Service\PrepaymentAssetNominalService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\PrepaymentAssetNominalService $service): void {
        GoldenAccountsFixture::build();

        $harness->check(
            \eel_accounts\Service\PrepaymentAssetNominalService::class,
            'accepts only active Prepayments-subtype assets in settings and schedule resolution',
            static function () use ($harness, $service): void {
                $resolved = $service->resolveForCompany(GoldenAccountsFixture::GOLDEN_COMPANY_ID);
                $harness->assertSame(91018, (int)($resolved['id'] ?? 0));
                $harness->assertSame('prepayments', (string)($resolved['subtype_code'] ?? ''));

                $fixedAssetRejected = false;
                try {
                    $service->requireSelection(91013);
                } catch (RuntimeException $exception) {
                    $fixedAssetRejected = str_contains($exception->getMessage(), 'Prepayments subtype');
                }
                if (!$fixedAssetRejected) {
                    throw new RuntimeException('A fixed-asset nominal was not rejected by requireSelection().');
                }

                $store = new \eel_accounts\Store\CompanySettingsStore(GoldenAccountsFixture::GOLDEN_COMPANY_ID);
                $settings = $store->all();
                $settings['prepayment_asset_nominal_id'] = '91013';
                $settingsRejected = false;
                try {
                    (new \eel_accounts\Service\CompanySettingsService())->saveNominalsSection($store, $settings);
                } catch (RuntimeException $exception) {
                    $settingsRejected = str_contains($exception->getMessage(), 'Prepayments subtype');
                }
                if (!$settingsRejected) {
                    throw new RuntimeException('Company settings accepted a fixed-asset Prepayments nominal.');
                }
                $harness->assertSame(
                    91018,
                    (int)InterfaceDB::fetchColumn(
                        'SELECT value FROM company_settings WHERE company_id = :company_id AND setting = :setting',
                        ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'setting' => 'prepayment_asset_nominal_id']
                    )
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\PrepaymentAssetNominalService::class,
            'blocks a nominal change when an affected golden schedule has posted journals',
            static function () use ($harness, $service): void {
                InterfaceDB::beginTransaction();
                try {
                    $subtypeId = (int)InterfaceDB::fetchColumn("SELECT id FROM nominal_account_subtypes WHERE code = 'prepayments'");
                    InterfaceDB::execute(
                        'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, prepayment_candidate, is_active, sort_order)
                         VALUES (:code, :name, \'asset\', :subtype, \'other\', 0, 1, :sort)',
                        ['code' => 'GOLD-PREPAY-ALT', 'name' => 'Golden Alternative Prepayments', 'subtype' => $subtypeId, 'sort' => 91020]
                    );
                    $alternativeId = (int)InterfaceDB::fetchColumn("SELECT id FROM nominal_accounts WHERE code = 'GOLD-PREPAY-ALT'");
                    $postedRejected = false;
                    try {
                        $service->prepareChange(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $alternativeId);
                    } catch (RuntimeException $exception) {
                        $postedRejected = str_contains($exception->getMessage(), 'posted journals')
                            && str_contains($exception->getMessage(), 'review #9195');
                    }
                    $harness->assertTrue($postedRejected);
                } finally {
                    InterfaceDB::rollBack();
                }
            }
        );
    }
);
