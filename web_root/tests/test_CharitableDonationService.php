<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CharitableDonationService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $client = new class implements \eel_accounts\Contract\CharityRegistryClientInterface {
            public function lookup(string $registrationNumber): array
            {
                return [
                    'success' => true,
                    'records' => [[
                        'authority' => 'cc_ew',
                        'registration_number' => $registrationNumber,
                        'entity_suffix' => '0',
                        'registered_name' => 'Example Registered Charity',
                        'registry_status' => 'registered',
                        'registered_on' => '2020-01-01',
                        'removed_on' => null,
                        'source_url' => 'https://example.test/register/1234567',
                    ]],
                    'errors' => [],
                    'response_sha256' => hash('sha256', 'registry-response'),
                ];
            }
        };
        $service = new \eel_accounts\Service\CharitableDonationService(['cc_ew' => $client]);
        $subtypeId = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_account_subtypes WHERE code = :code',
            ['code' => \eel_accounts\Service\CharitableDonationService::SUBTYPE_CODE]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
             VALUES (:code, :name, :account_type, :subtype_id, :tax_treatment, 1, :sort_order)',
            [
                'code' => \eel_accounts\Service\CharitableDonationService::NOMINAL_CODE,
                'name' => 'Charitable Donations',
                'account_type' => 'expense',
                'subtype_id' => $subtypeId,
                'tax_treatment' => 'other',
                'sort_order' => 6160,
            ]
        );
        $nominalId = $service->nominalAccountId();
        $harness->assertTrue($nominalId > 0);

        $outgoing = [
            'id' => 1,
            'company_id' => 1,
            'accounting_period_id' => 1,
            'txn_date' => '2024-06-01',
            'amount' => -100.00,
            'source_account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'has_transaction_split' => 0,
            'is_internal_transfer' => 0,
            'transfer_account_id' => null,
        ];
        $verified = $service->verifyTransaction($outgoing, $nominalId, 'cc_ew', '1234567');
        $harness->assertSame(true, (bool)$verified['success']);
        $harness->assertSame('Example Registered Charity', (string)$verified['record']['registered_name']);

        $incoming = $outgoing;
        $incoming['amount'] = 100.00;
        $rejected = $service->verifyTransaction($incoming, $nominalId, 'cc_ew', '1234567');
        $harness->assertSame(false, (bool)$rejected['success']);
        $harness->assertTrue(str_contains(implode(' ', $rejected['errors']), 'outgoing bank transaction'));
    }
);
