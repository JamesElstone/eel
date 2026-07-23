<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\TransmissionArchiveService::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $h->check(
            \eel_accounts\Service\TransmissionArchiveService::class,
            'stores immutable exact bytes in the company authority environment reference hierarchy',
            static function () use ($h): void {
                $companyId = 98731;
                $periodId = 98732;
                $root = test_tmp_directory() . DIRECTORY_SEPARATOR . 'transmission-' . bin2hex(random_bytes(4));
                try {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                         VALUES (:id, :name, :number, 1, :created_at)',
                        [
                            'id' => $companyId,
                            'name' => 'Transmission Archive Test Limited',
                            'number' => '09873100',
                            'created_at' => '2026-07-23 10:00:00',
                        ]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end, created_at)
                         VALUES (:id, :company_id, :label, :start, :end, :created_at)',
                        [
                            'id' => $periodId,
                            'company_id' => $companyId,
                            'label' => 'ARCHIVE-98732',
                            'start' => '2025-10-01',
                            'end' => '2026-09-30',
                            'created_at' => '2026-07-23 10:00:00',
                        ]
                    );
                    $service = new \eel_accounts\Service\TransmissionArchiveService($root);
                    $request = '<GovTalkMessage>exact request</GovTalkMessage>';
                    $stored = $service->store(
                        $companyId,
                        $periodId,
                        'companies_house',
                        'TEST',
                        '000001',
                        'submitting',
                        'submission-request.xml',
                        $request
                    );
                    $expected = $root . DIRECTORY_SEPARATOR . '09873100'
                        . DIRECTORY_SEPARATOR . 'companies_house'
                        . DIRECTORY_SEPARATOR . 'test'
                        . DIRECTORY_SEPARATOR . '000001';
                    $h->assertSame($expected . DIRECTORY_SEPARATOR . 'submission-request.xml', $stored['path']);
                    $h->assertSame($request, (string)file_get_contents($stored['path']));
                    $h->assertTrue(is_file($expected . DIRECTORY_SEPARATOR . 'manifest.json'));

                    $service->store(
                        $companyId,
                        $periodId,
                        'companies_house',
                        'TEST',
                        '000001',
                        'pending',
                        'submission-response.xml',
                        '<GovTalkMessage>acknowledged</GovTalkMessage>'
                    );
                    $archive = $service->find(
                        $companyId,
                        'companies_house',
                        'TEST',
                        '000001'
                    );
                    $h->assertSame(
                        $expected . DIRECTORY_SEPARATOR . 'submission-request.xml',
                        (string)($archive['request_path'] ?? '')
                    );
                    $h->assertSame(hash('sha256', $request), (string)($archive['request_sha256'] ?? ''));
                    $h->assertSame(
                        $expected . DIRECTORY_SEPARATOR . 'submission-response.xml',
                        (string)($archive['response_path'] ?? '')
                    );
                    $h->assertSame(
                        hash('sha256', '<GovTalkMessage>acknowledged</GovTalkMessage>'),
                        (string)($archive['response_sha256'] ?? '')
                    );
                    $manifest = json_decode(
                        (string)file_get_contents($expected . DIRECTORY_SEPARATOR . 'manifest.json'),
                        true
                    );
                    $h->assertSame('pending', (string)$manifest['lifecycle']);
                    $h->assertSame(2, count((array)$manifest['files']));
                    $h->assertSame(
                        hash('sha256', $request),
                        (string)$manifest['files'][0]['sha256']
                    );

                    $immutable = false;
                    try {
                        $service->store(
                            $companyId,
                            $periodId,
                            'companies_house',
                            'TEST',
                            '000001',
                            'pending',
                            'submission-request.xml',
                            '<different/>'
                        );
                    } catch (RuntimeException $exception) {
                        $immutable = str_contains($exception->getMessage(), 'immutable');
                    }
                    $h->assertTrue($immutable);
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );
    }
);
