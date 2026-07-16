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
$harness->run(\eel_accounts\Service\CompanyDirectorService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\CompanyDirectorService $service
): void {
    $harness->check(\eel_accounts\Service\CompanyDirectorService::class, 'parses active and former Companies House directors without secretaries', static function () use ($harness, $service): void {
        $directors = $service->previewFromStoredOfficersJson(json_encode([
            'items' => [
                [
                    'name' => 'James Example',
                    'officer_role' => 'director',
                    'appointed_on' => '2020-01-01',
                    'links' => ['officer' => ['appointments' => '/officers/abc123/appointments']],
                ],
                [
                    'name' => 'Brian Example',
                    'officer_role' => 'director',
                    'appointed_on' => '2018-01-01',
                    'resigned_on' => '2021-12-31',
                ],
                ['name' => 'Company Secretary', 'officer_role' => 'secretary'],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $harness->assertCount(2, (array)$directors);
        $harness->assertSame('officer:abc123', (string)($directors[0]['external_key'] ?? ''));
        $harness->assertSame(1, (int)($directors[0]['is_active'] ?? 0));
        $harness->assertSame(0, (int)($directors[1]['is_active'] ?? 1));
    });

    $harness->check(\eel_accounts\Service\CompanyDirectorService::class, 'synchronises multiple directors idempotently and resolves unique tenure', static function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('company_directors')) {
            $harness->skip('Structured directors schema is not available.');
        }

        InterfaceDB::beginTransaction();
        try {
            $marker = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                ['company_name' => 'Structured Directors Fixture Limited', 'company_number' => 'SDF' . $marker]
            );
            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => 'SDF' . $marker]
            );
            $json = json_encode([
                'items' => [
                    [
                        'name' => 'Brian Example',
                        'officer_role' => 'director',
                        'appointed_on' => '2018-01-01',
                        'resigned_on' => '2021-12-31',
                        'links' => ['officer' => ['appointments' => '/officers/brian/appointments']],
                    ],
                    [
                        'name' => 'James Example',
                        'officer_role' => 'director',
                        'appointed_on' => '2022-01-01',
                        'links' => ['officer' => ['appointments' => '/officers/james/appointments']],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES);

            $first = $service->syncFromStoredOfficersJson($companyId, (string)$json);
            $second = $service->syncFromStoredOfficersJson($companyId, (string)$json);
            $rows = $service->fetchForCompany($companyId);
            $matched = $service->findUniqueForDate($companyId, '2023-06-30');

            $harness->assertSame(true, (bool)($first['success'] ?? false));
            $harness->assertSame(true, (bool)($second['success'] ?? false));
            $harness->assertCount(2, $rows);
            $harness->assertSame('James Example', (string)($matched['full_name'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
