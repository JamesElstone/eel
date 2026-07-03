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
    \eel_accounts\Service\ManualAssetEvidenceStorageService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\ManualAssetEvidenceStorageService $service): void {
        $harness->check(\eel_accounts\Service\ManualAssetEvidenceStorageService::class, 'rejects missing company or asset code', static function () use ($harness, $service): void {
            $result = $service->storeEvidence(0, '', []);

            $harness->assertSame(false, (bool)($result['success'] ?? true));
            $harness->assertSame(
                'Asset evidence cannot be stored without a company and asset code.',
                (string)($result['errors'][0] ?? '')
            );
        });

        $harness->check(\eel_accounts\Service\ManualAssetEvidenceStorageService::class, 'rejects absent uploaded evidence', static function () use ($harness, $service): void {
            $result = $service->storeEvidence(1, 'ASSET-1', [
                'error' => UPLOAD_ERR_NO_FILE,
            ]);

            $harness->assertSame(false, (bool)($result['success'] ?? true));
            $harness->assertSame('Upload evidence that the manual asset exists.', (string)($result['errors'][0] ?? ''));
        });

        $harness->check(\eel_accounts\Service\ManualAssetEvidenceStorageService::class, 'rejects non HTTP upload temporary files', static function () use ($harness, $service): void {
            $tmpFile = tempnam(sys_get_temp_dir(), 'manual_asset_evidence_');
            if ($tmpFile === false) {
                $harness->skip('Unable to create a temporary file.');
            }

            file_put_contents($tmpFile, 'not-an-http-upload');

            try {
                $result = $service->storeEvidence(1, 'ASSET-1', [
                    'name' => 'evidence.pdf',
                    'tmp_name' => $tmpFile,
                    'size' => filesize($tmpFile),
                    'error' => UPLOAD_ERR_OK,
                ]);

                $harness->assertSame(false, (bool)($result['success'] ?? true));
                $harness->assertSame(
                    'The uploaded asset evidence file was not received as a valid HTTP upload.',
                    (string)($result['errors'][0] ?? '')
                );
            } finally {
                if (is_file($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        });

        $harness->check(\eel_accounts\Service\ManualAssetEvidenceStorageService::class, 'deletes stored evidence by absolute path', static function () use ($harness, $service): void {
            $tmpFile = tempnam(sys_get_temp_dir(), 'manual_asset_evidence_delete_');
            if ($tmpFile === false) {
                $harness->skip('Unable to create a temporary file.');
            }

            file_put_contents($tmpFile, 'delete-me');
            $service->deleteStoredEvidence(['absolute_path' => $tmpFile]);

            $harness->assertSame(false, is_file($tmpFile));
        });
    }
);
