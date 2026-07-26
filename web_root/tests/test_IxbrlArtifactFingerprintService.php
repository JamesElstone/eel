<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlArtifactFingerprintService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlArtifactFingerprintService $service): void {
        $harness->check($service::class, 'memoizes a fingerprint only for the active request', static function () use ($harness, $service): void {
            \eel_accounts\Support\RequestCache::reset();
            $path = tempnam(test_tmp_directory(), 'eel-ixbrl-fingerprint-');
            if ($path === false) {
                throw new RuntimeException('Could not create the temporary fingerprint fixture.');
            }

            try {
                file_put_contents($path, 'first artifact');
                $firstHash = hash('sha256', 'first artifact');
                $secondHash = hash('sha256', 'second artifact');
                $harness->assertSame($firstHash, $service->sha256($path));

                file_put_contents($path, 'second artifact');
                $harness->assertSame($secondHash, $service->sha256($path));

                file_put_contents($path, 'first artifact');
                $request = new stdClass();
                $scope = \eel_accounts\Support\RequestCache::beginFor($request);
                $harness->assertSame($firstHash, $service->sha256($path));

                file_put_contents($path, 'second artifact');
                $harness->assertSame($firstHash, $service->sha256($path));

                \eel_accounts\Support\RequestCache::endFor($scope);
                $nextRequest = new stdClass();
                $nextScope = \eel_accounts\Support\RequestCache::beginFor($nextRequest);
                $harness->assertSame($secondHash, $service->sha256($path));
                \eel_accounts\Support\RequestCache::endFor($nextScope);
            } finally {
                \eel_accounts\Support\RequestCache::reset();
                @unlink($path);
            }
        });

        $harness->check($service::class, 'fails closed for missing and unreadable artifact paths', static function () use ($harness, $service): void {
            \eel_accounts\Support\RequestCache::reset();
            $missing = test_tmp_directory() . DIRECTORY_SEPARATOR
                . 'eel-ixbrl-missing-' . bin2hex(random_bytes(8)) . '.xhtml';
            $request = new stdClass();
            $scope = \eel_accounts\Support\RequestCache::beginFor($request);

            try {
                $harness->assertSame(null, $service->sha256($missing));
                $harness->assertSame(null, $service->sha256($missing));
            } finally {
                \eel_accounts\Support\RequestCache::endFor($scope);
                \eel_accounts\Support\RequestCache::reset();
            }
        });
    }
);
