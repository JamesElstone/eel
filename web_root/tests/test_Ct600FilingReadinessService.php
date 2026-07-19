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
    \eel_accounts\Service\Ct600FilingReadinessService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(\eel_accounts\Service\Ct600FilingReadinessService::class, 'requires filing-ready accounts and every CT-period computation', static function () use ($harness): void {
            $service = new \eel_accounts\Service\Ct600FilingReadinessService(
                static fn(string $start, string $end): array => [
                    'ok' => true,
                    'form_version' => 'V3',
                    'artifact_version' => 'V1.994',
                    'errors' => [],
                ],
                static fn(int $companyId, int $accountingPeriodId): array => [
                    'ok' => true,
                    'state' => 'ready',
                    'run_id' => 81,
                    'errors' => [],
                ],
                static fn(string $mode): array => ['ok' => true, 'errors' => []],
                static fn(int $companyId, int $ctPeriodId): array => [
                    'ok' => true, 'state' => 'ready', 'run_id' => 82, 'errors' => [],
                ]
            );

            $summary = $service->fetch(
                49,
                79,
                [[
                    'ct_period_id' => 6,
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-04',
                ]],
                ['company_number' => '12345678'],
                ['utr' => '1234567890']
            );

            $harness->assertSame(true, (bool)($summary['rim']['ready'] ?? false));
            $harness->assertSame(true, (bool)($summary['identity']['ready'] ?? false));
            $harness->assertSame(true, (bool)($summary['ixbrl']['accounts_ready'] ?? false));
            $harness->assertSame(true, (bool)($summary['ixbrl']['computations_ready'] ?? false));
            $harness->assertSame(true, (bool)($summary['ixbrl']['ready'] ?? false));
            $harness->assertSame(false, (bool)($summary['attachments']['ready'] ?? true));
            $harness->assertSame(false, (bool)($summary['approval_transport']['ready'] ?? true));
            $harness->assertSame(true, (bool)($summary['approval_transport']['credentials_ready'] ?? false));
        });

        $harness->check(\eel_accounts\Service\Ct600FilingReadinessService::class, 'reports missing identity and RIM data as filing readiness detail', static function () use ($harness): void {
            $service = new \eel_accounts\Service\Ct600FilingReadinessService(
                static fn(string $start, string $end): array => ['ok' => false, 'errors' => ['No live package.']],
                static fn(int $companyId, int $accountingPeriodId): array => ['ok' => false, 'errors' => ['No accounts artifact.']],
                static fn(string $mode): array => ['ok' => false, 'errors' => ['No credentials.']]
            );

            $summary = $service->fetch(
                1,
                2,
                [['ct_period_id' => 3, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']],
                ['company_number' => ''],
                ['utr' => '']
            );

            $harness->assertSame(false, (bool)($summary['rim']['ready'] ?? true));
            $harness->assertSame('No live package.', (string)($summary['rim']['detail'] ?? ''));
            $harness->assertSame(false, (bool)($summary['identity']['ready'] ?? true));
            $harness->assertTrue(str_contains((string)($summary['identity']['detail'] ?? ''), 'company number'));
            $harness->assertTrue(str_contains((string)($summary['identity']['detail'] ?? ''), 'Corporation Tax UTR'));
        });

        $harness->check(\eel_accounts\Service\Ct600FilingReadinessService::class, 'blocks a two-period return when either computation is not fileable', static function () use ($harness): void {
            $service = new \eel_accounts\Service\Ct600FilingReadinessService(
                static fn(string $start, string $end): array => ['ok' => true, 'errors' => []],
                static fn(int $companyId, int $accountingPeriodId): array => ['ok' => true, 'errors' => []],
                static fn(string $mode): array => ['ok' => true, 'errors' => []],
                static fn(int $companyId, int $ctPeriodId): array => $ctPeriodId === 7
                    ? ['ok' => false, 'state' => 'not_ready', 'errors' => ['External validation hash mismatch.']]
                    : ['ok' => true, 'state' => 'ready', 'run_id' => 90, 'errors' => []]
            );
            $summary = $service->fetch(
                49,
                79,
                [
                    ['id' => 6, 'period_start' => '2023-01-01', 'period_end' => '2023-10-31'],
                    ['id' => 7, 'period_start' => '2023-11-01', 'period_end' => '2023-12-31'],
                ],
                ['company_number' => '12345678'],
                ['utr' => '1234567890']
            );
            $harness->assertSame(false, (bool)$summary['ixbrl']['computations_ready']);
            $harness->assertSame(2, count((array)$summary['ixbrl']['computations']));
            $harness->assertTrue(str_contains((string)$summary['ixbrl']['detail'], 'External validation hash mismatch'));
        });
    }
);
