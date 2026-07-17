<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlReadinessService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlReadinessService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlReadinessService::class, 'blocks generation when company and period are missing', static function () use ($harness, $service): void {
            $readiness = $service->getReadiness(0, 0);
            $harness->assertSame(false, $readiness['can_build_facts']);
            $harness->assertSame(false, $readiness['can_generate']);
            $harness->assertSame(false, $readiness['can_validate']);
            $harness->assertSame(false, $readiness['ready_for_filing']);
            $harness->assertTrue(count($readiness['blocking_errors']) > 0);
        });

        $harness->check(\eel_accounts\Service\IxbrlReadinessService::class, 'keeps filing-only failures out of the fact-build gate', static function () use ($harness, $service): void {
            $addCheck = new ReflectionMethod(\eel_accounts\Service\IxbrlReadinessService::class, 'addCheck');
            $addCheck->setAccessible(true);
            $forStage = new ReflectionMethod(\eel_accounts\Service\IxbrlReadinessService::class, 'incompleteForStage');
            $forStage->setAccessible(true);
            $checks = [];

            $arguments = [&$checks, 'external', 'Arelle', false, ['filing'], 'Arelle failed.'];
            $addCheck->invokeArgs($service, $arguments);

            $harness->assertSame([], $forStage->invoke($service, $checks, 'build'));
            $harness->assertCount(1, $forStage->invoke($service, $checks, 'filing'));
            $harness->assertSame('Filing blocked', (string)($checks[0]['status_label'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\IxbrlReadinessService::class, 'treats the Year End lock as a fact-build prerequisite', static function () use ($harness, $service): void {
            $addCheck = new ReflectionMethod(\eel_accounts\Service\IxbrlReadinessService::class, 'addCheck');
            $addCheck->setAccessible(true);
            $forStage = new ReflectionMethod(\eel_accounts\Service\IxbrlReadinessService::class, 'incompleteForStage');
            $forStage->setAccessible(true);
            $checks = [];

            $arguments = [&$checks, 'year_end_locked', 'Year End finalised', false, ['build', 'generate', 'filing'], 'Complete and lock Year End.'];
            $addCheck->invokeArgs($service, $arguments);

            $harness->assertCount(1, $forStage->invoke($service, $checks, 'build'));
            $harness->assertSame('Build blocked', (string)($checks[0]['status_label'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\IxbrlReadinessService::class, 'requires every statutory profile fact before generation', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlReadinessService::class, 'requiredProfileFactKeys');
            $method->setAccessible(true);
            $keys = $method->invoke($service);

            $profileKeys = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                if (!empty($mapping['is_active']) && !empty($mapping['is_required'])) {
                    $profileKeys[] = (string)$mapping['fact_key'];
                }
            }
            $harness->assertSame(array_values(array_unique($profileKeys)), $keys);

            foreach ([
                'accounts_approval_date',
                'approving_director_name',
                'average_number_employees',
                'entity_dormant',
                'entity_trading_status',
                'director_signing_financial_statements',
                'accounting_standards_applied',
                'accounts_status',
                'small_companies_regime_statement',
                'audit_exemption_statement',
                'directors_responsibility_statement',
                'members_no_audit_statement',
            ] as $required) {
                $harness->assertTrue(in_array($required, $keys, true));
            }
        });

        $harness->check(\eel_accounts\Service\IxbrlReadinessService::class, 'requires comparative-enabled facts when a prior locked period exists', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlReadinessService::class, 'missingRequiredProfileFacts');
            $method->setAccessible(true);
            $missing = $method->invoke($service, 0, true);
            $harness->assertTrue(in_array('comparative:turnover', $missing, true));
            $harness->assertTrue(in_array('comparative:average_number_employees', $missing, true));
            $harness->assertFalse(in_array('comparative:entity_name', $missing, true));
        });

        $harness->check(\eel_accounts\Service\IxbrlReadinessService::class, 'requires both director loan nominal settings', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlReadinessService::class, 'missingSettings');
            $method->setAccessible(true);

            $missing = $method->invoke($service, [
                'utr' => '1234567890',
                'default_currency' => 'GBP',
                'default_bank_nominal_id' => '10',
                'director_loan_nominal_id' => '30',
                'vat_nominal_id' => '40',
            ]);
            $harness->assertTrue(in_array('director loan asset nominal', $missing, true));
            $harness->assertTrue(in_array('director loan liability nominal', $missing, true));

            $complete = $method->invoke($service, [
                'utr' => '1234567890',
                'default_currency' => 'GBP',
                'default_bank_nominal_id' => '10',
                'director_loan_asset_nominal_id' => '30',
                'director_loan_liability_nominal_id' => '31',
                'vat_nominal_id' => '40',
            ]);
            $harness->assertFalse(in_array('director loan asset nominal', $complete, true));
            $harness->assertFalse(in_array('director loan liability nominal', $complete, true));
        });

        $harness->check(\eel_accounts\Service\IxbrlReadinessService::class, 'surfaces deferred tax as a non-blocking FRS 105 warning', static function () use ($harness, $service): void {
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

        $harness->check(\eel_accounts\Service\IxbrlReadinessService::class, 'passes the deferred tax readiness check when the fixture nominal is inactive', static function () use ($harness, $service): void {
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
