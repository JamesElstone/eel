<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PeriodLedgerTestFixture.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CorporationTaxLineTreatmentService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CorporationTaxLineTreatmentService $service): void {
        $h->check($service::class, 'uses a saved journal-line treatment in review and tax computation', static function () use ($h, $service): void {
            InterfaceDB::beginTransaction();
            try {
                $fixture = periodLedgerTestCreateFixture();
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];
                $marker = substr(hash('sha256', (string)microtime(true)), 0, 10);
                $professionalNominalId = periodLedgerTestInsertNominal(
                    'PF' . $marker,
                    'Professional Fees ' . $marker,
                    'expense',
                    'allowable'
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO corporation_tax_treatment_rules (
                        rule_code, rule_version, priority, account_type, name_contains,
                        tax_treatment, source_url, source_checked_at, rationale, review_status, is_active
                     ) VALUES (
                        :rule_code, :rule_version, 1, :account_type, :name_contains,
                        :tax_treatment, :source_url, :source_checked_at, :rationale, :review_status, 1
                     )',
                    [
                        'rule_code' => 'professional_review_' . $marker,
                        'rule_version' => 'test-v1',
                        'account_type' => 'expense',
                        'name_contains' => $marker,
                        'tax_treatment' => 'other',
                        'source_url' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim35500',
                        'source_checked_at' => '2026-07-22',
                        'rationale' => 'Review the underlying professional service.',
                        'review_status' => 'needs_review',
                    ]
                );
                $journalId = periodLedgerTestInsertJournal(
                    $companyId,
                    $periodId,
                    '2025-07-02',
                    'transaction:987654',
                    [
                        [$professionalNominalId, 406.0, 0.0],
                        [(int)$fixture['asset_nominal_id'], 0.0, 406.0],
                    ],
                    'bank_csv'
                );
                $lineId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM journal_lines WHERE journal_id = :journal_id AND nominal_account_id = :nominal_id',
                    ['journal_id' => $journalId, 'nominal_id' => $professionalNominalId]
                );

                $before = $service->fetchReview($companyId, $periodId);
                $h->assertSame(1, (int)$before['unresolved_count']);
                $h->assertSame('Transaction #987654', (string)$before['items'][0]['source_label']);
                $h->assertTrue(str_contains((string)$before['items'][0]['guidance_url'], 'bim35500'));

                $saved = $service->save($companyId, $periodId, $lineId, 'disallowable', 'test:user');
                $h->assertSame(true, (bool)$saved['success']);
                $after = $service->fetchReview($companyId, $periodId);
                $h->assertSame(0, (int)$after['unresolved_count']);
                $h->assertSame('resolved', (string)$after['items'][0]['state']);
                $h->assertSame('disallowable', (string)$after['items'][0]['tax_treatment']);

                $profit = (new \eel_accounts\Service\PreTaxProfitLossService())->calculate(
                    $companyId,
                    $periodId,
                    '2025-12-31',
                    '2025-01-01',
                    [],
                    []
                );
                $h->assertSame(0, (int)$profit['other_treatment_count']);
                $h->assertSame('456.00', number_format((float)$profit['disallowable_add_backs'], 2, '.', ''));

                $service->save($companyId, $periodId, $lineId, 'capital', 'test:user');
                $h->assertSame(2, (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM corporation_tax_line_treatment_decisions WHERE journal_line_id = :line_id',
                    ['line_id' => $lineId]
                ));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);
