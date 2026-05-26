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
    HmrcSubmissionAuthorisationService::class,
    static function (GeneratedServiceClassTestHarness $harness, HmrcSubmissionAuthorisationService $service): void {
        $harness->check(HmrcSubmissionAuthorisationService::class, 'allows non-submission intents', static function () use ($harness, $service): void {
            $result = $service->validate(new RequestFramework([], [], ['REQUEST_METHOD' => 'POST'], [], [], null), 0, 'hmrc_validate_package');

            $harness->assertSame(true, $result['success'] ?? null);
        });

        $harness->check(HmrcSubmissionAuthorisationService::class, 'requires explicit authority confirmation for submissions', static function () use ($harness, $service): void {
            $request = new RequestFramework([], [], ['REQUEST_METHOD' => 'POST'], [], [], null);
            $result = $service->validate($request, 1, 'hmrc_submit_test');

            $harness->assertSame(false, $result['success'] ?? null);
        });
    }
);
