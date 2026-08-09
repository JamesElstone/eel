<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    _govtalk_transmission_historyCard::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        _govtalk_transmission_historyCard $card
    ): void {
        $harness->check(
            _govtalk_transmission_historyCard::class,
            'declares submission history service',
            static function () use ($harness, $card): void {
                $harness->assertSame('govtalk_transmission_history', $card->key());
                $harness->assertSame('Submission History', $card->title());
                $services = $card->services();
                $harness->assertCount(1, $services);
                $harness->assertSame('submissionHistory', (string)$services[0]['method']);
                $harness->assertSame(
                    ':company.accounting_period_id',
                    (string)$services[0]['params']['accountingPeriodId']
                );
                $harness->assertSame(
                    ':govtalk_history.authority',
                    (string)$services[0]['params']['authority']
                );
                $harness->assertSame(
                    ':govtalk_history.environment',
                    (string)$services[0]['params']['environment']
                );
                $harness->assertTrue(str_contains(
                    $card->helper([]),
                    'Submission History shows HMRC and Companies House filings'
                ));
            }
        );

        $harness->check(
            _govtalk_transmission_historyCard::class,
            'renders paired private XML downloads independently of developer mode',
            static function () use ($harness, $card): void {
                $previous = AppConfigurationStore::get('developer_options', false);
                AppConfigurationStore::set('developer_options', false);
                try {
                    $context = [
                        'company' => ['id' => 49, 'accounting_period_id' => 79],
                        'govtalk_history' => [
                            'authority' => '',
                            'environment' => '',
                            'conversation_authority' => '',
                            'conversation_id' => 0,
                        ],
                        'services' => [
                            'govtalk_submission_history' => [[
                                'authority' => 'companies_house',
                                'authority_label' => 'Companies House',
                                'conversation_id' => 34,
                                'submission_reference' => '000012',
                                'filing_context' => 'Company accounts - 2024-10-01 to 2025-09-30',
                                'filing_type' => 'Original',
                                'environment' => 'TEST',
                                'transaction_id' => 'ABC123',
                                'status_key' => 'pending',
                                'latest_status' => 'Pending',
                                'prepared_at' => '2026-07-30 00:15:00',
                                'submitted_at' => '2026-07-30 00:18:00',
                            ], [
                                'authority' => 'hmrc',
                                'authority_label' => 'HMRC',
                                'conversation_id' => 4,
                                'ct_period_id' => 7,
                                'submission_reference' => '000004',
                                'filing_context' => 'CT600 - 2024-10-01 to 2025-09-30',
                                'filing_type' => 'Original',
                                'environment' => 'TEST',
                                'transaction_id' => 'HMRC-TXN-4',
                                'correlation_id' => 'HMRC-CORR-4',
                                'status_key' => 'awaiting_poll',
                                'latest_status' => 'Awaiting HMRC poll',
                                'prepared_at' => '2026-07-30 00:20:00',
                                'submitted_at' => '2026-07-30 00:21:00',
                            ]],
                            'govtalk_exchange_history' => [[
                                'id' => 82,
                                'authority_label' => 'Companies House',
                                'submission_reference' => '000012',
                                'operation' => 'accounts',
                                'request_message_class' => 'Accounts',
                                'transaction_id' => 'ABC123',
                                'exchange_state' => 'rejected',
                                'sent_at' => '2026-07-30 00:18:11',
                                'received_at' => '2026-07-30 00:18:12',
                                'response_status_code' => 200,
                                'display_http_status' => '200 OK',
                                'govtalk_errors' => [],
                                'request_available' => true,
                                'response_available' => true,
                                'display_outcome' => 'Rejected',
                            ], [
                                'id' => 81,
                                'authority_label' => 'Companies House',
                                'submission_reference' => 'Not allocated',
                                'operation' => 'company_data',
                                'request_message_class' => 'CompanyDataRequest',
                                'transaction_id' => 'PRE123',
                                'exchange_state' => 'failed',
                                'sent_at' => '2026-07-30 00:17:02',
                                'received_at' => '2026-07-30 00:17:03',
                                'request_available' => true,
                                'response_available' => true,
                                'display_http_status' => '200 OK',
                                'govtalk_errors' => [[
                                    'raised_by' => 'CompanyDataRequest',
                                    'number' => '502',
                                    'type' => 'fatal',
                                    'texts' => ['Authorisation Failure'],
                                    'locations' => [],
                                ]],
                                'display_outcome' => 'Presenter authorisation failed',
                            ], [
                                'id' => 83,
                                'authority_label' => 'HMRC',
                                'submission_reference' => '000004',
                                'operation' => 'poll',
                                'request_message_class' => 'HMRC-CT-CT600',
                                'transaction_id' => 'HMRC-EX-TXN',
                                'correlation_id' => 'HMRC-EX-CORR',
                                'exchange_state' => 'succeeded',
                                'sent_at' => '2026-07-30 00:22:02',
                                'received_at' => '2026-07-30 00:22:03',
                                'request_available' => false,
                                'response_available' => false,
                                'display_http_status' => '200 OK',
                                'govtalk_errors' => [],
                                'display_outcome' => 'Acknowledged',
                            ]],
                        ],
                    ];
                    $exchangeCard = new _govtalk_exchangesCard();
                    $html = $card->title() . $card->render($context)
                        . $exchangeCard->title() . $exchangeCard->render($context);

                    $harness->assertTrue(str_contains($html, 'Submission History'));
                    $harness->assertTrue(str_contains(
                        $html,
                        '<th>Transaction / Correlation ID</th>'
                    ));
                    $harness->assertTrue(str_contains(
                        $html,
                        'HMRC-TXN-4<div class="helper">HMRC-CORR-4</div>'
                    ));
                    $harness->assertTrue(str_contains(
                        $html,
                        'HMRC-EX-TXN<div class="helper">HMRC-EX-CORR</div>'
                    ));
                    $harness->assertTrue(str_contains($html, '<th>Filing / Period</th>'));
                    $harness->assertTrue(str_contains(
                        $html,
                        'Company accounts - 2024-10-01 to 2025-09-30'
                    ));
                    $harness->assertTrue(str_contains($html, 'XML Exchange History'));
                    $harness->assertTrue(str_contains(
                        $html,
                        'data-table-key="govtalk_xml_exchange_history"'
                    ));
                    $harness->assertTrue(str_contains($html, 'Apply Filters'));
                    $harness->assertTrue(str_contains($html, 'Clear Filters'));
                    $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit">Apply Filters</button>'));
                    $harness->assertTrue(str_contains($html, '<form method="post" action="?page=transmit" data-ajax="true">'));
                    $harness->assertTrue(str_contains($html, 'name="history_conversation_authority" value="companies_house"'));
                    $harness->assertTrue(str_contains(
                        $html,
                        'class="toolbar govtalk-xml-exchange-filter-controls"'
                    ));
                    $harness->assertTrue(str_contains(
                        $html,
                        'class="form-row table-filter-row"><label for="table-filter-govtalk_xml_exchange_history-authority">Authority</label><select class="selector-input"'
                    ));
                    $harness->assertTrue(str_contains(
                        $html,
                        'id="table-filter-govtalk_xml_exchange_history-environment" name="history_environment"'
                    ));
                    $harness->assertTrue(str_contains($html, '>CSV</button>'));
                    $harness->assertTrue(str_contains($html, 'XML exchanges'));
                    $harness->assertTrue(str_contains($html, '000012'));
                    $harness->assertTrue(str_contains($html, 'Not allocated'));
                    $harness->assertTrue(str_contains($html, 'CompanyDataRequest'));
                    $harness->assertTrue(str_contains($html, 'value="download_protocol_evidence"'));
                    $harness->assertTrue(str_contains(
                        $html,
                        'value="GovTalkTransmissionHistory"'
                    ));
                    $harness->assertSame(4, substr_count($html, '>Download</button>'));
                    $harness->assertTrue(str_contains($html, 'HTTP Response Code'));
                    $harness->assertTrue(str_contains($html, '200 OK'));
                    $harness->assertTrue(str_contains($html, 'GovTalk Errors'));
                    $harness->assertTrue(str_contains($html, '502 — Authorisation Failure'));
                    $harness->assertTrue(str_contains($html, 'Raised by CompanyDataRequest'));
                    $harness->assertFalse(str_contains($html, 'Rejected · HTTP'));
                    $harness->assertTrue(str_contains($html, 'View Conversation'));
                    $harness->assertTrue(str_contains($html, 'name="_card_refresh" value="1"'));
                    $harness->assertTrue(str_contains(
                        $html,
                        'name="_invalidate_fact" value="govtalk.exchanges.selection"'
                    ));
                    $harness->assertTrue(str_contains($html, 'name="cards[]" value="govtalk_exchanges"'));
                    $harness->assertFalse(str_contains($html, 'name="cards[]" value="govtalk_transmission_history"'));
                    $harness->assertFalse(str_contains($html, 'Get Submission Status'));
                    $harness->assertSame(2, substr_count($html, 'Check Submission Status'));
                    $harness->assertTrue(str_contains(
                        $html,
                        '<form method="post" action="?page=transmit" data-ajax="true">'
                    ));
                    $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit">Check Submission Status</button>'));
                    $harness->assertTrue(str_contains(
                        $html,
                        'name="intent" value="refresh_accounts_status"'
                    ));
                    $harness->assertTrue(str_contains(
                        $html,
                        'name="intent" value="hmrc_poll"'
                    ));
                    $harness->assertTrue(str_contains($html, 'name="ct_period_id" value="7"'));
                    $harness->assertFalse(str_contains($html, 'name="company_auth_code"'));

                    $tableMethod = new ReflectionMethod($exchangeCard, 'exchangeHistoryTable');
                    $tableMethod->setAccessible(true);
                    /** @var TableFramework $table */
                    $table = $tableMethod->invoke(
                        $exchangeCard,
                        49,
                        $context['services']['govtalk_exchange_history'],
                        $context['govtalk_history']
                    );
                    $harness->assertTrue(str_contains(
                        $table->exportCsv(),
                        "HMRC-EX-TXN\nHMRC-EX-CORR"
                    ));
                } finally {
                    AppConfigurationStore::set('developer_options', (bool)$previous);
                }
            }
        );

        $harness->check(
            _govtalk_transmission_historyCard::class,
            'exports every structured GovTalk error field in deterministic readable form',
            static function () use ($harness): void {
                $exchangeCard = new _govtalk_exchangesCard();
                $errors = [[
                    'source' => 'body',
                    'scope' => 'departmental',
                    'raised_by' => 'Department',
                    'number' => '3001',
                    'type' => 'fatal',
                    'texts' => [
                        'The submission contains errors, review every detail.',
                        'Second diagnostic text',
                    ],
                    'locations' => ['', '/Accounts/BalanceSheet', '/Computation/Tax'],
                ], [
                    'raised_by' => 'Gateway',
                    'number' => '1046',
                    'type' => 'fatal',
                    'texts' => ['Authentication Failure'],
                    'locations' => [],
                ]];
                $expected = 'Error 1 | RaisedBy: Department | Number: 3001 | Type: fatal'
                    . ' | Source: body | Scope: departmental'
                    . ' | Text 1: The submission contains errors, review every detail.'
                    . ' | Text 2: Second diagnostic text'
                    . ' | Location 1: /Accounts/BalanceSheet'
                    . ' | Location 2: /Computation/Tax'
                    . "\nError 2 | RaisedBy: Gateway | Number: 1046 | Type: fatal"
                    . ' | Text 1: Authentication Failure';

                $exportMethod = new ReflectionMethod($exchangeCard, 'govTalkErrorsExport');
                $exportMethod->setAccessible(true);
                $harness->assertSame($expected, $exportMethod->invoke($exchangeCard, $errors));

                $tableMethod = new ReflectionMethod($exchangeCard, 'exchangeHistoryTable');
                $tableMethod->setAccessible(true);
                /** @var TableFramework $table */
                $table = $tableMethod->invoke($exchangeCard, 49, [[
                    'authority_label' => 'HMRC',
                    'submission_reference' => '000004',
                    'request_message_class' => 'HMRC-CT-CT600',
                    'transaction_id' => 'POLL-TXN',
                    'govtalk_errors' => $errors,
                    'display_outcome' => 'Rejected',
                ]], []);
                $harness->assertTrue(str_contains($table->exportCsv(), '"' . $expected . '"'));
            }
        );

        $harness->check(
            _govtalk_transmission_historyCard::class,
            'shows rejected cleanup state with the existing status action',
            static function () use ($harness, $card): void {
                $html = $card->render([
                    'company' => ['id' => 49, 'accounting_period_id' => 79],
                    'services' => [
                        'govtalk_submission_history' => [[
                            'authority' => 'hmrc',
                            'authority_label' => 'HMRC',
                            'conversation_id' => 4,
                            'ct_period_id' => 7,
                            'submission_reference' => '000004',
                            'filing_context' => 'CT600 — 2023-09-05 to 2023-09-30',
                            'filing_type' => 'Original',
                            'environment' => 'TEST',
                            'transaction_id' => 'POLL-TXN',
                            'correlation_id' => 'HMRC-CORR',
                            'status_key' => 'delete_pending',
                            'latest_status' => 'Rejected — cleanup required',
                            'prepared_at' => '2026-08-01 00:55:39',
                            'submitted_at' => '2026-08-01 00:55:40',
                        ]],
                    ],
                ]);

                $harness->assertTrue(str_contains($html, 'Rejected — cleanup required'));
                $harness->assertTrue(str_contains($html, 'name="intent" value="hmrc_poll"'));
                $harness->assertTrue(str_contains($html, '>Check Submission Status</button>'));
            }
        );

        $harness->check(
            _govtalk_transmission_historyCard::class,
            'shows an accepted TEST filing as submitted with internal and HMRC references',
            static function () use ($harness, $card): void {
                $html = $card->render([
                    'company' => ['id' => 49, 'accounting_period_id' => 79],
                    'services' => [
                        'govtalk_submission_history' => [[
                            'authority' => 'hmrc',
                            'authority_label' => 'HMRC',
                            'conversation_id' => 6,
                            'ct_period_id' => 7,
                            'submission_reference' => '000006',
                            'hmrc_document_reference' => '8596148860',
                            'filing_context' => 'CT600 — 2023-09-05 to 2023-09-30',
                            'filing_type' => 'Original',
                            'environment' => 'TEST',
                            'transaction_id' => 'POLL-TXN',
                            'correlation_id' => 'HMRC-CORR',
                            'status_key' => 'delete_pending',
                            'status_tone' => 'success',
                            'latest_status' => 'Submitted — cleanup required',
                            'prepared_at' => '2026-08-04 06:35:01',
                            'submitted_at' => '2026-08-04 06:35:11',
                        ]],
                    ],
                ]);

                $harness->assertTrue(str_contains($html, '000006'));
                $harness->assertTrue(str_contains($html, 'HMRC ref 8596148860'));
                $harness->assertTrue(str_contains(
                    $html,
                    '<span class="badge success">Submitted — cleanup required</span>'
                ));
                $harness->assertTrue(str_contains($html, '>Check Submission Status</button>'));

                $tableMethod = new ReflectionMethod($card, 'exchangeHistoryTable');
                $tableMethod->setAccessible(true);
                /** @var TableFramework $table */
                $table = $tableMethod->invoke($card, 49, [[
                    'authority_label' => 'HMRC',
                    'submission_reference' => '000006',
                    'hmrc_document_reference' => '8596148860',
                    'request_message_class' => 'HMRC-CT-CT600',
                    'transaction_id' => 'POLL-TXN',
                    'govtalk_errors' => [],
                    'display_outcome' => 'Accepted',
                ]], []);
                $harness->assertTrue(str_contains(
                    $table->exportCsv(),
                    '000006 HMRC ref 8596148860'
                ));
            }
        );

        $harness->check(
            _govtalk_transmission_historyCard::class,
            'renders an eligible archived HMRC response as a developer-only danger action',
            static function () use ($harness, $card): void {
                $context = [
                    'company' => ['id' => 49, 'accounting_period_id' => 79],
                    'services' => [
                        'govtalk_submission_history' => [[
                            'authority' => 'hmrc',
                            'authority_label' => 'HMRC',
                            'conversation_id' => 4,
                            'ct_period_id' => 7,
                            'submission_reference' => '000004',
                            'filing_context' => 'CT600 — 2023-09-05 to 2023-09-30',
                            'filing_type' => 'Original',
                            'environment' => 'TEST',
                            'transaction_id' => 'AD907A5A3D1804FB27577E1CCD9C95C9',
                            'correlation_id' => '',
                            'status_key' => 'transport_uncertain',
                            'latest_status' => 'Transmission outcome uncertain',
                            'prepared_at' => '2026-08-01 00:55:39',
                            'submitted_at' => '2026-08-01 00:55:40',
                            'response_reprocess_available' => true,
                            'response_reprocess_exchange_id' => 25,
                        ]],
                    ],
                ];
                $previous = AppConfigurationStore::get('developer_options', false);
                try {
                    AppConfigurationStore::set('developer_options', false);
                    $standard = $card->render($context);
                    $harness->assertFalse(str_contains(
                        $standard,
                        'name="intent" value="hmrc_reprocess_response"'
                    ));

                    AppConfigurationStore::set('developer_options', true);
                    $developer = $card->render($context);
                    $harness->assertTrue(str_contains(
                        $developer,
                        'name="intent" value="hmrc_reprocess_response"'
                    ));
                    $harness->assertTrue(str_contains(
                        $developer,
                        '<button class="button button-inline danger" type="submit" title="Developer only"'
                    ));
                    $harness->assertTrue(str_contains(
                        $developer,
                        'data-chicken-button-class="button danger"'
                    ));
                    foreach ([
                        'name="card_action" value="HmrcSubmission"',
                        'name="company_id" value="49"',
                        'name="accounting_period_id" value="79"',
                        'name="ct_period_id" value="7"',
                        'name="submission_id" value="4"',
                        'name="exchange_id" value="25"',
                    ] as $field) {
                        $harness->assertTrue(str_contains($developer, $field));
                    }
                    $harness->assertTrue(str_contains(
                        $developer,
                        'Nothing will be sent to HMRC by this action.'
                    ));
                    $harness->assertTrue(str_contains($developer, '>Reprocess Response</button>'));
                    $harness->assertFalse(str_contains($developer, 'hmrc_recover_acknowledgement'));
                } finally {
                    AppConfigurationStore::set('developer_options', (bool)$previous);
                }
            }
        );
    }
);
