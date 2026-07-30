<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    _companies_house_transmission_historyCard::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        _companies_house_transmission_historyCard $card
    ): void {
        $harness->check(
            _companies_house_transmission_historyCard::class,
            'declares submission and paired exchange history services',
            static function () use ($harness, $card): void {
                $services = $card->services();
                $harness->assertCount(2, $services);
                $harness->assertSame('submissionHistory', (string)$services[0]['method']);
                $harness->assertSame(
                    ':company.accounting_period_id',
                    (string)$services[0]['params']['accountingPeriodId']
                );
                $harness->assertSame('protocolExchangeHistory', (string)$services[1]['method']);
                $harness->assertFalse(array_key_exists(
                    'accountingPeriodId',
                    (array)$services[1]['params']
                ));
                $harness->assertTrue(str_contains(
                    $card->helper([]),
                    'Submission History covers the current accounting period'
                ));
                $harness->assertTrue(str_contains(
                    $card->helper([]),
                    'XML Exchange History covers every accounting year'
                ));
            }
        );

        $harness->check(
            _companies_house_transmission_historyCard::class,
            'renders paired private XML downloads independently of developer mode',
            static function () use ($harness, $card): void {
                $previous = AppConfigurationStore::get('developer_options', false);
                AppConfigurationStore::set('developer_options', false);
                try {
                    $html = $card->render([
                        'company' => ['id' => 49, 'accounting_period_id' => 79],
                        'companies_house_history' => ['submission_id' => 0],
                        'services' => [
                            'companies_house_submission_history' => [[
                                'id' => 34,
                                'submission_number' => '000012',
                                'filing_kind' => 'original',
                                'environment' => 'TEST',
                                'lifecycle' => 'rejected',
                                'prepared_at' => '2026-07-30 00:15:00',
                                'submitted_at' => '2026-07-30 00:18:00',
                            ]],
                            'companies_house_exchange_history' => [[
                                'id' => 82,
                                'submission_number' => '000012',
                                'operation' => 'accounts',
                                'transaction_id' => 'ABC123',
                                'exchange_state' => 'rejected',
                                'sent_at' => '2026-07-30 00:18:11',
                                'received_at' => '2026-07-30 00:18:12',
                                'response_status_code' => 200,
                                'request_available' => true,
                                'response_available' => true,
                                'display_outcome' => 'Rejected',
                            ], [
                                'id' => 81,
                                'submission_number' => null,
                                'operation' => 'company_data',
                                'transaction_id' => 'PRE123',
                                'exchange_state' => 'transport_unknown',
                                'sent_at' => '2026-07-30 00:17:02',
                                'received_at' => null,
                                'request_available' => true,
                                'response_available' => false,
                                'display_outcome' => 'Transport unknown',
                            ]],
                        ],
                    ]);

                    $harness->assertTrue(str_contains($html, 'Submission History'));
                    $harness->assertTrue(str_contains($html, 'XML Exchange History'));
                    $harness->assertTrue(str_contains($html, '000012'));
                    $harness->assertTrue(str_contains($html, 'Not allocated'));
                    $harness->assertTrue(str_contains($html, 'Company authentication check'));
                    $harness->assertTrue(str_contains($html, 'value="download_protocol_evidence"'));
                    $harness->assertSame(3, substr_count($html, '>Download</button>'));
                    $harness->assertTrue(str_contains($html, 'HTTP 200'));
                    $harness->assertTrue(str_contains($html, 'View conversation'));
                } finally {
                    AppConfigurationStore::set('developer_options', (bool)$previous);
                }
            }
        );
    }
);
