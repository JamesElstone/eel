<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$h = new GeneratedServiceClassTestHarness();
$h->run(
    \eel_accounts\Service\CharitableDonationService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CharitableDonationService $ignored): void {
        $h->check(\eel_accounts\Service\CharitableDonationService::class, 'identifies and filters only the exact active donation nominal', static function () use ($h): void {
            charitableDonationTestWithFixture(static function (array $fixture) use ($h): void {
                $service = charitableDonationTestService();
                $h->assertSame((int)$fixture['donation_nominal_id'], $service->nominalAccountId());
                $h->assertSame(true, $service->isDonationNominal((int)$fixture['donation_nominal_id']));
                $h->assertSame(false, $service->isDonationNominal((int)$fixture['expense_nominal_id']));
                $h->assertSame(false, $service->isDonationRow([
                    'code' => '6160', 'subtype_code' => 'overhead',
                ]));
                $filtered = $service->withoutDonationNominal([
                    ['id' => $fixture['donation_nominal_id'], 'code' => '6160', 'subtype_code' => 'charitable_donations'],
                    ['id' => $fixture['expense_nominal_id'], 'code' => '6100', 'subtype_code' => 'overhead'],
                ]);
                $h->assertSame(1, count($filtered));
                $h->assertSame((int)$fixture['expense_nominal_id'], (int)$filtered[0]['id']);

                \InterfaceDB::prepareExecute(
                    'UPDATE nominal_accounts SET is_active = 0 WHERE id = :id',
                    ['id' => (int)$fixture['donation_nominal_id']]
                );
                \eel_accounts\Support\RequestCache::clear();
                $h->assertSame(0, $service->nominalAccountId());
            });
        });

        $h->check(\eel_accounts\Service\CharitableDonationService::class, 'enforces every bank transaction eligibility boundary', static function () use ($h): void {
            charitableDonationTestWithFixture(static function (array $fixture) use ($h): void {
                $service = charitableDonationTestService();
                $transaction = charitableDonationTestTransaction($fixture);
                $nominalId = (int)$fixture['donation_nominal_id'];
                $h->assertSame([], $service->transactionEligibilityErrors($transaction, $nominalId));

                foreach ([
                    'incoming' => ['amount' => 100.00],
                    'zero' => ['amount' => 0.00],
                    'trade' => ['source_account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE],
                    'split_flag' => ['has_transaction_split' => 1],
                    'transfer_flag' => ['is_internal_transfer' => 1],
                    'transfer_account' => ['transfer_account_id' => 999],
                ] as $case => $changes) {
                    $candidate = array_replace($transaction, $changes);
                    $errors = $service->transactionEligibilityErrors($candidate, $nominalId);
                    $h->assertTrue($errors !== [], 'Expected eligibility rejection for ' . $case . '.');
                }

                $h->assertSame([], $service->transactionEligibilityErrors(
                    array_replace($transaction, ['amount' => 100.00]),
                    (int)$fixture['expense_nominal_id']
                ));

                \InterfaceDB::prepareExecute(
                    'INSERT INTO transaction_splits (transaction_id) VALUES (:transaction_id)',
                    ['transaction_id' => (int)$fixture['transaction_id']]
                );
                $errors = $service->transactionEligibilityErrors($transaction, $nominalId);
                $h->assertTrue(str_contains(implode(' ', $errors), 'split bank transaction'));
            });
        });

        $h->check(\eel_accounts\Service\CharitableDonationService::class, 'requires an active registered entity on the payment date', static function () use ($h): void {
            charitableDonationTestWithFixture(static function (array $fixture) use ($h): void {
                $transaction = charitableDonationTestTransaction($fixture);
                $nominalId = (int)$fixture['donation_nominal_id'];

                $linked = charitableDonationTestService([
                    charitableDonationTestRegistryRecord('0'),
                    charitableDonationTestRegistryRecord('1', ['registered_name' => 'Golden Linked Charity']),
                ]);
                $missingSuffix = $linked->verifyTransaction($transaction, $nominalId, 'cc_ew', '9999999');
                $h->assertSame(false, (bool)$missingSuffix['success']);
                $h->assertSame(2, count((array)$missingSuffix['records']));
                $selected = $linked->verifyTransaction($transaction, $nominalId, 'cc_ew', '9999999', '1');
                $h->assertSame(true, (bool)$selected['success']);
                $h->assertSame('Golden Linked Charity', (string)$selected['record']['registered_name']);
                $unknown = $linked->verifyTransaction($transaction, $nominalId, 'cc_ew', '9999999', '8');
                $h->assertSame(false, (bool)$unknown['success']);

                foreach ([
                    'not registered' => ['registered_on' => '2025-05-02'],
                    'removed on payment date' => ['removed_on' => '2025-05-01'],
                    'ceased without date' => ['registry_status' => 'ceased', 'removed_on' => null],
                ] as $label => $changes) {
                    $service = charitableDonationTestService([charitableDonationTestRegistryRecord('0', $changes)]);
                    $result = $service->verifyTransaction($transaction, $nominalId, 'cc_ew', '9999999');
                    $h->assertSame(false, (bool)$result['success'], 'Expected date/status rejection for ' . $label . '.');
                }

                $unsupported = $linked->lookup('unknown', '9999999');
                $h->assertSame(false, (bool)$unsupported['success']);
            });
        });

        $h->check(\eel_accounts\Service\CharitableDonationService::class, 'persists append-only evidence and invalidates it when the tax basis changes', static function () use ($h): void {
            charitableDonationTestWithFixture(static function (array $fixture) use ($h): void {
                $service = charitableDonationTestService();
                $transaction = charitableDonationTestTransaction($fixture);
                $record = charitableDonationTestRegistryRecord();
                $id = $service->recordVerification(
                    $transaction,
                    (int)$fixture['donation_nominal_id'],
                    $record,
                    'registry-body',
                    ''
                );
                $h->assertTrue($id > 0);
                $stored = \InterfaceDB::fetchOne(
                    'SELECT * FROM transaction_charitable_donation_verifications WHERE id = :id',
                    ['id' => $id]
                );
                $h->assertSame('system', (string)$stored['verified_by']);
                $h->assertSame(hash('sha256', 'registry-body'), (string)$stored['response_sha256']);
                $h->assertSame($service->basisHash($transaction, (int)$fixture['donation_nominal_id']), (string)$stored['basis_sha256']);

                \InterfaceDB::prepareExecute(
                    'UPDATE transactions SET nominal_account_id = :nominal_id, category_status = :status WHERE id = :id',
                    [
                        'nominal_id' => (int)$fixture['donation_nominal_id'],
                        'status' => 'manual',
                        'id' => (int)$fixture['transaction_id'],
                    ]
                );
                $h->assertTrue(is_array($service->currentVerification((int)$fixture['transaction_id'])));

                $descriptionChanged = $transaction;
                $descriptionChanged['description'] = 'Updated narrative only';
                $h->assertTrue(is_array($service->verificationForBasis($descriptionChanged, (int)$fixture['donation_nominal_id'])));
                foreach ([
                    ['amount' => -101.00],
                    ['txn_date' => '2025-05-02'],
                    ['company_id' => (int)$fixture['company_id'] + 1],
                    ['accounting_period_id' => (int)$fixture['accounting_period_id'] + 1],
                ] as $changes) {
                    $h->assertSame(null, $service->verificationForBasis(
                        array_replace($transaction, $changes),
                        (int)$fixture['donation_nominal_id']
                    ));
                }
                $h->assertSame(null, $service->verificationForBasis($transaction, (int)$fixture['expense_nominal_id']));

                $secondId = $service->recordVerification(
                    $transaction,
                    (int)$fixture['donation_nominal_id'],
                    array_replace($record, ['registered_name' => 'Golden Charity Reverified']),
                    hash('sha256', 'second-response'),
                    str_repeat('v', 120)
                );
                $h->assertTrue($secondId > $id);
                $current = $service->currentVerification((int)$fixture['transaction_id']);
                $h->assertSame('Golden Charity Reverified', (string)$current['registered_name']);
                $h->assertSame(100, strlen((string)$current['verified_by']));
            });
        });

        $h->check(\eel_accounts\Service\CharitableDonationService::class, 'qualifies only current verified posted bank payments in the CT date window', static function () use ($h): void {
            charitableDonationTestWithFixture(static function (array $fixture) use ($h): void {
                $service = charitableDonationTestService();
                $transaction = charitableDonationTestTransaction($fixture);
                $service->recordVerification(
                    $transaction,
                    (int)$fixture['donation_nominal_id'],
                    charitableDonationTestRegistryRecord(),
                    hash('sha256', 'posted-response'),
                    'tester'
                );
                \InterfaceDB::prepareExecute(
                    'UPDATE transactions SET nominal_account_id = :nominal_id, category_status = :status WHERE id = :id',
                    ['nominal_id' => (int)$fixture['donation_nominal_id'], 'status' => 'manual', 'id' => (int)$fixture['transaction_id']]
                );
                $unposted = $service->qualifyingPaidForPeriod(
                    (int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2025-01-01', '2025-12-31'
                );
                $h->assertSame(0.0, (float)$unposted['total']);

                charitableDonationTestPostJournal($fixture, 100.00);
                $qualified = $service->qualifyingPaidForPeriod(
                    (int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2025-05-01', '2025-05-01'
                );
                $h->assertSame(100.0, (float)$qualified['total']);
                $h->assertSame(1, count((array)$qualified['rows']));
                $h->assertSame('Golden Community Charity', (string)$qualified['rows'][0]['registered_name']);
                $outside = $service->qualifyingPaidForPeriod(
                    (int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2025-05-02', '2025-12-31'
                );
                $h->assertSame(0.0, (float)$outside['total']);

                \InterfaceDB::prepareExecute(
                    'UPDATE transactions SET amount = :amount WHERE id = :id',
                    ['amount' => '-100.01', 'id' => (int)$fixture['transaction_id']]
                );
                $stale = $service->qualifyingPaidForPeriod(
                    (int)$fixture['company_id'], (int)$fixture['accounting_period_id'], '2025-01-01', '2025-12-31'
                );
                $h->assertSame(0.0, (float)$stale['total']);
            });
        });

        $h->check(\eel_accounts\Service\CharitableDonationService::class, 'requires verification before categorisation and journal posting', static function () use ($h): void {
            charitableDonationTestWithFixture(static function (array $fixture) use ($h): void {
                $categorisation = new \eel_accounts\Service\TransactionCategorisationService();
                $unverified = $categorisation->saveManualCategorisation(
                    (int)$fixture['transaction_id'],
                    (int)$fixture['donation_nominal_id'],
                    null,
                    false,
                    'charity-test'
                );
                $h->assertSame(false, (bool)($unverified['success'] ?? true));
                $h->assertTrue(str_contains(implode(' ', (array)($unverified['errors'] ?? [])), 'Verify the charity registration number'));

                $transaction = charitableDonationTestTransaction($fixture);
                charitableDonationTestService()->recordVerification(
                    $transaction,
                    (int)$fixture['donation_nominal_id'],
                    charitableDonationTestRegistryRecord(),
                    hash('sha256', 'workflow-response'),
                    'charity-test'
                );
                $categorised = $categorisation->saveManualCategorisation(
                    (int)$fixture['transaction_id'],
                    (int)$fixture['donation_nominal_id'],
                    null,
                    false,
                    'charity-test'
                );
                $h->assertSame(true, (bool)($categorised['success'] ?? false));
                $h->assertSame(true, (bool)($categorised['changed'] ?? false));

                $posted = (new \eel_accounts\Service\TransactionJournalService())->syncJournalForTransaction(
                    (int)$fixture['transaction_id'],
                    (int)$fixture['bank_nominal_id'],
                    'charity-test'
                );
                $h->assertSame(true, (bool)($posted['success'] ?? false));
                $h->assertSame(true, (bool)($posted['created'] ?? false));
                $h->assertSame(100.0, (float)\InterfaceDB::fetchColumn(
                    'SELECT COALESCE(SUM(jl.debit), 0)
                     FROM journal_lines jl
                     INNER JOIN journals j ON j.id = jl.journal_id
                     WHERE j.source_ref = :source_ref AND jl.nominal_account_id = :nominal_id',
                    [
                        'source_ref' => 'transaction:' . (int)$fixture['transaction_id'],
                        'nominal_id' => (int)$fixture['donation_nominal_id'],
                    ]
                ));
            });
        });

        $h->check(\eel_accounts\Service\CharitableDonationService::class, 'keeps unverified donation lines in tax review and exempts only a current verified bank donation', static function () use ($h): void {
            charitableDonationTestWithFixture(static function (array $fixture) use ($h): void {
                \InterfaceDB::prepareExecute(
                    'UPDATE transactions SET nominal_account_id = :nominal_id WHERE id = :id',
                    [
                        'nominal_id' => (int)$fixture['donation_nominal_id'],
                        'id' => (int)$fixture['transaction_id'],
                    ]
                );
                charitableDonationTestPostJournal($fixture, 100.0);
                \eel_accounts\Support\RequestCache::clear();

                $review = new \eel_accounts\Service\CorporationTaxLineTreatmentService();
                $unverified = $review->fetchReview(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $h->assertSame(1, (int)$unverified['unresolved_count']);
                $h->assertSame('requires_review', (string)$unverified['items'][0]['state']);

                $transaction = charitableDonationTestTransaction($fixture);
                charitableDonationTestService()->recordVerification(
                    $transaction,
                    (int)$fixture['donation_nominal_id'],
                    charitableDonationTestRegistryRecord(),
                    hash('sha256', 'tax-review-response'),
                    'charity-test'
                );
                \eel_accounts\Support\RequestCache::clear();

                $verified = $review->fetchReview(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $h->assertSame(0, (int)$verified['unresolved_count']);
                $h->assertSame([], (array)$verified['items']);
            });
        });

        $h->check(\eel_accounts\Service\CharitableDonationService::class, 'blocks donation nominals from split lines and automatic rules', static function () use ($h): void {
            charitableDonationTestWithFixture(static function (array $fixture) use ($h): void {
                $splitService = new \eel_accounts\Service\TransactionSplitService();
                $started = $splitService->startSplit((int)$fixture['company_id'], (int)$fixture['transaction_id']);
                $h->assertSame(true, (bool)($started['success'] ?? false));
                $line = ((array)($started['split']['lines'] ?? []))[0] ?? [];
                $saved = $splitService->saveLine((int)$fixture['company_id'], (int)($line['id'] ?? 0), [
                    'split_line_description' => 'Attempted donation split',
                    'split_line_amount' => '50.00',
                    'nominal_account_id' => (int)$fixture['donation_nominal_id'],
                ]);
                $h->assertSame(false, (bool)($saved['success'] ?? true));
                $h->assertTrue(str_contains(implode(' ', (array)($saved['errors'] ?? [])), 'cannot be used on transaction split lines'));

                $rule = (new \eel_accounts\Service\CategorisationRuleService())->saveRule((int)$fixture['company_id'], [
                    'priority' => 10,
                    'desc_match_type' => 'contains',
                    'desc_match_value' => 'CHARITY',
                    'ref_match_type' => 'none',
                    'nominal_account_id' => (int)$fixture['donation_nominal_id'],
                ]);
                $h->assertSame(false, (bool)($rule['success'] ?? true));
                $h->assertTrue(str_contains(implode(' ', (array)($rule['errors'] ?? [])), 'cannot be assigned by an automatic categorisation rule'));
            });
        });

        $h->check(\eel_accounts\Service\CharitableDonationService::class, 'excludes and rejects donation nominals throughout expense claims', static function () use ($h): void {
            charitableDonationTestWithFixture(static function (array $fixture) use ($h): void {
                $service = new \eel_accounts\Service\ExpenseClaimService();
                $nominalIds = array_map(
                    static fn(array $row): int => (int)($row['id'] ?? 0),
                    $service->fetchExpenseNominals()
                );
                $h->assertSame(false, in_array((int)$fixture['donation_nominal_id'], $nominalIds, true));
                $h->assertSame(true, in_array((int)$fixture['expense_nominal_id'], $nominalIds, true));

                [$claimId, $claimantId] = charitableDonationTestCreateExpenseClaim($fixture);
                $rejected = $service->saveLine((int)$fixture['company_id'], $claimId, [
                    'expense_date' => '2025-05-01',
                    'description' => 'Attempted charity expense claim',
                    'amount' => '100.00',
                    'nominal_account_id' => (int)$fixture['donation_nominal_id'],
                ]);
                $h->assertSame(false, (bool)($rejected['success'] ?? true));
                $h->assertTrue(str_contains(implode(' ', (array)($rejected['errors'] ?? [])), 'only be recorded from an outgoing bank transaction'));

                \InterfaceDB::prepareExecute(
                    'INSERT INTO expense_claim_lines (expense_claim_id, line_number, expense_date, description, amount, nominal_account_id)
                     VALUES (:claim_id, 1, :expense_date, :description, :amount, :nominal_id)',
                    [
                        'claim_id' => $claimId, 'expense_date' => '2025-05-01',
                        'description' => 'Legacy invalid donation', 'amount' => '100.00',
                        'nominal_id' => (int)$fixture['donation_nominal_id'],
                    ]
                );
                $lineId = (int)\InterfaceDB::fetchColumn(
                    'SELECT id FROM expense_claim_lines WHERE expense_claim_id = :claim_id',
                    ['claim_id' => $claimId]
                );
                $updated = $service->updateLineNominal(
                    (int)$fixture['company_id'], $claimId, $lineId, (int)$fixture['donation_nominal_id']
                );
                $h->assertSame(false, (bool)($updated['success'] ?? true));
                $posted = $service->postClaim((int)$fixture['company_id'], $claimId, [
                    'default_expense_nominal_id' => (int)$fixture['expense_nominal_id'],
                ]);
                $h->assertSame(false, (bool)($posted['success'] ?? true));
                $h->assertTrue(str_contains(implode(' ', (array)($posted['errors'] ?? [])), 'only be recorded from an outgoing bank transaction'));
                $h->assertTrue($claimantId > 0);
            });
        });
    }
);

/** @param list<array<string,mixed>>|null $records */
function charitableDonationTestService(?array $records = null): \eel_accounts\Service\CharitableDonationService
{
    $records ??= [charitableDonationTestRegistryRecord()];
    $client = new class($records) implements \eel_accounts\Contract\CharityRegistryClientInterface {
        public function __construct(private array $records) {}
        public function lookup(string $registrationNumber): array
        {
            $records = array_map(static function (array $record) use ($registrationNumber): array {
                $record['registration_number'] = $registrationNumber;
                return $record;
            }, $this->records);
            return [
                'success' => true,
                'records' => $records,
                'errors' => [],
                'response_sha256' => hash('sha256', 'registry-response-' . $registrationNumber),
            ];
        }
    };
    return new \eel_accounts\Service\CharitableDonationService(['cc_ew' => $client]);
}

/** @return array<string,mixed> */
function charitableDonationTestRegistryRecord(string $suffix = '0', array $changes = []): array
{
    return array_replace([
        'authority' => 'cc_ew',
        'registration_number' => '9999999',
        'entity_suffix' => $suffix,
        'registered_name' => 'Golden Community Charity',
        'registry_status' => 'registered',
        'registered_on' => '2020-01-01',
        'removed_on' => null,
        'source_url' => 'https://example.test/charities/9999999/' . $suffix,
    ], $changes);
}

function charitableDonationTestWithFixture(callable $callback): void
{
    \InterfaceDB::beginTransaction();
    try {
        $callback(charitableDonationTestCreateFixture());
    } finally {
        if (\InterfaceDB::inTransaction()) {
            \InterfaceDB::rollBack();
        }
        \eel_accounts\Support\RequestCache::clear();
    }
}

/** @return array<string,int> */
function charitableDonationTestCreateFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('41' . $marker);
    $periodId = (int)('42' . $marker);
    $accountId = (int)('43' . $marker);
    $uploadId = (int)('44' . $marker);
    $transactionId = (int)('45' . $marker);
    $bankNominalId = charitableDonationTestInsertNominal('CDB' . $marker, 'Charity Test Bank', 'asset', 'other');
    $expenseNominalId = charitableDonationTestInsertNominal('CDE' . $marker, 'Ordinary Expense', 'expense', 'allowable');

    $subtypeId = (int)\InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_account_subtypes WHERE code = :code',
        ['code' => 'charitable_donations']
    );
    if ($subtypeId <= 0) {
        \InterfaceDB::prepareExecute(
            'INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
             VALUES (:code, :name, :parent, 615, 1)',
            ['code' => 'charitable_donations', 'name' => 'Charitable Donations', 'parent' => 'expense']
        );
        $subtypeId = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_account_subtypes WHERE code = :code', ['code' => 'charitable_donations']
        );
    }
    $donationNominalId = (int)\InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code', ['code' => '6160']
    );
    if ($donationNominalId <= 0) {
        \InterfaceDB::prepareExecute(
            'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
             VALUES (:code, :name, :account_type, :subtype_id, :tax_treatment, 1, 6160)',
            [
                'code' => '6160', 'name' => 'Charitable Donations', 'account_type' => 'expense',
                'subtype_id' => $subtypeId, 'tax_treatment' => 'other',
            ]
        );
        $donationNominalId = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_accounts WHERE code = :code', ['code' => '6160']
        );
    }

    \InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active) VALUES (:id, :name, :number, 1)',
        ['id' => $companyId, 'name' => 'Charity Test ' . $marker, 'number' => 'CD' . $marker]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $periodId, 'company_id' => $companyId, 'label' => 'Charity FY',
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
         VALUES (:id, :company_id, :name, :type, :nominal_id, 1)',
        [
            'id' => $accountId, 'company_id' => $companyId, 'name' => 'Charity Test Bank',
            'type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK, 'nominal_id' => $bankNominalId,
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            id, company_id, accounting_period_id, account_id, workflow_status,
            statement_month, original_filename, stored_filename, file_sha256
         ) VALUES (
            :id, :company_id, :period_id, :account_id, :status,
            :month, :original, :stored, :hash
         )',
        [
            'id' => $uploadId, 'company_id' => $companyId, 'period_id' => $periodId, 'account_id' => $accountId,
            'status' => 'committed', 'month' => '2025-05-01', 'original' => 'charity.csv',
            'stored' => 'charity-' . $marker . '.csv', 'hash' => hash('sha256', 'charity-upload-' . $marker),
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id, company_id, accounting_period_id, statement_upload_id, account_id,
            txn_date, txn_type, description, amount, currency, source_account_label, dedupe_hash
         ) VALUES (
            :id, :company_id, :period_id, :upload_id, :account_id,
            :txn_date, :txn_type, :description, :amount, :currency, :account_label, :hash
         )',
        [
            'id' => $transactionId, 'company_id' => $companyId, 'period_id' => $periodId,
            'upload_id' => $uploadId, 'account_id' => $accountId, 'txn_date' => '2025-05-01',
            'txn_type' => 'BACS', 'description' => 'Golden Community Charity', 'amount' => '-100.00',
            'currency' => 'GBP', 'account_label' => 'Charity Test Bank',
            'hash' => hash('sha256', 'charity-transaction-' . $marker),
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $periodId,
        'account_id' => $accountId,
        'transaction_id' => $transactionId,
        'bank_nominal_id' => $bankNominalId,
        'expense_nominal_id' => $expenseNominalId,
        'donation_nominal_id' => $donationNominalId,
    ];
}

