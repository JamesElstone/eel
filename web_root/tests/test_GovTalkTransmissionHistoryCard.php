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
                            ]],
                        ],
                    ];
                    $exchangeCard = new _govtalk_exchangesCard();
                    $html = $card->title() . $card->render($context)
                        . $exchangeCard->title() . $exchangeCard->render($context);

                    $harness->assertTrue(str_contains($html, 'Submission History'));
                    $harness->assertTrue(str_contains($html, '<th>Transaction ID</th>'));
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
                    $harness->assertTrue(str_contains($html, 'Clear filters'));
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
                    $harness->assertTrue(str_contains($html, 'View conversation'));
                    $harness->assertTrue(str_contains($html, 'name="_card_refresh" value="1"'));
                    $harness->assertTrue(str_contains(
                        $html,
                        'name="_invalidate_fact" value="govtalk.exchanges.selection"'
                    ));
                    $harness->assertTrue(str_contains($html, 'name="cards[]" value="govtalk_exchanges"'));
                    $harness->assertFalse(str_contains($html, 'name="cards[]" value="govtalk_transmission_history"'));
                    $harness->assertTrue(str_contains($html, 'Get Submission Status'));
                    $harness->assertTrue(str_contains(
                        $html,
                        '<form method="post" action="?page=transmit" data-ajax="true">'
                    ));
                    $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit">Get Submission Status</button>'));
                    $harness->assertTrue(str_contains(
                        $html,
                        'name="intent" value="refresh_accounts_status"'
                    ));
                    $harness->assertFalse(str_contains($html, 'name="company_auth_code"'));
                } finally {
                    AppConfigurationStore::set('developer_options', (bool)$previous);
                }
            }
        );
    }
);
