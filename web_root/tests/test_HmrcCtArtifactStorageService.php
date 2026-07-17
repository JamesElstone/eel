<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

function hmrc_ct_storage_test_root(string $suffix): string
{
    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
        . 'eel-hmrc-ct-storage-' . $suffix . '-' . bin2hex(random_bytes(5));
}

function hmrc_ct_remove_test_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            hmrc_ct_remove_test_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\HmrcCtArtifactStorageService::class, static function (
    GeneratedServiceClassTestHarness $harness
): void {
    $harness->check(
        \eel_accounts\Service\HmrcCtArtifactStorageService::class,
        'stores immutable package artefacts under opaque relative keys and verifies every hash',
        static function () use ($harness): void {
            $root = hmrc_ct_storage_test_root('package');
            try {
                $storage = new \eel_accounts\Service\HmrcCtArtifactStorageService($root);
                $ct600 = '<IRenvelope><IRmark>safe</IRmark></IRenvelope>';
                $accounts = '<html><body>accounts</body></html>';
                $computations = '<html><body>computations</body></html>';
                $packageHash = hash('sha256', $ct600 . $accounts . $computations);
                $stored = $storage->storePreparedPackage(
                    49,
                    79,
                    6,
                    'TIL',
                    $packageHash,
                    $ct600,
                    $accounts,
                    $computations,
                    ['manifest_version' => 1, 'package_hash' => $packageHash]
                );

                $harness->assertFalse(str_starts_with((string)$stored['ct600_path'], $root));
                $harness->assertSame(hash('sha256', $ct600), $stored['ct600_sha256']);
                $harness->assertSame($ct600, $storage->readVerified(
                    (string)$stored['ct600_path'],
                    (string)$stored['ct600_sha256']
                ));
                $harness->assertSame(true, $storage->verify(
                    (string)$stored['accounts_ixbrl_path'],
                    (string)$stored['accounts_sha256']
                ));

                $again = $storage->storePreparedPackage(
                    49,
                    79,
                    6,
                    'TIL',
                    $packageHash,
                    $ct600,
                    $accounts,
                    $computations,
                    ['manifest_version' => 1, 'package_hash' => $packageHash]
                );
                $harness->assertSame($stored['ct600_path'], $again['ct600_path']);
            } finally {
                hmrc_ct_remove_test_tree($root);
            }
        }
    );

    $harness->check(
        \eel_accounts\Service\HmrcCtArtifactStorageService::class,
        'fails closed on path traversal public storage credentials and immutable overwrite attempts',
        static function () use ($harness): void {
            $root = hmrc_ct_storage_test_root('guards');
            $publicRoot = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp'
                . DIRECTORY_SEPARATOR . 'hmrc-ct-public-' . bin2hex(random_bytes(4));
            try {
                $storage = new \eel_accounts\Service\HmrcCtArtifactStorageService($root);
                $first = $storage->writeImmutable('safe/file.xml', '<safe/>');

                foreach (
                    [
                        static fn() => $storage->writeImmutable('../escape.xml', '<safe/>'),
                        static fn() => $storage->writeImmutable((string)$first['path'], '<changed/>'),
                        static fn() => $storage->writeImmutable('safe/credential.xml', '<Password>secret</Password>'),
                        static fn() => $storage->writeImmutable(
                            'safe/govtalk-credential.xml',
                            '<GovTalkMessage><Header><SenderDetails><IDAuthentication>'
                            . '<SenderID>real-sender</SenderID><Authentication><Method>clear</Method>'
                            . '<Value>real-password</Value></Authentication></IDAuthentication>'
                            . '</SenderDetails></Header></GovTalkMessage>'
                        ),
                        static fn() => new \eel_accounts\Service\HmrcCtArtifactStorageService($publicRoot),
                    ] as $operation
                ) {
                    $thrown = false;
                    try {
                        $operation();
                    } catch (\Throwable) {
                        $thrown = true;
                    }
                    $harness->assertSame(true, $thrown);
                }
            } finally {
                hmrc_ct_remove_test_tree($root);
                hmrc_ct_remove_test_tree($publicRoot);
            }
        }
    );

    $harness->check(
        \eel_accounts\Service\HmrcCtArtifactStorageService::class,
        'stores only redacted requests and protected response artefacts',
        static function () use ($harness): void {
            $root = hmrc_ct_storage_test_root('responses');
            try {
                $storage = new \eel_accounts\Service\HmrcCtArtifactStorageService($root);
                $directory = 'companies/49/accounting-periods/79/ct-periods/6/TEST/' . str_repeat('a', 64);
                $request = $storage->storeRedactedRequest(
                    $directory,
                    '<GovTalkMessage><IDAuthentication>[REDACTED]</IDAuthentication></GovTalkMessage>'
                );
                $response = $storage->storeResponse(
                    $directory,
                    'acknowledgement',
                    '<GovTalkMessage><CorrelationID>abc</CorrelationID></GovTalkMessage>'
                );
                $harness->assertTrue(str_contains((string)$request['path'], 'submission-request-redacted.xml'));
                $harness->assertTrue(str_contains((string)$response['path'], '/responses/acknowledgement-'));
            } finally {
                hmrc_ct_remove_test_tree($root);
            }
        }
    );
});
