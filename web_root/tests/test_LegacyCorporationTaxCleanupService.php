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
    \eel_accounts\Service\LegacyCorporationTaxCleanupService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\LegacyCorporationTaxCleanupService $service): void {
        $harness->check(\eel_accounts\Service\LegacyCorporationTaxCleanupService::class, 'accepts only local TEST validation artefacts', static function () use ($harness, $service): void {
            $artifact = [
                'mode' => 'TEST',
                'status' => 'validation_failed',
                'validation_json' => '{"ok":false,"mode":"TEST"}',
                'request_headers_json' => '{"X-EEL-HMRC-Mode":"TEST"}',
            ];
            $harness->assertSame(true, $service->isValidationOnlyArtifact($artifact));

            foreach ([
                ['hmrc_submission_reference' => 'ABC123'],
                ['hmrc_correlation_id' => 'correlation'],
                ['hmrc_response_code' => '200'],
                ['submitted_at' => '2026-07-14 12:00:00'],
                ['status' => 'accepted'],
                ['mode' => 'LIVE'],
            ] as $unsafeChange) {
                $harness->assertSame(false, $service->isValidationOnlyArtifact(array_replace($artifact, $unsafeChange)));
            }

            $harness->assertSame(true, $service->isValidationOnlyEvent([
                'event_message' => 'Submission draft created.',
                'event_context_json' => '{"mode":"TEST"}',
            ]));
            $harness->assertSame(true, $service->isValidationOnlyEvent([
                'event_message' => 'Package validation failed.',
                'event_context_json' => '{"mode":"TEST","ok":false}',
            ]));
            $harness->assertSame(false, $service->isValidationOnlyEvent([
                'event_message' => 'Submission accepted.',
                'event_context_json' => '{"mode":"TEST"}',
            ]));
            $harness->assertSame(false, $service->isValidationOnlyEvent([
                'event_message' => 'Package validation failed.',
                'event_context_json' => '{"mode":"LIVE"}',
            ]));
        });

        $harness->check(\eel_accounts\Service\LegacyCorporationTaxCleanupService::class, 'accepts only the explicitly authorised legacy tax-loss rows', static function () use ($harness, $service): void {
            $carryforwards = [[
                'id' => 248,
                'company_id' => 49,
                'origin_accounting_period_id' => 79,
                'origin_ct_period_id' => null,
                'amount_originated' => '697.58',
                'amount_used' => '0.00',
                'amount_remaining' => '697.58',
                'status' => 'open',
            ]];
            $expected = [
                1397 => [79, null], 1405 => [79, null], 1406 => [80, null], 1407 => [81, null],
                1408 => [82, null], 1413 => [79, null], 1414 => [80, null], 1415 => [81, null],
                1416 => [82, null], 1421 => [79, null], 1422 => [80, null], 1423 => [81, null],
                1424 => [82, null], 2297 => [79, null], 2301 => [79, null], 2356 => [80, 8],
                2387 => [79, null], 2388 => [80, null], 2389 => [81, null], 2390 => [82, null],
                2432 => [79, 7], 2434 => [79, 6], 2436 => [79, 7],
            ];
            $movements = [];
            foreach ($expected as $id => [$accountingPeriodId, $ctPeriodId]) {
                $movements[] = [
                    'id' => $id,
                    'company_id' => 49,
                    'accounting_period_id' => $accountingPeriodId,
                    'ct_period_id' => $ctPeriodId,
                    'computation_hash' => str_repeat('a', 64),
                ];
            }

            $harness->assertSame(true, $service->isExpectedLegacyLossData($carryforwards, $movements));
            $movements[0]['company_id'] = 50;
            $harness->assertSame(false, $service->isExpectedLegacyLossData($carryforwards, $movements));
        });
    }
);
