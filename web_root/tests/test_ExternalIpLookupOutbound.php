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
    ExternalIpLookupOutbound::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(ExternalIpLookupOutbound::class, 'validates public IP responses from injected requester', static function () use ($harness): void {
            $outbound = new ExternalIpLookupOutbound(static fn(): string => '8.8.8.8');

            $harness->assertSame('8.8.8.8', $outbound->lookupPublicIp());
        });

        $harness->check(ExternalIpLookupOutbound::class, 'rejects private IP responses', static function () use ($harness): void {
            $outbound = new ExternalIpLookupOutbound(static fn(): string => '192.168.0.1');
            $failed = false;

            try {
                $outbound->lookupPublicIp();
            } catch (RuntimeException) {
                $failed = true;
            }

            $harness->assertSame(true, $failed);
        });
    }
);
