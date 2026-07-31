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
            'declares submission and paired exchange history services',
            static function () use ($harness, $card): void {
                $harness->assertSame('govtalk_transmission_history', $card->key());
                $harness->assertSame('Transmission History', $card->title());
                $services = $card->services();
                $harness->assertCount(2, $services);
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
                $harness->assertSame('exchangeHistory', (string)$services[1]['method']);
                $harness->assertFalse(array_key_exists(
                    'accountingPeriodId',
                    (array)$services[1]['params']
                ));
                $harness->assertTrue(str_contains(
                    $card->helper([]),
                    'Submission History combines HMRC and Companies House'
                ));
                $harness->assertTrue(str_contains(
                    $card->helper([]),
                    'XML Exchange History combines every GovTalk exchange'
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
                    $html = $card->render([
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
                                'filing_context' => 'Company accounts',
                                'filing_type' => 'Original',
                                'environment' => 'TEST',
                                'transaction_id' => 'ABC123',
                                'status_key' => 'rejected',
                                'latest_status' => 'Rejected',
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
                    ]);

                    $harness->assertTrue(str_contains($html, 'Submission History'));
                    $harness->assertTrue(str_contains($html, '<th>Transaction ID</th>'));
                    $harness->assertTrue(str_contains($html, 'XML Exchange History'));
                    $harness->assertTrue(str_contains(
                        $html,
                        'data-table-key="govtalk_xml_exchange_history"'
                    ));
                    $harness->assertTrue(str_contains($html, 'Apply Filters'));
                    $harness->assertTrue(str_contains($html, 'Clear filters'));
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
                } finally {
                    AppConfigurationStore::set('developer_options', (bool)$previous);
                }
            }
        );
    }
);
