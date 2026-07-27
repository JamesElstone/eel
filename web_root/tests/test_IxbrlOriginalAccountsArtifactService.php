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
    \eel_accounts\Service\IxbrlOriginalAccountsArtifactService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlOriginalAccountsArtifactService $service
    ): void {
        $harness->check(
            $service::class,
            'accepts ordinary iXBRL and rejects the revised-report marker',
            static function () use ($harness, $service): void {
                $ordinary = $service->validateSource(
                    '<html xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"><body>'
                    . '<ix:nonNumeric name="bus:NameEntity" contextRef="current">Example Limited</ix:nonNumeric>'
                    . '</body></html>'
                );
                $harness->assertSame(true, !empty($ordinary['success']));

                $revised = $service->validateSource(
                    '<ix:nonNumeric name="bus:ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse" '
                    . 'contextRef="current_period_duration">true</ix:nonNumeric>'
                );
                $harness->assertSame(false, !empty($revised['success']));
                $harness->assertTrue(str_contains(
                    implode(' ', (array)($revised['errors'] ?? [])),
                    'must not contain'
                ));
            }
        );
    }
);
