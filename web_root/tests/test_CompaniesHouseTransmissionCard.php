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
$harness->run(_companies_house_transmissionCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _companies_house_transmissionCard $card
): void {
    $harness->check(
        _companies_house_transmissionCard::class,
        'declares read services for transmission status and history',
        static function () use ($harness, $card): void {
            $services = $card->services();
            $harness->assertCount(2, $services);
            $harness->assertSame('companies_house_transmission_context', (string)$services[0]['key']);
            $harness->assertSame(
                \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                (string)$services[0]['service']
            );
            $harness->assertSame('fetchContext', (string)$services[0]['method']);
            $harness->assertSame('companies_house_transmission_history', (string)$services[1]['key']);
            $harness->assertSame('submissionHistory', (string)$services[1]['method']);
        }
    );

    $harness->check(
        _companies_house_transmissionCard::class,
        'shows the next presenter-wide number and allocates only on send',
        static function () use ($harness, $card): void {
            $secret = 'DO-NOT-RENDER-THIS-AUTHENTICATION-VALUE';
            $html = $card->render([
                'company' => ['id' => 49, 'accounting_period_id' => 80],
                'services' => [
                    'companies_house_transmission_context' => [
                        'feature' => [
                            'mode' => 'TEST',
                            'credentials_configured' => true,
                            'authentication_value' => $secret,
                        ],
                        'sequence' => [
                            'next_number' => '000001',
                            'last_issued_number' => null,
                            'in_flight_submission_id' => null,
                        ],
                        'submission' => [
                            'id' => 712,
                            'lifecycle' => 'prepared',
                            'submission_number' => null,
                            'revised_artifact_path' => 'private/revised-accounts.xhtml',
                            'revised_artifact_sha256' => str_repeat('a', 64),
                            'transmission_archive' => null,
                        ],
                        'prepared_artifact' => [
                            'filename' => 'revised-accounts.xhtml',
                            'sha256' => str_repeat('a', 64),
                        ],
                        'can_submit' => true,
                        'submission_blockers' => [],
                    ],
                    'companies_house_transmission_history' => [],
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'Next submission number'));
            $harness->assertTrue(str_contains($html, '000001'));
            $harness->assertTrue(str_contains($html, 'Allocated on send'));
            $harness->assertTrue(str_contains($html, 'action="?page=transmit"'));
            $harness->assertTrue(str_contains($html, 'value="submit_revised_accounts"'));
            $harness->assertFalse(str_contains($html, $secret));
        }
    );

    $harness->check(
        _companies_house_transmissionCard::class,
        'adds one-exchange controls and exact XML evidence only in developer mode',
        static function () use ($harness, $card): void {
            $previous = AppConfigurationStore::get('developer_options', false);
            AppConfigurationStore::set('developer_options', true);
            try {
                $html = $card->render([
                    'company' => ['id' => 49, 'accounting_period_id' => 80],
                    'services' => [
                        'companies_house_transmission_context' => [
                            'feature' => [
                                'mode' => 'TEST',
                                'credentials_configured' => true,
                                'company_data_credentials_configured' => true,
                                'protocol_ready' => true,
                                'developer_binding_configured' => true,
                            ],
                            'sequence' => ['next_number' => '000001'],
                            'submission' => [
                                'id' => 712,
                                'lifecycle' => 'prepared',
                                'submission_number' => null,
                                'revised_artifact_path' => 'private/revised-accounts.xhtml',
                                'revised_artifact_sha256' => str_repeat('a', 64),
                            ],
                            'prepared_artifact' => [
                                'filename' => 'revised-accounts.xhtml',
                                'sha256' => str_repeat('a', 64),
                            ],
                            'preflight' => null,
                            'status_cycle' => null,
                            'exchanges' => [[
                                'id' => 8,
                                'operation' => 'company_data',
                                'transaction_id' => 'ABC123',
                                'exchange_state' => 'succeeded',
                                'request_path' => 'private/request.xml',
                                'response_path' => 'private/response.xml',
                            ]],
                            'can_submit' => true,
                            'submission_blockers' => [],
                        ],
                        'companies_house_transmission_history' => [],
                    ],
                ]);
                $harness->assertTrue(str_contains($html, 'Send / continue TEST filing'));
                $harness->assertTrue(str_contains($html, 'Send CompanyData preflight'));
                $harness->assertTrue(str_contains($html, 'Developer XML exchange timeline'));
                $harness->assertTrue(str_contains($html, 'value="download_protocol_evidence"'));
                $harness->assertTrue(str_contains($html, 'maxlength="6"'));
                $harness->assertFalse(str_contains($html, 'maxlength="8"'));
            } finally {
                AppConfigurationStore::set('developer_options', (bool)$previous);
            }
        }
    );
});
