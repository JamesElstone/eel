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
$harness->run(OtpVerificationService::class, function (GeneratedServiceClassTestHarness $harness, OtpVerificationService $service): void {
    $secret = 'JBSWY3DPEHPK3PXP';
    $digits = 6;
    $algorithm = 'SHA1';
    $period = 30;
    $window = 1;
    $currentUnixTime = 1_710_000_000;
    $currentTimestep = $service->currentTimestep($currentUnixTime, $period);

    $harness->check(OtpVerificationService::class, 'calculates timesteps deterministically from a supplied unix time', function () use ($harness, $service, $currentUnixTime, $period): void {
        $harness->assertSame(intdiv($currentUnixTime, $period), $service->currentTimestep($currentUnixTime, $period));
    });

    $harness->check(OtpVerificationService::class, 'accepts a current timestep code and returns the matched timestep', function () use ($harness, $service, $secret, $digits, $algorithm, $period, $window, $currentUnixTime, $currentTimestep): void {
        $code = $service->generateCodeForTimestep($digits, $algorithm, $secret, $currentTimestep);

        $harness->assertSame(
            $currentTimestep,
            $service->verifyCode($code, $digits, $algorithm, $period, $secret, $currentUnixTime, $window, null, true)
        );
    });

    $harness->check(OtpVerificationService::class, 'accepts a previous timestep when it is still inside the allowed window', function () use ($harness, $service, $secret, $digits, $algorithm, $period, $window, $currentUnixTime, $currentTimestep): void {
        $code = $service->generateCodeForTimestep($digits, $algorithm, $secret, $currentTimestep - 1);

        $harness->assertSame(
            $currentTimestep - 1,
            $service->verifyCode($code, $digits, $algorithm, $period, $secret, $currentUnixTime, $window, null, true)
        );
    });

    $harness->check(OtpVerificationService::class, 'accepts a future timestep when it is inside the allowed window', function () use ($harness, $service, $secret, $digits, $algorithm, $period, $window, $currentUnixTime, $currentTimestep): void {
        $code = $service->generateCodeForTimestep($digits, $algorithm, $secret, $currentTimestep + 1);

        $harness->assertSame(
            $currentTimestep + 1,
            $service->verifyCode($code, $digits, $algorithm, $period, $secret, $currentUnixTime, $window, null, true)
        );
    });

    $harness->check(OtpVerificationService::class, 'rejects codes outside the verification window', function () use ($harness, $service, $secret, $digits, $algorithm, $period, $window, $currentUnixTime, $currentTimestep): void {
        $code = $service->generateCodeForTimestep($digits, $algorithm, $secret, $currentTimestep + 2);

        $harness->assertSame(
            null,
            $service->verifyCode($code, $digits, $algorithm, $period, $secret, $currentUnixTime, $window, null, true)
        );
    });

    $harness->check(OtpVerificationService::class, 'rejects replay of the same timestep when replay protection is on', function () use ($harness, $service, $secret, $digits, $algorithm, $period, $window, $currentUnixTime, $currentTimestep): void {
        $code = $service->generateCodeForTimestep($digits, $algorithm, $secret, $currentTimestep);

        $harness->assertSame(
            null,
            $service->verifyCode($code, $digits, $algorithm, $period, $secret, $currentUnixTime, $window, $currentTimestep, true)
        );
    });

    $harness->check(OtpVerificationService::class, 'rejects an older timestep once a newer timestep has already been used', function () use ($harness, $service, $secret, $digits, $algorithm, $period, $window, $currentUnixTime, $currentTimestep): void {
        $code = $service->generateCodeForTimestep($digits, $algorithm, $secret, $currentTimestep - 1);

        $harness->assertSame(
            null,
            $service->verifyCode($code, $digits, $algorithm, $period, $secret, $currentUnixTime, $window, $currentTimestep, true)
        );
    });

    $harness->check(OtpVerificationService::class, 'allows repeated verification when replay protection is disabled', function () use ($harness, $service, $secret, $digits, $algorithm, $period, $window, $currentUnixTime, $currentTimestep): void {
        $code = $service->generateCodeForTimestep($digits, $algorithm, $secret, $currentTimestep);

        $harness->assertSame(
            $currentTimestep,
            $service->verifyCode($code, $digits, $algorithm, $period, $secret, $currentUnixTime, $window, $currentTimestep, false)
        );
    });

    $harness->check(OtpVerificationService::class, 'allows a newer timestep even when an older one has already been used', function () use ($harness, $service, $secret, $digits, $algorithm, $period, $window, $currentUnixTime, $currentTimestep): void {
        $code = $service->generateCodeForTimestep($digits, $algorithm, $secret, $currentTimestep + 1);

        $harness->assertSame(
            $currentTimestep + 1,
            $service->verifyCode($code, $digits, $algorithm, $period, $secret, $currentUnixTime, $window, $currentTimestep, true)
        );
    });
});
