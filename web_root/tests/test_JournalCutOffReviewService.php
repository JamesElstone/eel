<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\JournalCutOffReviewService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\JournalCutOffReviewService $service): void {
    $harness->check(\eel_accounts\Service\JournalCutOffReviewService::class, 'returns missing-context access state without an acknowledgement', static function () use ($harness, $service): void {
        $context = $service->fetchContext(0, 0);

        $harness->assertSame(null, $context['acknowledgement'] ?? null);
        $harness->assertSame(false, (bool)($context['access']['permitted'] ?? true));
        $harness->assertSame('missing_context', (string)($context['access']['reason_code'] ?? ''));
    });

    $harness->check(\eel_accounts\Service\JournalCutOffReviewService::class, 'returns unlocked access for an unacknowledged deterministic period', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
            $companyNumber = 'JC' . $marker;
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active)
                 VALUES (:company_name, :company_number, 1)',
                ['company_name' => 'Journal Cut Off Fixture ' . $marker, 'company_number' => $companyNumber]
            );
            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'Journal Cut Off FY',
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                ]
            );
            $accountingPeriodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
                ['company_id' => $companyId]
            );

            $context = $service->fetchContext($companyId, $accountingPeriodId);
            $harness->assertSame(null, $context['acknowledgement'] ?? null);
            $harness->assertSame(true, (bool)($context['access']['permitted'] ?? false));
            $harness->assertSame(false, (bool)($context['access']['is_locked'] ?? true));
            $harness->assertSame('', (string)($context['access']['reason_code'] ?? 'missing'));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
