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
$harness->run(\eel_accounts\Service\YearEndChecklistService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'locking a period posts asset depreciation first', static function () use ($harness): void {
        yearEndChecklistServiceRequireDepreciationLockSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = yearEndChecklistServiceCreateDepreciationLockFixture();
            $result = (new \eel_accounts\Service\YearEndChecklistService())->lockPeriod(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test'
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(1, (int)(($result['depreciation'] ?? [])['created'] ?? 0));
            $harness->assertSame(1, InterfaceDB::countWhere('asset_depreciation_entries', [
                'asset_id' => (int)$fixture['asset_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
            ]));
            $harness->assertSame(1, InterfaceDB::countWhere('year_end_reviews', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'is_locked' => 1,
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'director loan offset before lock is gated by acknowledgement', static function () use ($harness): void {
        yearEndChecklistServiceRequireDirectorLoanOffsetLockSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = yearEndChecklistServiceCreateDirectorLoanOffsetFixture();
            $service = new \eel_accounts\Service\YearEndChecklistService();
            $method = new ReflectionMethod($service, 'applyDirectorLoanOffsetBeforeLock');
            $method->setAccessible(true);

            $blocked = $method->invoke(
                $service,
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                ['review' => []],
                'test'
            );

            $harness->assertSame(false, (bool)($blocked['success'] ?? true));
            $harness->assertSame(true, str_contains((string)(($blocked['errors'] ?? [])[0] ?? ''), 'acknowledgement'));
            $harness->assertSame(0, InterfaceDB::countWhere('journal_entry_metadata', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'journal_key' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
            ]));

            $posted = $method->invoke(
                $service,
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                ['review' => ['director_loan_closing_acknowledged_at' => '2026-07-03 12:00:00']],
                'test'
            );

            $harness->assertSame(true, (bool)($posted['success'] ?? false));
            $harness->assertSame(1, InterfaceDB::countWhere('journal_entry_metadata', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'journal_key' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'review acknowledgement clears advisory warning checks only', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $method = new ReflectionMethod($service, 'applyReviewAcknowledgement');
        $method->setAccessible(true);

        $warning = [
            'check_code' => 'filing_basis_reminder',
            'status' => 'warning',
            'metric_value' => '',
            'detail_text' => 'App numbers remain working figures.',
        ];
        $acknowledged = $method->invoke($service, $warning, [
            'filing_basis_reminder' => [
                'acknowledged_at' => '2026-07-03 12:00:00',
                'acknowledged_by' => 'test',
                'note' => null,
            ],
        ]);

        $harness->assertSame('pass', (string)$acknowledged['status']);
        $harness->assertSame('Reviewed', (string)$acknowledged['metric_value']);
        $harness->assertSame(true, str_contains((string)$acknowledged['detail_text'], 'Review acknowledged'));

        $fail = $method->invoke($service, [
            'check_code' => 'lock_readiness_checklist',
            'status' => 'fail',
            'metric_value' => 'Not ready',
            'detail_text' => 'Blocking check failed.',
        ], [
            'lock_readiness_checklist' => [
                'acknowledged_at' => '2026-07-03 12:00:00',
            ],
        ]);

        $harness->assertSame('fail', (string)$fail['status']);
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'workflow URLs omit selected site context ids', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndChecklistService();
        $makeCheck = new ReflectionMethod($service, 'makeCheck');
        $makeCheck->setAccessible(true);
        $dashboardActionUrl = new ReflectionMethod($service, 'dashboardActionUrl');
        $dashboardActionUrl->setAccessible(true);

        $check = $makeCheck->invoke(
            $service,
            'prepayments_accruals_placeholder',
            'Prepayments and accruals review',
            'warning',
            'warning',
            'Manual review reminder.',
            '',
            '?page=journal&company_id=12&accounting_period_id=34&show_card=nominal_closing_balances'
        );

        $harness->assertSame('?page=journal&show_card=nominal_closing_balances', (string)$check['action_url']);
        $harness->assertSame('?page=year_end&show_card=year_end_checklist', (string)$dashboardActionUrl->invoke($service, 12, 34));
    });
});

function yearEndChecklistServiceRequireDepreciationLockSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'nominal_accounts', 'asset_register', 'asset_depreciation_entries', 'year_end_reviews', 'year_end_check_results', 'year_end_audit_log', 'accounting_period_adjustments', 'tax_loss_carryforwards'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['1000', '1300', '1330', '4000', '6200'] as $code) {
        if (yearEndChecklistServiceNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }
}

function yearEndChecklistServiceRequireDirectorLoanOffsetLockSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'nominal_accounts', 'journal_entry_metadata'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['1200', '2100'] as $code) {
        if (yearEndChecklistServiceNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }
}

function yearEndChecklistServiceCreateDepreciationLockFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('61' . $marker);
    $accountingPeriodId = (int)('62' . $marker);
    $assetId = (int)('63' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Year End Depreciation Fixture ' . $marker,
            'company_number' => 'YED' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'YED FY ' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => 'year-end-depreciation-fixture-' . $marker,
            'journal_date' => '2025-12-31',
            'description' => 'Year end depreciation fixture ' . $marker,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => 'year-end-depreciation-fixture-' . $marker,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 1200.00, 0.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => yearEndChecklistServiceNominalId('1000'),
            'line_description' => 'Fixture bank debit',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 0.00, 1200.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => yearEndChecklistServiceNominalId('4000'),
            'line_description' => 'Fixture sales credit',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            id,
            company_id,
            asset_code,
            description,
            category,
            nominal_account_id,
            accum_dep_nominal_id,
            purchase_date,
            cost,
            useful_life_years,
            depreciation_method,
            residual_value,
            status
         ) VALUES (
            :id,
            :company_id,
            :asset_code,
            :description,
            :category,
            :nominal_account_id,
            :accum_dep_nominal_id,
            :purchase_date,
            :cost,
            :useful_life_years,
            :depreciation_method,
            :residual_value,
            :status
         )',
        [
            'id' => $assetId,
            'company_id' => $companyId,
            'asset_code' => 'YED-' . $marker,
            'description' => 'Year end depreciation fixture asset',
            'category' => 'tools_equipment',
            'nominal_account_id' => yearEndChecklistServiceNominalId('1300'),
            'accum_dep_nominal_id' => yearEndChecklistServiceNominalId('1330'),
            'purchase_date' => '2025-01-01',
            'cost' => 1200.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'asset_id' => $assetId,
    ];
}

function yearEndChecklistServiceCreateDirectorLoanOffsetFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('64' . $marker);
    $accountingPeriodId = (int)('65' . $marker);
    $assetNominalId = yearEndChecklistServiceNominalId('1200');
    $liabilityNominalId = yearEndChecklistServiceNominalId('2100');

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Year End Director Loan Fixture ' . $marker,
            'company_number' => 'YDL' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'YDL FY ' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );

    yearEndChecklistServiceInsertDirectorLoanLineJournal($companyId, $accountingPeriodId, $assetNominalId, 1000.00, 0.00, 'asset', $marker);
    yearEndChecklistServiceInsertDirectorLoanLineJournal($companyId, $accountingPeriodId, $liabilityNominalId, 0.00, 1500.00, 'liability', $marker);

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ];
}

function yearEndChecklistServiceInsertDirectorLoanLineJournal(int $companyId, int $accountingPeriodId, int $nominalId, float $debit, float $credit, string $key, string $marker): void
{
    $sourceRef = 'year-end-director-loan-fixture-' . $marker . '-' . $key;
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => '2025-12-31',
            'description' => 'Year end director loan fixture ' . $key,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => $sourceRef,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalId,
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'line_description' => 'Year end director loan fixture',
        ]
    );
}

function yearEndChecklistServiceNominalId(string $code): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}
