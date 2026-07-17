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
$harness->run(\eel_accounts\Service\HmrcCtSubmissionReadModel::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\HmrcCtSubmissionReadModel $unused
): void {
    $harness->check(\eel_accounts\Service\HmrcCtSubmissionReadModel::class, 'does not treat a TIL validation as statutory acceptance', static function () use ($harness): void {
        $periods = [[
            'id' => 6,
            'sequence_no' => 1,
            'display_label' => 'CT Period 1',
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-04',
        ]];
        $history = [[
            'id' => 91,
            'ct_period_id' => 6,
            'environment' => 'TIL',
            'protocol_state' => 'closed',
            'business_outcome' => 'til_validated',
            'created_at' => '2026-07-17 09:00:00',
        ]];
        $model = new \eel_accounts\Service\HmrcCtSubmissionReadModel(
            static fn(int $companyId, int $accountingPeriodId): array => $periods,
            static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId, string $environment): array => [
                'ok' => true,
                'can_prepare' => true,
                'can_submit' => false,
                'checks' => [],
                'blockers' => [],
                'warnings' => [],
            ],
            static fn(int $companyId, int $accountingPeriodId): array => $history,
            static fn(int $submissionId): array => [],
            static fn(): string => 'TIL'
        );

        $state = $model->pageState(49, 79);
        $harness->assertSame('til_validated', (string)($state['progress'][0]['state'] ?? ''));
        $harness->assertSame(false, (bool)($state['progress'][0]['live_accepted'] ?? true));
        $harness->assertSame(false, (bool)($state['capabilities']['can_submit'] ?? true));
    });

    $harness->check(\eel_accounts\Service\HmrcCtSubmissionReadModel::class, 'binds lifecycle capabilities to the configured environment', static function () use ($harness): void {
        $periods = [[
            'id' => 6,
            'sequence_no' => 1,
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-04',
        ]];
        $history = [
            [
                'id' => 102,
                'ct_period_id' => 6,
                'environment' => 'TEST',
                'protocol_state' => 'closed',
                'business_outcome' => 'sandbox_passed',
                'created_at' => '2026-07-17 10:00:00',
            ],
            [
                'id' => 101,
                'ct_period_id' => 6,
                'environment' => 'TIL',
                'protocol_state' => 'awaiting_poll',
                'business_outcome' => 'none',
                'next_poll_at' => '2000-01-01 00:00:00',
                'created_at' => '2026-07-17 09:00:00',
            ],
        ];
        $model = new \eel_accounts\Service\HmrcCtSubmissionReadModel(
            static fn(): array => $periods,
            static fn(): array => [
                'ok' => true,
                'can_prepare' => true,
                'can_submit' => true,
                'checks' => [],
                'blockers' => [],
                'warnings' => [],
            ],
            static fn(): array => $history,
            static fn(): array => [],
            static fn(): string => 'TIL'
        );

        $state = $model->pageState(49, 79, 6);
        $harness->assertSame(101, (int)($state['latest_submission']['id'] ?? 0));
        $harness->assertSame(true, (bool)($state['capabilities']['can_poll'] ?? false));
        $harness->assertSame(false, (bool)($state['capabilities']['can_prepare'] ?? true));
    });
});
