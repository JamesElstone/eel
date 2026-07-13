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
$harness->run(
    \eel_accounts\Service\PrepaymentApprovalContextService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\PrepaymentApprovalContextService $service
    ): void {
        $harness->check(\eel_accounts\Service\PrepaymentApprovalContextService::class, 'returns one unavailable review context without constructing the Year End checklist', static function () use ($harness, $service): void {
            $context = $service->fetchContext(0, 0);

            $harness->assertSame(false, !empty($context['review']['available']));
            $harness->assertSame(null, $context['approval']);
            $harness->assertSame(
                'Select a company and accounting period before reviewing prepayments.',
                (string)($context['review']['errors'][0] ?? '')
            );
        });
    }
);
