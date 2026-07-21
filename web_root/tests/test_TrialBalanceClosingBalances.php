<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\TrialBalanceService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TrialBalanceService $service): void {
        $harness->check(\eel_accounts\Service\TrialBalanceService::class, 'keeps period equality while reporting cumulative closing control balances', static function () use ($harness, $service): void {
            foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'nominal_accounts'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }
            InterfaceDB::beginTransaction();
            try {
                $nominals = [];
                StandardNominalTestFixture::ensureNominals(['1000', '1200', '2100', '3000']);
                foreach (['1000', '1200', '2100', '3000'] as $code) {
                    $nominals[$code] = StandardNominalTestFixture::id($code);
                }

                $marker = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                    ['company_name' => 'Synthetic Trial Balance Fixture', 'company_number' => 'TBC' . $marker]
                );
                $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'TBC' . $marker]);
                $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
                $settings->set('participator_loan_asset_nominal_id', $nominals['1200'], 'int');
                $settings->set('participator_loan_liability_nominal_id', $nominals['2100'], 'int');
                $settings->flush();
                $periodIds = [];
                foreach ([
                    'prior' => ['2024-01-01', '2024-12-31'],
                    'current' => ['2025-01-01', '2025-12-31'],
                ] as $key => [$start, $end]) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :start, :end)',
                        ['company_id' => $companyId, 'label' => 'TB ' . $key . ' ' . $marker, 'start' => $start, 'end' => $end]
                    );
                    $periodIds[$key] = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label', ['company_id' => $companyId, 'label' => 'TB ' . $key . ' ' . $marker]);
                }

                // Deliberately synthetic balanced journals: no production balances belong in source control.
                trialBalanceClosingJournal($companyId, $periodIds['prior'], '2024-12-31', 'tb-prior-' . $marker, [
                    [$nominals['1000'], 1137.42, 0.00],
                    [$nominals['1200'], 286.19, 0.00],
                    [$nominals['2100'], 0.00, 2074.83],
                    [$nominals['3000'], 651.22, 0.00],
                ]);
                trialBalanceClosingJournal($companyId, $periodIds['current'], '2025-12-31', 'tb-current-' . $marker, [
                    [$nominals['1200'], 3462.71, 0.00],
                    [$nominals['1000'], 0.00, 418.36],
                    [$nominals['2100'], 0.00, 7931.58],
                    [$nominals['3000'], 4887.23, 0.00],
                ]);

                $snapshot = $service->fetchStateSnapshot($companyId, $periodIds['current']);
                $summary = (array)($snapshot['summary'] ?? []);
                $harness->assertSame(true, (bool)($summary['trial_balance_status']['is_balanced'] ?? false));
                $harness->assertSame('719.06', number_format((float)($summary['bank_balance_total'] ?? 0), 2, '.', ''));
                $harness->assertSame('6257.51', number_format((float)($summary['director_loan_balance'] ?? 0), 2, '.', ''));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);

function trialBalanceClosingJournal(int $companyId, int $periodId, string $date, string $sourceRef, array $lines): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :date, :description, 1)',
        ['company_id' => $companyId, 'period_id' => $periodId, 'source_type' => 'manual', 'source_ref' => $sourceRef, 'date' => $date, 'description' => 'Synthetic closing-balance journal']
    );
    $journalId = (int)InterfaceDB::fetchColumn('SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref', ['company_id' => $companyId, 'source_ref' => $sourceRef]);
    foreach ($lines as [$nominalId, $debit, $credit]) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
             VALUES (:journal_id, :nominal_id, :debit, :credit, :description)',
            ['journal_id' => $journalId, 'nominal_id' => $nominalId, 'debit' => number_format($debit, 2, '.', ''), 'credit' => number_format($credit, 2, '.', ''), 'description' => 'Synthetic closing-balance line']
        );
    }
}
