<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    IxbrlReadinessService::class,
    static function (GeneratedServiceClassTestHarness $harness, IxbrlReadinessService $service): void {
        $harness->check(IxbrlReadinessService::class, 'blocks generation when company and period are missing', static function () use ($harness, $service): void {
            $readiness = $service->getReadiness(0, 0);
            $harness->assertSame(false, $readiness['can_build_facts']);
            $harness->assertTrue(count($readiness['blocking_errors']) > 0);
        });

        $harness->check(IxbrlReadinessService::class, 'surfaces deferred tax as a non-blocking FRS 105 warning', static function () use ($harness, $service): void {
            $fixture = ixbrlReadinessDeferredTaxFixture();

            try {
                $readiness = $service->getReadiness((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
                $check = ixbrlReadinessFindCheck((array)($readiness['checks'] ?? []), 'frs105_deferred_tax_nominal');

                $harness->assertSame(false, (bool)($check['complete'] ?? true));
                $harness->assertSame(false, (bool)($check['blocking'] ?? true));
                $harness->assertSame('warning', (string)($check['status'] ?? ''));
                $harness->assertTrue(str_contains((string)($check['detail'] ?? ''), 'FRS 105 prohibits recognising deferred tax'));
            } finally {
                ixbrlReadinessDeactivateNominal((int)$fixture['nominal_id']);
            }
        });

        $harness->check(IxbrlReadinessService::class, 'passes the deferred tax readiness check when the fixture nominal is inactive', static function () use ($harness, $service): void {
            $fixture = ixbrlReadinessDeferredTaxFixture();
            ixbrlReadinessDeactivateNominal((int)$fixture['nominal_id']);
            if (ixbrlReadinessActiveDeferredTaxNominalCount() > 0) {
                $harness->skip('An existing active deferred tax nominal is present in the local database.');
            }

            $readiness = $service->getReadiness((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $check = ixbrlReadinessFindCheck((array)($readiness['checks'] ?? []), 'frs105_deferred_tax_nominal');

            $harness->assertSame(true, (bool)($check['complete'] ?? false));
            $harness->assertSame(false, (bool)($check['blocking'] ?? true));
            $harness->assertSame('success', (string)($check['status'] ?? ''));
        });
    }
);

function ixbrlReadinessDeferredTaxFixture(): array
{
    $suffix = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
    $companyNumber = 'ID' . strtoupper(substr($suffix, 0, 8));

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
        ['company_name' => 'iXBRL Deferred Tax Fixture Limited', 'company_number' => $companyNumber]
    );
    $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => $companyNumber]);

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        ['company_id' => $companyId, 'label' => 'iXBRL Deferred Tax Fixture FY', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
    );
    $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label', ['company_id' => $companyId, 'label' => 'iXBRL Deferred Tax Fixture FY']);

    $nominalCode = '9IDT' . strtoupper(substr($suffix, 0, 4));
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $nominalCode,
            'name' => 'Deferred Tax Fixture ' . $suffix,
            'account_type' => 'liability',
            'tax_treatment' => 'allowable',
            'sort_order' => 9900,
        ]
    );
    $nominalId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code', ['code' => $nominalCode]);

    return ['company_id' => $companyId, 'accounting_period_id' => $periodId, 'nominal_id' => $nominalId];
}

function ixbrlReadinessDeactivateNominal(int $nominalId): void
{
    InterfaceDB::prepareExecute(
        'UPDATE nominal_accounts SET is_active = 0 WHERE id = :id',
        ['id' => $nominalId]
    );
}

function ixbrlReadinessFindCheck(array $checks, string $key): array
{
    foreach ($checks as $check) {
        if ((string)($check['key'] ?? '') === $key) {
            return (array)$check;
        }
    }

    throw new RuntimeException('Check was not found: ' . $key);
}

function ixbrlReadinessActiveDeferredTaxNominalCount(): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM nominal_accounts
         WHERE COALESCE(is_active, 0) = 1
           AND (
                LOWER(COALESCE(name, \'\')) LIKE :name_pattern
                OR LOWER(COALESCE(name, \'\')) LIKE :reverse_name_pattern
                OR LOWER(COALESCE(code, \'\')) LIKE :code_pattern
                OR LOWER(COALESCE(code, \'\')) LIKE :reverse_code_pattern
           )',
        [
            'name_pattern' => '%deferred%tax%',
            'reverse_name_pattern' => '%tax%deferred%',
            'code_pattern' => '%deferred%tax%',
            'reverse_code_pattern' => '%tax%deferred%',
        ]
    );
}
