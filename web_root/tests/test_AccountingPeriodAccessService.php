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
$harness->run(
    \eel_accounts\Service\AccountingPeriodAccessService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\AccountingPeriodAccessService $service
    ): void {
        $harness->check(\eel_accounts\Service\AccountingPeriodAccessService::class, 'denies data entry when accounting context is missing', static function () use ($harness, $service): void {
            $state = $service->fetchDataEntryState(0, 0);

            $harness->assertSame(false, $state['permitted']);
            $harness->assertSame(false, $state['is_locked']);
            $harness->assertSame('missing_context', $state['reason_code']);
            $harness->assertSame(false, $service->isDataEntryPermitted(0, 0));

            $message = '';
            try {
                $service->assertDataEntryPermitted(0, 0, 'save a transaction');
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
            }
            $harness->assertSame('Select a company and accounting period before you can save a transaction.', $message);
        });

        $harness->check(\eel_accounts\Service\AccountingPeriodAccessService::class, 'permits open periods and rejects locked periods with stable reasons', static function () use ($harness, $service): void {
            if (!InterfaceDB::tableExists('year_end_reviews')) {
                $harness->skip('year_end_reviews table is not available.');
            }

            $companyId = random_int(700000000, 799999999);
            $accountingPeriodId = random_int(800000000, 899999999);
            InterfaceDB::beginTransaction();
            try {
                $open = $service->fetchDataEntryState($companyId, $accountingPeriodId);
                $harness->assertSame(true, $open['permitted']);
                $harness->assertSame(false, $open['is_locked']);
                $harness->assertSame('', $open['reason_code']);

                InterfaceDB::prepareExecute(
                    'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                     VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by)',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'locked_by' => 'access_service_test',
                    ]
                );

                $locked = $service->fetchDataEntryState($companyId, $accountingPeriodId);
                $harness->assertSame(false, $locked['permitted']);
                $harness->assertSame(true, $locked['is_locked']);
                $harness->assertSame('period_locked', $locked['reason_code']);
                $harness->assertSame('This accounting period is locked, so data entry is not permitted.', $locked['reason']);

                $message = '';
                try {
                    $service->assertDataEntryPermitted($companyId, $accountingPeriodId, 'change potential asset settings');
                } catch (RuntimeException $exception) {
                    $message = $exception->getMessage();
                }
                $harness->assertSame(
                    'This accounting period is locked, so you cannot change potential asset settings.',
                    $message
                );
            } finally {
                InterfaceDB::rollBack();
            }
        });
    }
);
