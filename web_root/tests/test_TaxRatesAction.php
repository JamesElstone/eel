<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(TaxRatesAction::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(TaxRatesAction::class, 'refreshes both HMRC CT artefact families', static function () use ($harness): void {
        $rimCalls = 0;
        $computationCalls = 0;
        $action = new TaxRatesAction(
            static function () use (&$rimCalls): array {
                $rimCalls++;
                return [
                    'success' => true,
                    'updated_count' => 3,
                    'downloaded_count' => 1,
                    'expanded_count' => 2,
                    'errors' => [],
                ];
            },
            static function () use (&$computationCalls): array {
                $computationCalls++;
                return [
                    'success' => true,
                    'already_installed' => false,
                    'package_id' => 12,
                    'profile_id' => 34,
                    'file_count' => 21,
                    'concept_count' => 1757,
                    'errors' => [],
                ];
            },
        );

        $result = $action->handle(
            taxRatesCtArtifactActionRequest('hmrc_ct_artifacts_refresh'),
            createTestPageServiceFramework(),
        );

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(1, $rimCalls);
        $harness->assertSame(1, $computationCalls);
        $harness->assertSame([
            'hmrc_ct_rim.refresh',
            'hmrc_ct_rim.state',
            'hmrc.ct.computation.taxonomy',
            'ct.filing.mappings',
            'page.context',
        ], $result->changedFacts());

        $messages = taxRatesCtArtifactMessages($result);
        $harness->assertTrue(str_contains($messages, '3 package(s) checked'));
        $harness->assertTrue(str_contains($messages, '21 file(s) and 1757 concept(s)'));
        $harness->assertTrue(str_contains($messages, 'mapping profile #34 prepared'));
    });

    $harness->check(TaxRatesAction::class, 'runs computation installation after a RIM failure', static function () use ($harness): void {
        $rimCalls = 0;
        $computationCalls = 0;
        $action = new TaxRatesAction(
            static function () use (&$rimCalls): array {
                $rimCalls++;
                throw new RuntimeException('RIM catalogue endpoint unavailable.');
            },
            static function () use (&$computationCalls): array {
                $computationCalls++;
                return [
                    'success' => true,
                    'already_installed' => true,
                    'package_id' => 12,
                    'profile_id' => 34,
                    'file_count' => 21,
                    'concept_count' => 1757,
                    'errors' => [],
                ];
            },
        );

        $result = $action->handle(
            taxRatesCtArtifactActionRequest('hmrc_ct_artifacts_refresh'),
            createTestPageServiceFramework(),
        );

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame(1, $rimCalls);
        $harness->assertSame(1, $computationCalls);
        $messages = taxRatesCtArtifactMessages($result);
        $harness->assertTrue(str_contains($messages, 'RIM catalogue endpoint unavailable'));
        $harness->assertTrue(str_contains($messages, 'already installed and verified'));
    });

    $harness->check(TaxRatesAction::class, 'fails after a successful RIM refresh when computation installation fails', static function () use ($harness): void {
        $action = new TaxRatesAction(
            static fn(): array => [
                'success' => true,
                'updated_count' => 3,
                'downloaded_count' => 0,
                'expanded_count' => 0,
                'errors' => [],
            ],
            static fn(): array => [
                'success' => false,
                'already_installed' => false,
                'package_id' => 0,
                'profile_id' => 0,
                'file_count' => 0,
                'concept_count' => 0,
                'errors' => ['CT2024 manifest identity did not match.'],
            ],
        );

        $result = $action->handle(
            taxRatesCtArtifactActionRequest('hmrc_ct_artifacts_refresh'),
            createTestPageServiceFramework(),
        );

        $harness->assertSame(false, $result->isSuccess());
        $messages = taxRatesCtArtifactMessages($result);
        $harness->assertTrue(str_contains($messages, '3 package(s) checked'));
        $harness->assertTrue(str_contains($messages, 'CT2024 manifest identity did not match'));
    });

    $harness->check(TaxRatesAction::class, 'retains the old RIM intent as a unified compatibility alias', static function () use ($harness): void {
        $computationCalls = 0;
        $action = new TaxRatesAction(
            static fn(): array => [
                'success' => true,
                'updated_count' => 1,
                'downloaded_count' => 0,
                'expanded_count' => 0,
                'errors' => [],
            ],
            static function () use (&$computationCalls): array {
                $computationCalls++;
                return ['success' => true];
            },
        );

        $result = $action->handle(
            taxRatesCtArtifactActionRequest('hmrc_ct_rim_refresh'),
            createTestPageServiceFramework(),
        );

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(1, $computationCalls);
        $harness->assertSame([
            'hmrc_ct_rim.refresh',
            'hmrc_ct_rim.state',
            'hmrc.ct.computation.taxonomy',
            'ct.filing.mappings',
            'page.context',
        ], $result->changedFacts());
    });

    $harness->check(TaxRatesAction::class, 'deletes a computation package through the dedicated confirmed action', static function () use ($harness): void {
        $deletedPackageId = 0;
        $action = new TaxRatesAction(
            null,
            null,
            static function (int $packageId) use (&$deletedPackageId): array {
                $deletedPackageId = $packageId;
                return ['success' => true, 'package_id' => $packageId];
            },
        );

        $result = $action->handle(
            taxRatesCtArtifactActionRequest('hmrc_ct_computation_delete', 24),
            createTestPageServiceFramework(),
        );

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(24, $deletedPackageId);
        $harness->assertSame(['hmrc.ct.computation.taxonomy', 'ct.filing.mappings', 'page.context'], $result->changedFacts());
        $harness->assertTrue(str_contains(taxRatesCtArtifactMessages($result), 'local files deleted'));
    });
});

function taxRatesCtArtifactActionRequest(string $intent, int $packageId = 0): RequestFramework
{
    return new RequestFramework([], ['intent' => $intent, 'package_id' => $packageId], ['REQUEST_METHOD' => 'POST'], [], []);
}

function taxRatesCtArtifactMessages(ActionResultFramework $result): string
{
    return implode("\n", array_map(
        static fn(array $message): string => (string)($message['message'] ?? ''),
        $result->flashMessages(),
    ));
}
