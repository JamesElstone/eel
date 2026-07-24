<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CorporationTaxFilingScopeService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CorporationTaxFilingScopeService $service): void {
        $h->check($service::class, 'defines every unsupported CT600 supplementary page with official guidance', static function () use ($h, $service): void {
            $definitions = $service->definitions();
            $h->assertSame(['ct600b','ct600c','ct600d','ct600e','ct600f','ct600g','ct600h','ct600i','ct600j','ct600k','ct600l','ct600m','ct600n','ct600p'], array_keys($definitions));
            foreach ($definitions as $definition) {
                $h->assertTrue(str_starts_with((string)$definition['url'], 'https://www.gov.uk/'));
                $h->assertTrue(str_starts_with((string)$definition['page'], 'CT600'));
            }
        });

        $h->check($service::class, 'fails closed without an accounting context', static function () use ($h, $service): void {
            $status = $service->fetch(0, 0);
            $h->assertSame(false, (bool)$status['available']);
            $h->assertSame(false, (bool)$status['complete']);
        });

        $h->check($service::class, 'requires every current-version answer and keeps a Yes as a review error', static function () use ($h, $service): void {
            if (!\InterfaceDB::tableExists('corporation_tax_scope_confirmations')) {
                $h->skip('Corporation Tax filing-scope storage is unavailable.');
            }

            \InterfaceDB::beginTransaction();
            try {
                $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
                \InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number, incorporation_date) VALUES (:name, :number, :date)',
                    ['name' => 'Scope Fixture ' . $marker, 'number' => 'SC' . $marker, 'date' => '2025-01-01']
                );
                $companyId = (int)\InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => 'SC' . $marker]);
                \InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :start, :end)',
                    ['company_id' => $companyId, 'label' => 'Scope ' . $marker, 'start' => '2025-01-01', 'end' => '2025-12-31']
                );
                $periodId = (int)\InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id', ['company_id' => $companyId]);

                $fresh = $service->fetch($companyId, $periodId);
                $h->assertSame(false, (bool)$fresh['complete']);
                $h->assertSame('', (string)$fresh['answers']['ct600b']);
                $h->assertTrue(!empty($fresh['unanswered_fields']));

                $h->assertTrue(!empty($service->saveAnswer($companyId, $periodId, 'ct600b', 'yes', 'test')['success']));
                $yes = $service->fetch($companyId, $periodId);
                $h->assertSame(false, (bool)$yes['complete']);
                $h->assertTrue(str_contains(implode(' ', (array)$yes['errors']), 'CT600B may be required'));

                foreach (array_keys($service->definitions()) as $field) {
                    $h->assertTrue(!empty($service->saveAnswer($companyId, $periodId, $field, 'no', 'test')['success']));
                }
                $complete = $service->fetch($companyId, $periodId);
                $h->assertSame(true, (bool)$complete['complete']);
                $h->assertSame('no', (string)$complete['answers']['ct600b']);
            } finally {
                if (\InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
            }
        });
    }
);
