<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(
    \eel_accounts\Service\PrepaymentSourceService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\PrepaymentSourceService $service): void {
        GoldenAccountsFixture::build();

        $harness->check(\eel_accounts\Service\PrepaymentSourceService::class, 'bulk candidate context preserves exact source verification evidence', static function () use ($harness, $service): void {
            $context = $service->fetchCandidateContext(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111);

            $harness->assertCount(1, $context['eligible']);
            $harness->assertCount(0, $context['excluded']);
            $source = (array)$context['eligible'][0];
            $verified = $service->verify(
                GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                9111,
                (string)$source['source_type'],
                (int)$source['source_id']
            );
            $harness->assertTrue(!empty($verified['success']));
            $harness->assertSame(
                (int)($verified['source']['source_journal_id'] ?? 0),
                (int)($source['source_journal_id'] ?? 0)
            );
            $harness->assertSame(
                (int)($verified['source']['source_journal_line_id'] ?? 0),
                (int)($source['source_journal_line_id'] ?? 0)
            );
        });
    }
);
