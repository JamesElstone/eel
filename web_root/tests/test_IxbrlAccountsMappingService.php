<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PeriodLedgerTestFixture.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlAccountsMappingService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(\eel_accounts\Service\IxbrlAccountsMappingService::class, 'reconciles every P and L source list and keeps tax, prepayments, and depreciation in their exact buckets', static function () use ($harness): void {
            InterfaceDB::beginTransaction();
            try {
                $fixture = periodLedgerTestCreateFixture();
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];
                $materialsSubtypeId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM nominal_account_subtypes WHERE code = :code',
                    ['code' => 'materials']
                );
                if ($materialsSubtypeId <= 0) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO nominal_account_subtypes (code, name, parent_account_type) VALUES (:code, :name, :type)',
                        ['code' => 'materials', 'name' => 'Materials', 'type' => 'cost_of_sales']
                    );
                    $materialsSubtypeId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM nominal_account_subtypes WHERE code = :code',
                        ['code' => 'materials']
                    );
                }
                InterfaceDB::prepareExecute(
                    'UPDATE nominal_accounts SET account_subtype_id = :subtype_id WHERE id = :id',
                    ['subtype_id' => $materialsSubtypeId, 'id' => (int)$fixture['cost_nominal_id']]
                );
                $taxNominalId = periodLedgerTestInsertNominal(
                    'IXT' . substr(hash('sha256', (string)$periodId), 0, 9),
                    'iXBRL Corporation Tax ' . $periodId,
                    'expense',
                    'disallowable'
                );
                $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
                $settings->set('corporation_tax_expense_nominal_id', $taxNominalId, 'int');
                $settings->flush();
                periodLedgerTestInsertJournal(
                    $companyId,
                    $periodId,
                    '2025-06-30',
                    'ixbrl-mapping-ct-' . $periodId,
                    [
                        [$taxNominalId, 123.0, 0.0],
                        [(int)$fixture['asset_nominal_id'], 0.0, 123.0],
                    ]
                );
                $otherIncomeNominalId = periodLedgerTestInsertNominal(
                    'IXI' . substr(hash('sha256', 'income:' . $periodId), 0, 9),
                    'Interest received',
                    'income',
                    'allowable'
                );
                $wagesNominalId = periodLedgerTestInsertNominal(
                    'IXW' . substr(hash('sha256', 'wages:' . $periodId), 0, 9),
                    'Wages and salaries',
                    'expense',
                    'allowable'
                );
                $staffWelfareNominalId = periodLedgerTestInsertNominal(
                    'IXS' . substr(hash('sha256', 'welfare:' . $periodId), 0, 9),
                    'Staff Welfare',
                    'expense',
                    'allowable'
                );
                $subcontractorNominalId = periodLedgerTestInsertNominal(
                    'IXC' . substr(hash('sha256', 'subcontractor:' . $periodId), 0, 9),
                    'Electrical subcontractors',
                    'cost_of_sales',
                    'allowable'
                );
                $manualDepreciationNominalId = periodLedgerTestInsertNominal(
                    'IXD' . substr(hash('sha256', 'depreciation:' . $periodId), 0, 9),
                    'Manual asset write off',
                    'expense',
                    'allowable'
                );
                $depreciationSubtypeId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM nominal_account_subtypes WHERE code = :code',
                    ['code' => 'depreciation_expense']
                );
                $harness->assertTrue($depreciationSubtypeId > 0);
                InterfaceDB::prepareExecute(
                    'UPDATE nominal_accounts SET account_subtype_id = :subtype_id WHERE id = :id',
                    ['subtype_id' => $depreciationSubtypeId, 'id' => $manualDepreciationNominalId]
                );
                periodLedgerTestInsertJournal(
                    $companyId,
                    $periodId,
                    '2025-07-31',
                    'ixbrl-mapping-micro-lines-' . $periodId,
                    [
                        [$wagesNominalId, 80.0, 0.0],
                        [$staffWelfareNominalId, 30.0, 0.0],
                        [$manualDepreciationNominalId, 20.0, 0.0],
                        [$subcontractorNominalId, 50.0, 0.0],
                        [$otherIncomeNominalId, 0.0, 40.0],
                        [(int)$fixture['asset_nominal_id'], 0.0, 140.0],
                    ]
                );

                $mapping = (new \eel_accounts\Service\IxbrlAccountsMappingService())->getAccountsMapping(
                    $companyId,
                    $periodId,
                    false,
                    [
                        'success' => true,
                        'rows' => [[
                            'asset_id' => 999999,
                            'asset_code' => 'IX-DEPRECIATION',
                            'period_start' => '2025-01-01',
                            'period_end' => '2025-12-31',
                            'amount' => 60.0,
                        ]],
                    ],
                    [
                        [
                            'debit_nominal_id' => (int)$fixture['cost_nominal_id'],
                            'credit_nominal_id' => (int)$fixture['asset_nominal_id'],
                            'amount_pence' => 2500,
                            'journal_date' => '2025-06-30',
                            'review_id' => 31,
                            'schedule_id' => 41,
                        ],
                        [
                            'debit_nominal_id' => (int)$fixture['asset_nominal_id'],
                            'credit_nominal_id' => (int)$fixture['expense_nominal_id'],
                            'amount_pence' => 1000,
                            'journal_date' => '2025-06-30',
                            'review_id' => 32,
                            'schedule_id' => 42,
                        ],
                    ]
                );

                $buckets = (array)($mapping['buckets'] ?? []);
                $sources = (array)($mapping['sources'] ?? []);
                $expected = [
                    'turnover' => 1200.0,
                    'other_income' => 40.0,
                    'raw_materials_consumables' => 225.0,
                    'staff_costs' => 80.0,
                    'depreciation_write_offs' => 80.0,
                    'other_charges' => 220.0,
                    'cost_of_sales' => 275.0,
                    'gross_profit_loss' => 965.0,
                    'administrative_expenses' => 330.0,
                    'expenses' => 605.0,
                    'profit_loss_before_tax' => 635.0,
                    'tax_on_profit' => 123.0,
                    'profit_loss' => 512.0,
                ];
                foreach ($expected as $bucket => $amount) {
                    $harness->assertSame(number_format($amount, 2, '.', ''), number_format((float)($buckets[$bucket] ?? 0), 2, '.', ''));
                    $harness->assertSame(
                        number_format($amount, 2, '.', ''),
                        number_format(ixbrlMappingSourceTotal((array)($sources[$bucket] ?? [])), 2, '.', '')
                    );
                }

                $adminSources = (array)($sources['administrative_expenses'] ?? []);
                $costSources = (array)($sources['cost_of_sales'] ?? []);
                $taxSources = (array)($sources['tax_on_profit'] ?? []);
                $harness->assertSame(0, count(array_filter(
                    $adminSources,
                    static fn(array $row): bool => (string)($row['source_type'] ?? '') === 'posted_corporation_tax'
                        || (int)($row['nominal_account_id'] ?? 0) === $taxNominalId
                )));
                $harness->assertSame(1, count(ixbrlMappingSourcesOfType($taxSources, 'posted_corporation_tax')));
                $harness->assertSame(1, count(ixbrlMappingSourcesOfType($costSources, 'pending_prepayment')));
                $harness->assertSame('25.00', number_format(ixbrlMappingSourceTotal(ixbrlMappingSourcesOfType($costSources, 'pending_prepayment')), 2, '.', ''));
                $harness->assertSame(1, count(ixbrlMappingSourcesOfType($adminSources, 'pending_prepayment')));
                $harness->assertSame('-10.00', number_format(ixbrlMappingSourceTotal(ixbrlMappingSourcesOfType($adminSources, 'pending_prepayment')), 2, '.', ''));
                $harness->assertSame(1, count(ixbrlMappingSourcesOfType($adminSources, 'pending_depreciation')));
                $harness->assertSame('60.00', number_format(ixbrlMappingSourceTotal(ixbrlMappingSourcesOfType($adminSources, 'pending_depreciation')), 2, '.', ''));
                $harness->assertSame(4, count(ixbrlMappingSourcesOfType((array)($sources['expenses'] ?? []), 'formula')));
                $harness->assertSame(3, count(ixbrlMappingSourcesOfType((array)($sources['gross_profit_loss'] ?? []), 'formula')));
                $harness->assertSame(2, count(ixbrlMappingSourcesOfType((array)($sources['profit_loss_before_tax'] ?? []), 'formula')));
                $harness->assertSame(7, count(ixbrlMappingSourcesOfType((array)($sources['profit_loss'] ?? []), 'formula')));
                $harness->assertSame(1, count(array_filter(
                    (array)($sources['staff_costs'] ?? []),
                    static fn(array $row): bool => (int)($row['nominal_account_id'] ?? 0) === $wagesNominalId
                )));
                $harness->assertSame(0, count(array_filter(
                    (array)($sources['staff_costs'] ?? []),
                    static fn(array $row): bool => (int)($row['nominal_account_id'] ?? 0) === $staffWelfareNominalId
                )));
                $harness->assertSame(1, count(array_filter(
                    (array)($sources['other_charges'] ?? []),
                    static fn(array $row): bool => (int)($row['nominal_account_id'] ?? 0) === $staffWelfareNominalId
                )));
                $harness->assertSame(0, count(array_filter(
                    (array)($sources['raw_materials_consumables'] ?? []),
                    static fn(array $row): bool => (int)($row['nominal_account_id'] ?? 0) === $subcontractorNominalId
                )));
                $harness->assertSame(1, count(array_filter(
                    (array)($sources['other_charges'] ?? []),
                    static fn(array $row): bool => (int)($row['nominal_account_id'] ?? 0) === $subcontractorNominalId
                )));
                $harness->assertSame(2, count(array_filter(
                    (array)($sources['raw_materials_consumables'] ?? []),
                    static fn(array $row): bool => (int)($row['nominal_account_id'] ?? 0) === (int)$fixture['cost_nominal_id']
                )));
                $harness->assertSame(1, count(array_filter(
                    (array)($sources['depreciation_write_offs'] ?? []),
                    static fn(array $row): bool => (int)($row['nominal_account_id'] ?? 0) === $manualDepreciationNominalId
                )));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);

/** @param list<array<string, mixed>> $rows */
function ixbrlMappingSourceTotal(array $rows): float
{
    return round(array_sum(array_map(
        static fn(array $row): float => (float)($row['amount'] ?? 0),
        $rows
    )), 2);
}

/** @param list<array<string, mixed>> $rows
 *  @return list<array<string, mixed>>
 */
function ixbrlMappingSourcesOfType(array $rows, string $sourceType): array
{
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => (string)($row['source_type'] ?? '') === $sourceType
    ));
}
