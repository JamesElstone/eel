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
                $history = $service->fetchReview($companyId, $periodId)['items'][0]['decision_history'];
                $h->assertSame(2, count($history));
                $h->assertSame('capital', (string)$history[0]['tax_treatment']);
                $h->assertSame('disallowable', (string)$history[1]['tax_treatment']);
                $h->assertSame('current', (string)$history[0]['basis_status']);
                $h->assertSame('historical', (string)$history[1]['basis_status']);

                InterfaceDB::prepareExecute(
                    'UPDATE journal_lines SET debit = :debit WHERE id = :line_id',
                    ['debit' => 407.0, 'line_id' => $lineId]
                );
                $staleReview = $service->fetchReview($companyId, $periodId);
                $h->assertSame('stale', (string)$staleReview['items'][0]['state']);
                $h->assertSame('stale', (string)$staleReview['items'][0]['decision_history'][0]['basis_status']);
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $h->check($service::class, 'scopes review items to the selected Corporation Tax period', static function () use ($h): void {
            InterfaceDB::beginTransaction();
            try {
                $fixture = periodLedgerTestCreateFixture();
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];
                $marker = substr(hash('sha256', (string)microtime(true)), 0, 10);
                $nominalId = periodLedgerTestInsertNominal('PS' . $marker, 'Scoped Professional Fees ' . $marker, 'expense', 'allowable');
                InterfaceDB::prepareExecute(
                    'INSERT INTO corporation_tax_treatment_rules (
                        rule_code, rule_version, priority, account_type, name_contains,
                        tax_treatment, rationale, review_status, is_active
                     ) VALUES (
                        :rule_code, :rule_version, 1, :account_type, :name_contains,
                        :tax_treatment, :rationale, :review_status, 1
                     )',
                    [
                        'rule_code' => 'scoped_review_' . $marker,
                        'rule_version' => 'test-v1',
                        'account_type' => 'expense',
                        'name_contains' => $marker,
                        'tax_treatment' => 'other',
                        'rationale' => 'Scoped CT period fixture.',
                        'review_status' => 'needs_review',
                    ]
                );
                foreach ([
                    [1, '2025-01-01', '2025-06-30'],
                    [2, '2025-07-01', '2025-12-31'],
                ] as [$sequence, $start, $end]) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO corporation_tax_periods (
                            company_id, accounting_period_id, sequence_no, period_start, period_end
                         ) VALUES (
                            :company_id, :accounting_period_id, :sequence_no, :period_start, :period_end
                         )',
                        [
                            'company_id' => $companyId,
                            'accounting_period_id' => $periodId,
                            'sequence_no' => $sequence,
                            'period_start' => $start,
                            'period_end' => $end,
                        ]
                    );
                }
                periodLedgerTestInsertJournal($companyId, $periodId, '2025-03-01', 'scope-first-' . $marker, [
                    [$nominalId, 10.0, 0.0], [(int)$fixture['asset_nominal_id'], 0.0, 10.0],
                ]);
                periodLedgerTestInsertJournal($companyId, $periodId, '2025-10-01', 'scope-second-' . $marker, [
                    [$nominalId, 20.0, 0.0], [(int)$fixture['asset_nominal_id'], 0.0, 20.0],
                ]);
                $periodIds = array_map('intval', array_column(InterfaceDB::fetchAll(
                    'SELECT id FROM corporation_tax_periods WHERE accounting_period_id = :period_id ORDER BY sequence_no',
                    ['period_id' => $periodId]
                ), 'id'));
                $review = new \eel_accounts\Service\CorporationTaxLineTreatmentService();
                $h->assertSame(2, (int)$review->fetchReview($companyId, $periodId, 0)['unresolved_count']);
                $h->assertSame(1, (int)$review->fetchReview($companyId, $periodId, $periodIds[0])['unresolved_count']);
                $h->assertSame(1, (int)$review->fetchReview($companyId, $periodId, $periodIds[1])['unresolved_count']);
                $h->assertSame(false, !empty($review->fetchReview($companyId, $periodId, 999999)['available']));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
                \eel_accounts\Support\RequestCache::clear();
            }
        });

        $h->check($service::class, 'uses the persisted review summary after the accounting period is locked', static function () use ($h): void {
            InterfaceDB::beginTransaction();
            try {
                $fixture = periodLedgerTestCreateFixture();
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];
                InterfaceDB::prepareExecute(
                    'INSERT INTO corporation_tax_periods (
                        company_id, accounting_period_id, sequence_no, period_start, period_end
                     ) VALUES (
                        :company_id, :accounting_period_id, 1, :period_start, :period_end
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ]
                );
                $ctPeriodId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM corporation_tax_periods WHERE accounting_period_id = :period_id LIMIT 1',
                    ['period_id' => $periodId]
                );
                $summary = [
                    'available' => true,
                    'company_id' => $companyId,
                    'accounting_period_id' => $periodId,
                    'ct_period_id' => $ctPeriodId,
                    'other_treatment_count' => 0,
                    'other_treatment_amount' => 0.0,
                ];
                $hash = hash('sha256', 'frozen-review-' . $ctPeriodId);
                InterfaceDB::prepareExecute(
                    'INSERT INTO corporation_tax_computation_runs (
                        company_id, accounting_period_id, ct_period_id, period_start, period_end,
                        status, computation_hash, summary_json
                     ) VALUES (
                        :company_id, :accounting_period_id, :ct_period_id, :period_start, :period_end,
                        :status, :computation_hash, :summary_json
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                        'ct_period_id' => $ctPeriodId,
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                        'status' => 'generated',
                        'computation_hash' => $hash,
                        'summary_json' => json_encode($summary),
                    ]
                );
                $runId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM corporation_tax_computation_runs WHERE ct_period_id = :ct_period_id ORDER BY id DESC LIMIT 1',
                    ['ct_period_id' => $ctPeriodId]
                );
                InterfaceDB::prepareExecute(
                    'UPDATE corporation_tax_periods SET latest_computation_run_id = :run_id WHERE id = :id',
                    ['run_id' => $runId, 'id' => $ctPeriodId]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                     VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by)',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId, 'locked_by' => 'test']
                );
                \eel_accounts\Support\RequestCache::clear();

                $frozen = (new \eel_accounts\Service\CorporationTaxLineTreatmentService())
                    ->fetchReview($companyId, $periodId, 0);
                $h->assertSame(true, !empty($frozen['available']));
                $h->assertSame(true, !empty($frozen['read_only']));
                $h->assertSame('locked_snapshot', (string)$frozen['basis_source']);
                $h->assertSame(0, (int)$frozen['unresolved_count']);
                $h->assertSame([], (array)$frozen['items']);

                $summary['other_treatment_count'] = 2;
                $summary['other_treatment_amount'] = 25.0;
                InterfaceDB::prepareExecute(
                    'UPDATE corporation_tax_computation_runs SET summary_json = :summary_json WHERE id = :id',
                    ['summary_json' => json_encode($summary), 'id' => $runId]
                );
                \eel_accounts\Support\RequestCache::clear();
                $legacyUnresolved = (new \eel_accounts\Service\CorporationTaxLineTreatmentService())
                    ->fetchReview($companyId, $periodId, 0);
                $h->assertSame(2, (int)$legacyUnresolved['unresolved_count']);
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
                \eel_accounts\Support\RequestCache::clear();
            }
        });
    }
);
