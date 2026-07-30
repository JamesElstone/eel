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
$harness->run(_companies_house_transmitCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _companies_house_transmitCard $card
): void {
    $harness->check(
        _companies_house_transmitCard::class,
        'declares the transmission status read service',
        static function () use ($harness, $card): void {
            $services = $card->services();
            $harness->assertCount(1, $services);
            $harness->assertSame('companies_house_transmit_context', (string)$services[0]['key']);
            $harness->assertSame(
                \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                (string)$services[0]['service']
            );
            $harness->assertSame('fetchContext', (string)$services[0]['method']);
        }
    );

    $harness->check(
        _companies_house_transmitCard::class,
        'shows the next presenter-wide number and allocates only on send',
        static function () use ($harness, $card): void {
            $secret = 'DO-NOT-RENDER-THIS-AUTHENTICATION-VALUE';
            $html = $card->render([
                'company' => ['id' => 49, 'accounting_period_id' => 80],
                'services' => [
                    'companies_house_transmit_context' => [
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
                            'filing_kind' => 'original',
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
                    'companies_house_transmit_history' => [],
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'Next submission number'));
            $harness->assertTrue(str_contains($html, '000001'));
            $harness->assertTrue(str_contains(
                $html,
                '</div><div class="summary-grid"><div class="summary-card"><div class="summary-label">Next submission number</div>'
            ));
            $harness->assertTrue(str_contains($html, 'Companies House Connection'));
            $harness->assertTrue(str_contains($html, '>Test<'));
            $harness->assertTrue(str_contains($html, 'Configure Companies House XML environment'));
            $harness->assertTrue(str_contains($html, '<div class="summary-label">Credentials</div>'));
            $harness->assertTrue(str_contains($html, 'summary-card success hmrc-credential-summary-card'));
            $harness->assertTrue(str_contains($html, 'Configure Companies House XML credentials'));
            $harness->assertTrue(str_contains($html, 'show_card=api_keys_editor'));
            $harness->assertFalse(str_contains($html, 'XML Input'));
            $harness->assertFalse(str_contains($html, 'CompanyData XML Output'));
            $harness->assertFalse(str_contains($html, 'Transport lock'));
            $harness->assertFalse(str_contains($html, 'Status / StatusAck lock'));
            $harness->assertFalse(str_contains($html, 'Protocol migration'));
            $harness->assertFalse(str_contains($html, 'Allocated on send'));
            $harness->assertTrue(str_contains($html, '>Companies House iXBRL</button>'));
            $harness->assertFalse(str_contains($html, 'Download Companies House iXBRL'));
            $harness->assertTrue(str_contains($html, 'value="download_accounts_ixbrl"'));
            $harness->assertFalse(str_contains($html, '<div class="summary-label">Artifact SHA-256</div>'));
            $harness->assertFalse(str_contains($html, 'revised-accounts.xhtml'));
            $harness->assertTrue(str_contains($html, 'action="?page=transmit"'));
            $harness->assertTrue(str_contains($html, 'value="submit_accounts"'));
            $harness->assertTrue(str_contains(
                $html,
                '<section class="panel-soft"><h3 class="card-title">Submit accounts</h3>'
                . '<form method="post" action="?page=transmit" data-ajax="true" class="settings-stack">'
            ));
            $harness->assertTrue(str_contains($html, 'Original'));
            $harness->assertFalse(str_contains($html, $secret));
        }
    );

    $harness->check(
        _companies_house_transmitCard::class,
        'shows a danger credential card and configuration action when XML credentials are missing',
        static function () use ($harness, $card): void {
            $html = $card->render([
                'company' => ['id' => 49, 'accounting_period_id' => 80],
                'services' => [
                    'companies_house_transmit_context' => [
                        'feature' => [
                            'mode' => 'TEST',
                            'credentials_configured' => false,
                        ],
                        'sequence' => ['next_number' => 'Unavailable'],
                        'submission' => null,
                    ],
                    'companies_house_transmit_history' => [],
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'summary-card danger hmrc-credential-summary-card'));
            $harness->assertTrue(str_contains(
                $html,
                'Companies House XML accounts filing credentials are missing for the TEST environment.'
            ));
            $harness->assertTrue(str_contains($html, 'Configure Companies House XML credentials'));
            $harness->assertTrue(str_contains($html, 'show_card=api_keys_editor'));
        }
    );

    $harness->check(
        _companies_house_transmitCard::class,
        'renders a missing prepared iXBRL artifact as a spaced helper',
        static function () use ($harness, $card): void {
            $message = 'The prepared Companies House iXBRL artifact is missing.';
            $html = $card->render([
                'company' => ['id' => 49, 'accounting_period_id' => 80],
                'services' => [
                    'companies_house_transmit_context' => [
                        'feature' => ['mode' => 'TEST', 'credentials_configured' => true],
                        'sequence' => ['next_number' => '000001'],
                        'submission' => [
                            'id' => 712,
                            'lifecycle' => 'prepared',
                            'filing_kind' => 'original',
                        ],
                        'prepared_artifact' => [
                            'state' => 'missing',
                            'current' => false,
                            'errors' => [$message],
                        ],
                        'submission_blockers' => [$message],
                        'can_submit' => false,
                    ],
                    'companies_house_transmit_history' => [],
                ],
            ]);

            $harness->assertTrue(str_contains(
                $html,
                '<div class="helper companies-house-artifact-missing-helper">' . $message . '</div>'
            ));
            $harness->assertFalse(str_contains($html, '<div class="notice warning">' . $message));
        }
    );

    $harness->check(
        _companies_house_transmitCard::class,
            'adds protocol controls only in developer mode',
        static function () use ($harness, $card): void {
            $previous = AppConfigurationStore::get('developer_options', false);
            AppConfigurationStore::set('developer_options', true);
            try {
                $html = $card->render([
                    'company' => ['id' => 49, 'accounting_period_id' => 80],
                    'services' => [
                        'companies_house_transmit_context' => [
                            'feature' => [
                                'mode' => 'TEST',
                                'credentials_configured' => true,
                                'protocol_ready' => true,
                                'developer_binding_configured' => true,
                            ],
                            'sequence' => ['next_number' => '000001'],
                            'submission' => [
                                'id' => 712,
                                'lifecycle' => 'prepared',
                                'filing_kind' => 'revised',
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
                        'companies_house_transmit_history' => [],
                    ],
                ]);
                $harness->assertTrue(str_contains($html, 'Send / continue TEST filing'));
                $harness->assertTrue(str_contains($html, 'Send CompanyData preflight'));
                $harness->assertTrue(str_contains(
                    $html,
                    '<section class="panel-soft"><h3 class="card-title">Companies House protocol controls</h3>'
                ));
                $harness->assertFalse(str_contains(
                    $html,
                    'Each button performs one XML send/receive pair and then pauses.'
                ));
                $harness->assertFalse(str_contains($html, 'Developer XML exchange timeline'));
                $harness->assertFalse(str_contains($html, 'value="download_protocol_evidence"'));
                $harness->assertTrue(str_contains($html, 'maxlength="6"'));
                $harness->assertFalse(str_contains($html, 'maxlength="8"'));
            } finally {
                AppConfigurationStore::set('developer_options', (bool)$previous);
            }
        }
    );

});
