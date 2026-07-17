<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

/** @return array{company_id:int, accounting_period_id:int, ct6_id:int, ct7_id:int} */
function ct600_statutory_seed(bool $withObligation = true): array
{
    \InterfaceDB::beginTransaction();
    $companyId = 98649;
    $accountingPeriodId = 98679;
    $ct6Id = 98606;
    $ct7Id = 98607;
    \InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number) VALUES (:id, :name, :number)',
        ['id' => $companyId, 'name' => 'Synthetic Statutory Projection Ltd', 'number' => '09860000']
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'Synthetic AP79 shape',
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-30',
        ]
    );
    foreach (
        [
            [$ct6Id, 1, '2022-09-05', '2023-09-04'],
            [$ct7Id, 2, '2023-09-05', '2023-09-30'],
        ] as [$ctPeriodId, $sequence, $start, $end]
    ) {
        \InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_periods (
                id, company_id, accounting_period_id, sequence_no, period_start, period_end, status
             ) VALUES (
                :id, :company_id, :accounting_period_id, :sequence_no, :period_start, :period_end, :status
             )',
            [
                'id' => $ctPeriodId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'sequence_no' => $sequence,
                'period_start' => $start,
                'period_end' => $end,
                'status' => 'ready',
            ]
        );
    }
    if ($withObligation) {
        ct600_statutory_insert_obligation($companyId, $accountingPeriodId);
    }

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'ct6_id' => $ct6Id,
        'ct7_id' => $ct7Id,
    ];
}

function ct600_statutory_insert_obligation(int $companyId, int $accountingPeriodId): int
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO hmrc_obligations (
            company_id, accounting_period_id, obligation_type, period_start, period_end,
            due_date, status, source
         ) VALUES (
            :company_id, :accounting_period_id, :obligation_type, :period_start, :period_end,
            :due_date, :status, :source
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'obligation_type' => 'ct600_filing',
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-30',
            'due_date' => '2024-09-30',
            'status' => 'ready',
            'source' => 'calculated',
        ]
    );
    return (int)\InterfaceDB::fetchColumn('SELECT MAX(id) FROM hmrc_obligations');
}

