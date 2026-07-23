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
$harness->run(\eel_accounts\Service\CompaniesHouseDocumentService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\CompaniesHouseDocumentService::class, 'follows signed content redirects without authentication', static function () use ($harness): void {
        $requests = [];
        $service = new \eel_accounts\Service\CompaniesHouseDocumentService('LIVE', 20, static function (array $request) use (&$requests): array {
            $requests[] = $request;
            return count($requests) === 1
                ? ['status_code' => 302, 'url' => $request['url'], 'body' => '', 'headers' => ['location' => 'https://signed.example.invalid/document']]
                : ['status_code' => 200, 'url' => $request['url'], 'body' => '<html/>', 'headers' => ['content-type' => 'application/xhtml+xml']];
        });

        $content = $service->fetchContent('/document/example/content', 'application/xhtml+xml');
        $harness->assertSame(200, (int)$content['status']);
        $harness->assertSame('none', (string)($requests[1]['auth'] ?? ''));
    });
});
