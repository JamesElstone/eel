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
$harness->run(\eel_accounts\Service\DirectorLoanSubledgerBackfillService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanSubledgerBackfillService $service
): void {
    $harness->check(\eel_accounts\Service\DirectorLoanSubledgerBackfillService::class, 'only chooses a director when attribution is deterministic', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'deterministicDirector');
        $method->setAccessible(true);
        $directors = [
            ['id' => 1, 'appointed_on' => '2018-01-01', 'resigned_on' => '2021-12-31'],
            ['id' => 2, 'appointed_on' => '2022-01-01', 'resigned_on' => ''],
        ];

        $historic = $method->invoke($service, $directors, '2020-06-30');
        $current = $method->invoke($service, $directors, '2025-06-30');
        $ambiguous = $method->invoke($service, [
            ['id' => 1, 'appointed_on' => '2018-01-01', 'resigned_on' => ''],
            ['id' => 2, 'appointed_on' => '2020-01-01', 'resigned_on' => ''],
        ], '2025-06-30');

        $harness->assertSame(1, (int)($historic['id'] ?? 0));
        $harness->assertSame(2, (int)($current['id'] ?? 0));
        $harness->assertSame(null, $ambiguous);
    });

    $harness->check(\eel_accounts\Service\DirectorLoanSubledgerBackfillService::class, 'defaults to a non-mutating dry run', static function () use ($harness): void {
        $source = (string)file_get_contents(APP_CLASSES . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'DirectorLoanSubledgerBackfillService.php');
        $harness->assertTrue(str_contains($source, 'public function run(bool $apply = false)'));
        $harness->assertTrue(str_contains($source, "'mode' => \$apply ? 'apply' : 'dry-run'"));
    });
});