function charitableDonationTestInsertNominal(string $code, string $name, string $type, string $tax): int
{
    $id = random_int(200000000, 899999999);
    \InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:id, :code, :name, :type, :tax, 1, 100)',
        ['id' => $id, 'code' => substr($code, 0, 32), 'name' => $name, 'type' => $type, 'tax' => $tax]
    );
    return $id;
}

/** @return array<string,mixed> */
function charitableDonationTestTransaction(array $fixture): array
{
    $transaction = (new \eel_accounts\Service\TransactionCategorisationService())
        ->fetchTransaction((int)$fixture['transaction_id']);
    if (!is_array($transaction)) {
        throw new RuntimeException('Charitable donation transaction fixture is unavailable.');
    }
    return $transaction;
}

function charitableDonationTestPostJournal(array $fixture, float $amount): void
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted
         ) VALUES (
            :company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1
         )',
        [
            'company_id' => (int)$fixture['company_id'], 'period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['transaction_id'],
            'journal_date' => '2025-05-01', 'description' => 'Verified charitable donation',
        ]
    );
    $journalId = (int)\InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => (int)$fixture['company_id'], 'source_ref' => 'transaction:' . (int)$fixture['transaction_id']]
    );
    foreach ([
        [(int)$fixture['donation_nominal_id'], $amount, 0.0],
        [(int)$fixture['bank_nominal_id'], 0.0, $amount],
    ] as [$nominalId, $debit, $credit]) {
        \InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit)
             VALUES (:journal_id, :nominal_id, :debit, :credit)',
            [
                'journal_id' => $journalId, 'nominal_id' => $nominalId,
                'debit' => number_format($debit, 2, '.', ''), 'credit' => number_format($credit, 2, '.', ''),
            ]
        );
    }
}

/** @return array{0:int,1:int} */
function charitableDonationTestCreateExpenseClaim(array $fixture): array
{
    $claimantId = random_int(200000000, 899999999);
    $claimId = random_int(200000000, 899999999);
    \InterfaceDB::prepareExecute(
        'INSERT INTO expense_claimants (id, company_id, claimant_name, is_active)
         VALUES (:id, :company_id, :name, 1)',
        [
            'id' => $claimantId, 'company_id' => (int)$fixture['company_id'],
            'name' => 'Charity Test Claimant ' . $claimantId,
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO expense_claims (
            id, company_id, accounting_period_id, claimant_id, claim_year, claim_month,
            period_start, period_end, claim_reference_code
         ) VALUES (
            :id, :company_id, :period_id, :claimant_id, 2025, 5,
            :period_start, :period_end, :reference
         )',
        [
            'id' => $claimId, 'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'], 'claimant_id' => $claimantId,
            'period_start' => '2025-05-01', 'period_end' => '2025-05-31',
            'reference' => 'CD-' . $claimId,
        ]
    );
    return [$claimId, $claimantId];
}
