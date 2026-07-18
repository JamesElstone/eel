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
$harness->run(
    \eel_accounts\Service\NonAssetReviewService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\NonAssetReviewService $service
    ): void {
        $harness->check(\eel_accounts\Service\NonAssetReviewService::class, 'returns missing entry context without loading the checklist', static function () use ($harness, $service): void {
            $context = $service->fetchContext(0, 0, 0, 250);

            $harness->assertSame(false, (bool)($context['data_entry']['permitted'] ?? true));
            $harness->assertSame('missing_context', (string)($context['data_entry']['reason_code'] ?? ''));
            $harness->assertSame(0, (int)($context['candidates']['count'] ?? -1));
            $harness->assertSame(null, $context['acknowledgement']);
        });

        $harness->check(\eel_accounts\Service\NonAssetReviewService::class, 'uses one fixed-asset basis for current and stale acknowledgement states', static function () use ($harness, $service): void {
            if (!InterfaceDB::tableExists('year_end_review_acknowledgements')
                || !InterfaceDB::columnExists('year_end_review_acknowledgements', 'basis_hash')) {
                $harness->skip('Year End acknowledgement basis storage is not available.');
            }

            $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
            $emptyCandidates = ['available' => true, 'threshold' => 250, 'rows' => [], 'count' => 0];
            $basis = $service->buildAcknowledgementBasis($emptyCandidates, 250, 0);

            $harness->assertSame('fixed_asset_review_placeholder', (string)($basis['check_code'] ?? ''));
            $harness->assertSame(0, (int)($basis['facts']['candidate_count'] ?? -1));
            $harness->assertSame('250.00', (string)($basis['facts']['threshold'] ?? ''));

            InterfaceDB::beginTransaction();
            try {
                $marker = strtoupper(substr(hash('sha256', __FILE__ . microtime(true)), 0, 10));
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                    ['company_name' => 'Non Asset Review Fixture Limited', 'company_number' => 'NA' . $marker]
                );
                $companyId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM companies WHERE company_number = :company_number',
                    ['company_number' => 'NA' . $marker]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                     VALUES (:company_id, :label, :period_start, :period_end)',
                    [
                        'company_id' => $companyId,
                        'label' => 'Non Asset Review Fixture',
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ]
                );
                $accountingPeriodId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
                    ['company_id' => $companyId, 'label' => 'Non Asset Review Fixture']
                );
                $saved = $acknowledgements->save(
                    $companyId,
                    $accountingPeriodId,
                    \eel_accounts\Service\NonAssetReviewService::CHECK_CODE,
                    $basis,
                    'non_asset_review_test'
                );
                $harness->assertSame(true, (bool)($saved['success'] ?? false));

                $current = $service->fetchContext($companyId, $accountingPeriodId, 0, 250);
                $harness->assertSame(true, (bool)($current['data_entry']['permitted'] ?? false));
                $harness->assertSame('current', (string)($current['acknowledgement']['state'] ?? ''));
                $harness->assertSame(true, (bool)($current['acknowledgement']['current'] ?? false));

                $stale = $service->fetchContext($companyId, $accountingPeriodId, 0, 500);
                $harness->assertSame('stale', (string)($stale['acknowledgement']['state'] ?? ''));
                $harness->assertSame(false, (bool)($stale['acknowledgement']['current'] ?? true));
            } finally {
                InterfaceDB::rollBack();
            }
        });
    }
);
