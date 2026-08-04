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
    \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\GovTalkTransmissionHistoryService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
            'formats HMRC submission counters as six digits',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'submissionCounter');
                $method->setAccessible(true);

                $harness->assertSame('000003', $method->invoke($service, 3));
                $harness->assertSame('1234567', $method->invoke($service, 1234567));
            }
        );
        $harness->check(
            \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
            'formats accounting period date ranges',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'periodRange');
                $method->setAccessible(true);

                $harness->assertSame('2024-10-01 to 2025-09-30', $method->invoke(
                    $service,
                    ['period_start' => '2024-10-01', 'period_end' => '2025-09-30']
                ));
            }
        );
        $harness->check(
            \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
            'distinguishes a rejected filing awaiting GovTalk cleanup',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'hmrcStatus');
                $method->setAccessible(true);

                $harness->assertSame('Rejected — cleanup required', $method->invoke(
                    $service,
                    ['business_outcome' => 'rejected', 'protocol_state' => 'delete_pending']
                ));
                $harness->assertSame('Rejected', $method->invoke(
                    $service,
                    ['business_outcome' => 'rejected', 'protocol_state' => 'closed']
                ));
                $harness->assertSame('Delete pending', $method->invoke(
                    $service,
                    ['business_outcome' => 'none', 'protocol_state' => 'delete_pending']
                ));
            }
        );
        $harness->check(
            \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
            'shows successful HMRC filing separately from its conversation cleanup state',
            static function () use ($harness, $service): void {
                $status = new ReflectionMethod($service, 'hmrcStatus');
                $status->setAccessible(true);
                $tone = new ReflectionMethod($service, 'hmrcStatusTone');
                $tone->setAccessible(true);
                $harness->assertSame('Submitted — cleanup required', $status->invoke(
                    $service,
                    ['business_outcome' => 'sandbox_passed', 'protocol_state' => 'delete_pending']
                ));
                $harness->assertSame('Submitted', $status->invoke(
                    $service,
                    ['business_outcome' => 'sandbox_passed', 'protocol_state' => 'closed']
                ));
                $harness->assertSame('success', $tone->invoke(
                    $service,
                    ['business_outcome' => 'sandbox_passed', 'protocol_state' => 'delete_pending']
                ));
            }
        );
        $harness->check(
            \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
            'keeps the internal HMRC submission counter independent from the remote reference',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'exchangeSubmissionReference');
                $method->setAccessible(true);
                $harness->assertSame('000006', $method->invoke($service, [
                    'authority' => 'hmrc',
                    'hmrc_submission_id' => 6,
                    'hmrc_submission_reference' => '8596148860',
                ]));
            }
        );
        $harness->check(
            \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
            'selects only the current strictly bound HMRC response for reprocessing',
            static function () use ($harness, $service): void {
                $contractMethod = new ReflectionMethod($service, 'hmrcResponseReprocessContract');
                $contractMethod->setAccessible(true);
                $eligibleMethod = new ReflectionMethod($service, 'hmrcResponseReprocessEligible');
                $eligibleMethod->setAccessible(true);
                $responsePath = tempnam(test_tmp_directory(), 'hmrc-history-response-');
                if (!is_string($responsePath)) {
                    throw new RuntimeException('Unable to create response evidence fixture.');
                }
                file_put_contents($responsePath, '<GovTalkMessage/>');
                $responseHash = hash_file('sha256', $responsePath);
                if (!is_string($responseHash)) {
                    throw new RuntimeException('Unable to hash response evidence fixture.');
                }
                $submission = [
                    'id' => 4,
                    'company_id' => 49,
                    'accounting_period_id' => 79,
                    'protocol_state' => 'awaiting_poll',
                    'environment' => 'TEST',
                    'transaction_id' => '54B1C98A7BC69A5135435909056F65D1',
                    'response_body_path' => $responsePath,
                    'response_sha256' => $responseHash,
                ];
                $exchange = [
                    'id' => 25,
                    'authority' => 'hmrc',
                    'hmrc_submission_id' => 4,
                    'operation' => 'poll',
                    'environment' => 'TEST',
                    'transaction_id' => '54B1C98A7BC69A5135435909056F65D1',
                    'exchange_state' => 'failed',
                    'response_status_code' => 200,
                    'response_path' => $responsePath,
                    'response_sha256' => $responseHash,
                    'archive_authority' => 'hmrc',
                    'archive_environment' => 'TEST',
                    'archive_company_id' => 49,
                    'archive_accounting_period_id' => 79,
                    'archive_submission_reference' => 'submission-000004',
                ];

                try {
                    $contract = $contractMethod->invoke($service, $submission);
                    $harness->assertSame('poll', (string)$contract['operation']);
                    $harness->assertTrue((bool)$eligibleMethod->invoke(
                        $service,
                        $submission,
                        $exchange,
                        $contract
                    ));

                    $transport = $submission;
                    $transport['protocol_state'] = 'transport_uncertain';
                    $harness->assertSame(
                        'submit',
                        (string)$contractMethod->invoke($service, $transport)['operation']
                    );
                    $cleanup = $submission;
                    $cleanup['protocol_state'] = 'delete_pending';
                    $harness->assertSame(
                        'delete',
                        (string)$contractMethod->invoke($service, $cleanup)['operation']
                    );
                    $acceptedRepair = $submission;
                    $acceptedRepair['protocol_state'] = 'delete_pending';
                    $acceptedRepair['business_outcome'] = 'sandbox_passed';
                    $acceptedRepair['hmrc_submission_reference'] = '(count(ancestor-or-self::node()))';
                    $acceptedContract = $contractMethod->invoke($service, $acceptedRepair);
                    $harness->assertSame('poll', (string)$acceptedContract['operation']);
                    $harness->assertSame(true, (bool)$acceptedContract['metadata_only']);
                    $closed = $submission;
                    $closed['protocol_state'] = 'closed';
                    $harness->assertSame(null, $contractMethod->invoke($service, $closed));

                    foreach ([
                        ['transaction_id', 'UNRELATED'],
                        ['exchange_state', 'succeeded'],
                        ['response_status_code', 500],
                        ['archive_environment', 'LIVE'],
                        ['archive_submission_reference', 'submission-000005'],
                        ['response_sha256', str_repeat('a', 64)],
                    ] as [$key, $value]) {
                        $invalid = $exchange;
                        $invalid[$key] = $value;
                        $harness->assertFalse((bool)$eligibleMethod->invoke(
                            $service,
                            $submission,
                            $invalid,
                            $contract
                        ));
                    }
                } finally {
                    @unlink($responsePath);
                }
            }
        );
    }
);