function ct600_statutory_insert_receipt(
    int $id,
    int $companyId,
    int $accountingPeriodId,
    int $ctPeriodId,
    string $environment = 'LIVE',
): void {
    $isLive = $environment === 'LIVE';
    \InterfaceDB::prepareExecute(
        'INSERT INTO hmrc_ct600_submissions (
            id, company_id, accounting_period_id, ct_period_id, mode, environment,
            status, protocol_state, business_outcome, statutory_sync_state,
            submission_type, response_body_path, response_sha256, final_response_at
         ) VALUES (
            :id, :company_id, :accounting_period_id, :ct_period_id, :mode, :environment,
            :status, :protocol_state, :business_outcome, :statutory_sync_state,
            :submission_type, :response_body_path, :response_sha256, :final_response_at
         )',
        [
            'id' => $id,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'mode' => $environment,
            'environment' => $environment,
            'status' => 'accepted',
            'protocol_state' => 'final_received',
            'business_outcome' => $isLive ? 'live_accepted' : 'til_validated',
            'statutory_sync_state' => $isLive ? 'pending' : 'not_applicable',
            'submission_type' => 'original',
            'response_body_path' => 'responses/' . $id . '/final.xml',
            'response_sha256' => hash('sha256', 'final-receipt-' . $id),
            'final_response_at' => '2026-07-17 15:00:00',
        ]
    );
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\Ct600StatutoryAcceptanceService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\Ct600StatutoryAcceptanceService::class,
            'projects each LIVE receipt once and files the AP obligation only after both CT periods are accepted',
            static function () use ($harness): void {
                $scope = ct600_statutory_seed();
                ct600_statutory_insert_receipt(
                    98661,
                    $scope['company_id'],
                    $scope['accounting_period_id'],
                    $scope['ct6_id']
                );
                $service = new \eel_accounts\Service\Ct600StatutoryAcceptanceService(
                    null,
                    static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-07-17 16:00:00 UTC')
                );

                $ct6 = $service->apply(98661);
                $harness->assertSame('applied', (string)$ct6['statutory_sync_state']);
                $harness->assertSame('live_accepted', (string)$ct6['business_outcome']);
                $harness->assertSame('2026-07-17 16:00:00', (string)$ct6['statutory_synced_at']);
                $harness->assertSame(
                    'accepted',
                    (string)\InterfaceDB::fetchColumn(
                        'SELECT status FROM corporation_tax_periods WHERE id = :id',
                        ['id' => $scope['ct6_id']]
                    )
                );
                $harness->assertSame(
                    'ready',
                    (string)\InterfaceDB::fetchColumn(
                        'SELECT status FROM corporation_tax_periods WHERE id = :id',
                        ['id' => $scope['ct7_id']]
                    )
                );
                $harness->assertSame(
                    'ready',
                    (string)\InterfaceDB::fetchColumn(
                        'SELECT status FROM hmrc_obligations WHERE accounting_period_id = :id',
                        ['id' => $scope['accounting_period_id']]
                    )
                );

                $eventCount = (int)\InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM hmrc_submission_events WHERE submission_id = :id',
                    ['id' => 98661]
                );
                $service->apply(98661);
                $harness->assertSame(
                    $eventCount,
                    (int)\InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM hmrc_submission_events WHERE submission_id = :id',
                        ['id' => 98661]
                    )
                );

                ct600_statutory_insert_receipt(
                    98662,
                    $scope['company_id'],
                    $scope['accounting_period_id'],
                    $scope['ct7_id']
                );
                $ct7 = $service->apply(98662);
                $harness->assertSame('applied', (string)$ct7['statutory_sync_state']);
                $obligation = \InterfaceDB::fetchOne(
                    'SELECT id, status, source_reference FROM hmrc_obligations
                     WHERE accounting_period_id = :id',
                    ['id' => $scope['accounting_period_id']]
                );
                $harness->assertTrue(is_array($obligation));
                $harness->assertSame('filed', (string)$obligation['status']);
                $harness->assertSame('hmrc_ct600_live:98661,98662', (string)$obligation['source_reference']);
                $harness->assertSame(
                    2,
                    (int)\InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM hmrc_obligation_submission_links
                         WHERE hmrc_obligation_id = :id',
                        ['id' => (int)$obligation['id']]
                    )
                );
                $service->apply(98662);
                $harness->assertSame(
                    2,
                    (int)\InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM hmrc_obligation_submission_links
                         WHERE hmrc_obligation_id = :id',
                        ['id' => (int)$obligation['id']]
                    )
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600StatutoryAcceptanceService::class,
            'preserves LIVE acceptance on projection failure and retries without resubmitting',
            static function () use ($harness): void {
                $scope = ct600_statutory_seed(false);
                ct600_statutory_insert_receipt(
                    98671,
                    $scope['company_id'],
                    $scope['accounting_period_id'],
                    $scope['ct6_id']
                );
                ct600_statutory_insert_receipt(
                    98672,
                    $scope['company_id'],
                    $scope['accounting_period_id'],
                    $scope['ct7_id']
                );
                $service = new \eel_accounts\Service\Ct600StatutoryAcceptanceService(
                    null,
                    static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-07-17 16:30:00 UTC')
                );

                $failed = false;
                try {
                    $service->apply(98671);
                } catch (\RuntimeException $exception) {
                    $failed = str_contains($exception->getMessage(), 'obligation is missing');
                }
                $harness->assertSame(true, $failed);
                $receipt = \InterfaceDB::fetchOne(
                    'SELECT business_outcome, statutory_sync_state, statutory_sync_error,
                            response_body_path, response_sha256
                     FROM hmrc_ct600_submissions WHERE id = :id',
                    ['id' => 98671]
                );
                $harness->assertTrue(is_array($receipt));
                $harness->assertSame('live_accepted', (string)$receipt['business_outcome']);
                $harness->assertSame('failed', (string)$receipt['statutory_sync_state']);
                $harness->assertTrue(str_contains((string)$receipt['statutory_sync_error'], 'obligation is missing'));
                $harness->assertSame('responses/98671/final.xml', (string)$receipt['response_body_path']);
                $harness->assertSame(hash('sha256', 'final-receipt-98671'), (string)$receipt['response_sha256']);
                $harness->assertSame(
                    'ready',
                    (string)\InterfaceDB::fetchColumn(
                        'SELECT status FROM corporation_tax_periods WHERE id = :id',
                        ['id' => $scope['ct6_id']]
                    )
                );

                ct600_statutory_insert_obligation($scope['company_id'], $scope['accounting_period_id']);
                $retried = $service->retry(98671);
                $harness->assertSame('applied', (string)$retried['statutory_sync_state']);
                $harness->assertSame(null, $retried['statutory_sync_error']);
                $harness->assertSame(
                    'filed',
                    (string)\InterfaceDB::fetchColumn(
                        'SELECT status FROM hmrc_obligations WHERE accounting_period_id = :id',
                        ['id' => $scope['accounting_period_id']]
                    )
                );
                $harness->assertSame(
                    2,
                    (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM hmrc_obligation_submission_links')
                );
                $harness->assertSame('applied', (string)$service->retry(98671)['statutory_sync_state']);
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600StatutoryAcceptanceService::class,
            'rejects TIL validation outcomes without touching statutory state',
            static function () use ($harness): void {
                $scope = ct600_statutory_seed();
                ct600_statutory_insert_receipt(
                    98681,
                    $scope['company_id'],
                    $scope['accounting_period_id'],
                    $scope['ct6_id'],
                    'TIL'
                );
                $service = new \eel_accounts\Service\Ct600StatutoryAcceptanceService();
                $blocked = false;
                try {
                    $service->apply(98681);
                } catch (\DomainException $exception) {
                    $blocked = str_contains($exception->getMessage(), 'Only a final LIVE accepted original');
                }
                $harness->assertSame(true, $blocked);
                $harness->assertSame(
                    'not_applicable',
                    (string)\InterfaceDB::fetchColumn(
                        'SELECT statutory_sync_state FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => 98681]
                    )
                );
                $harness->assertSame(
                    'ready',
                    (string)\InterfaceDB::fetchColumn(
                        'SELECT status FROM corporation_tax_periods WHERE id = :id',
                        ['id' => $scope['ct6_id']]
                    )
                );
            }
        );
    }
);
