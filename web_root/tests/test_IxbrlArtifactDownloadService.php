<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlArtifactDownloadService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlArtifactDownloadService $service
    ): void {
        $invoke = static function (string $method, mixed ...$arguments) use ($service): mixed {
            $reflection = new ReflectionMethod($service, $method);
            $reflection->setAccessible(true);
            return $reflection->invoke($service, ...$arguments);
        };

        $approvalJson = '{"scope":"approved"}';
        $approvalHash = hash('sha256', $approvalJson);
        $accountsRow = [
            'is_locked' => 1,
            'year_end_review_id' => 7,
            'current_review_id' => 7,
            'year_end_locked_at' => '2026-07-27 10:00:00',
            'current_locked_at' => '2026-07-27 10:00:00',
            'approval_hash' => $approvalHash,
            'approval_json' => $approvalJson,
            'filing_approval_id' => 11,
            'approval_id' => 11,
            'filing_approval_hash' => $approvalHash,
            'status' => 'generated',
            'validation_status' => 'passed',
            'external_validation_status' => 'passed',
        ];

        $harness->check(
            \eel_accounts\Service\IxbrlArtifactDownloadService::class,
            'accepts current persisted Accounting iXBRL state without rebuilding readiness',
            static function () use ($harness, $invoke, $accountsRow): void {
                $harness->assertSame(null, $invoke('accountsRowError', $accountsRow));
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlArtifactDownloadService::class,
            'rejects Accounting iXBRL from an earlier lock',
            static function () use ($harness, $invoke, $accountsRow): void {
                $row = array_replace($accountsRow, ['current_locked_at' => '2026-07-27 11:00:00']);
                $harness->assertSame(
                    'Year End has changed since this Accounting iXBRL was approved.',
                    $invoke('accountsRowError', $row)
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlArtifactDownloadService::class,
            'requires Companies House artifact to reference current Accounting run',
            static function () use ($harness, $invoke): void {
                $harness->assertSame(
                    null,
                    $invoke('companiesHouseRowError', ['ixbrl_generation_run_id' => 42, 'lifecycle' => 'prepared'], 42)
                );
                $harness->assertSame(
                    'This Companies House iXBRL belongs to an earlier Accounting iXBRL run.',
                    $invoke('companiesHouseRowError', ['ixbrl_generation_run_id' => 41, 'lifecycle' => 'prepared'], 42)
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlArtifactDownloadService::class,
            'accepts only fully validated current Corporation Tax artifact state',
            static function () use ($harness, $invoke, $approvalJson, $approvalHash): void {
                $row = [
                    'is_locked' => 1,
                    'year_end_review_id' => 7,
                    'current_review_id' => 7,
                    'year_end_locked_at' => '2026-07-27 10:00:00',
                    'current_locked_at' => '2026-07-27 10:00:00',
                    'approval_hash' => $approvalHash,
                    'approval_json' => $approvalJson,
                    'filing_basis_hash' => 'basis-hash',
                    'approved_basis_hash' => 'basis-hash',
                    'status' => 'generated',
                    'ixbrl_status' => 'validated',
                    'validation_status' => 'passed',
                    'external_validation_status' => 'passed',
                ];
                $harness->assertSame(null, $invoke('computationRowError', $row));
                $harness->assertSame(
                    'The Corporation Tax iXBRL artifact is not current and fully validated.',
                    $invoke('computationRowError', array_replace($row, ['filing_basis_hash' => 'old-basis']))
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlArtifactDownloadService::class,
            'checks the stored validation hashes against the download file',
            static function () use ($harness, $invoke): void {
                $path = tempnam(test_tmp_directory(), 'ixbrl-download-');
                if ($path === false) {
                    throw new RuntimeException('Unable to create test artifact.');
                }
                file_put_contents($path, '<html>validated</html>');
                try {
                    $hash = hash_file('sha256', $path);
                    $ready = $invoke('verifiedFile', [
                        'id' => 42,
                        'generated_path' => $path,
                        'generated_filename' => 'accounts.html',
                        'output_sha256' => $hash,
                        'external_validated_sha256' => $hash,
                    ], 'generated_path', 'generated_filename', 'output_sha256', 'external_validated_sha256');
                    $harness->assertSame(true, (bool)($ready['ok'] ?? false));
                    $harness->assertSame('accounts.html', (string)($ready['filename'] ?? ''));

                    file_put_contents($path, '<html>changed</html>');
                    $tampered = $invoke('verifiedFile', [
                        'generated_path' => $path,
                        'generated_filename' => 'accounts.html',
                        'output_sha256' => $hash,
                        'external_validated_sha256' => $hash,
                    ], 'generated_path', 'generated_filename', 'output_sha256', 'external_validated_sha256');
                    $harness->assertSame('tampered', (string)($tampered['state'] ?? ''));
                } finally {
                    @unlink($path);
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlArtifactDownloadService::class,
            'download actions use the lightweight locator',
            static function () use ($harness): void {
                $ixbrlAction = file_get_contents(
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'actions'
                    . DIRECTORY_SEPARATOR . 'IxbrlAction.php'
                );
                $companiesHouseAction = file_get_contents(
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'actions'
                    . DIRECTORY_SEPARATOR . 'CompaniesHouseAccountsAction.php'
                );
                $harness->assertSame(true, str_contains((string)$ixbrlAction, 'IxbrlArtifactDownloadService'));
                $harness->assertSame(true, str_contains((string)$companiesHouseAction, 'IxbrlArtifactDownloadService'));
            }
        );
    }
);
