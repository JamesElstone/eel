<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(\eel_accounts\Service\DirectorLoanReconciliationService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanReconciliationService $service
): void {
    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'prefers subtype nominal before code fallback', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'chooseDirectorLoanNominal');
        $method->setAccessible(true);

        $chosen = $method->invoke($service, [
            ['id' => 12, 'code' => '1200', 'name' => 'Fallback Asset', 'account_type' => 'asset', 'subtype_code' => ''],
            ['id' => 99, 'code' => '1999', 'name' => 'Subtype Asset', 'account_type' => 'asset', 'subtype_code' => 'director_loan_asset'],
        ], 'director_loan_asset', '1200', 'asset');

        $harness->assertSame(99, (int)($chosen['id'] ?? 0));

        $fallback = $method->invoke($service, [
            ['id' => 12, 'code' => '1200', 'name' => 'Fallback Asset', 'account_type' => 'asset', 'subtype_code' => ''],
        ], 'director_loan_asset', '1200', 'asset');

        $harness->assertSame(12, (int)($fallback['id'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'calculates proposed offset from normal balances', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 10000.00, 0.00, 'asset-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 15000.00, 'liability-payable');

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($result['available'] ?? false));
            $harness->assertSame(10000.00, (float)($result['asset_receivable'] ?? 0));
            $harness->assertSame(15000.00, (float)($result['liability_payable'] ?? 0));
            $harness->assertSame(10000.00, (float)($result['required_offset_amount'] ?? 0));
            $harness->assertSame(0.00, (float)($result['desired_offset_amount'] ?? -1));
            $harness->assertSame(0.00, (float)($result['pending_adjustment_amount'] ?? -1));
            $harness->assertSame(0.00, (float)($result['offset_amount'] ?? -1));
            $harness->assertSame(5000.00, (float)($result['net_position'] ?? 0));
            $harness->assertSame('gross_presentation', (string)($result['offset_status'] ?? ''));
            $harness->assertSame(true, (bool)($result['offset_candidate_available'] ?? false));
            $harness->assertSame(false, (bool)($result['can_post'] ?? true));
            $harness->assertSame(false, (bool)($result['set_off_evidence_current'] ?? true));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'returns no proposed offset when only one side has a balance', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 750.00, 'liability-only');

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($result['available'] ?? false));
            $harness->assertSame(0.00, (float)($result['offset_amount'] ?? 0));
            $harness->assertSame('not_required', (string)($result['offset_status'] ?? ''));
            $harness->assertSame(false, (bool)($result['can_post'] ?? true));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'rolls prior period balances into the closing offset proposal', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            // Synthetic figures mirror the closing-balance behaviour without publishing company data.
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 286.19, 0.00, 'synthetic-prior-receivable', (int)$fixture['prior_accounting_period_id'], '2024-12-31');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 2074.83, 'synthetic-prior-payable', (int)$fixture['prior_accounting_period_id'], '2024-12-31');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 3462.71, 0.00, 'synthetic-current-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 7931.58, 'synthetic-current-payable');

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(3748.90, (float)($result['asset_receivable'] ?? 0));
            $harness->assertSame(10006.41, (float)($result['liability_payable'] ?? 0));
            $harness->assertSame(3748.90, (float)($result['required_offset_amount'] ?? 0));
            $harness->assertSame(0.00, (float)($result['offset_amount'] ?? -1));
            $harness->assertSame(6257.51, (float)($result['net_position'] ?? 0));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'confirmation context includes lightweight tax review', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 500.00, 0.00, 'director-owes-company');

            $result = $service->fetchYearEndConfirmationContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $taxReview = (array)($result['tax_review'] ?? []);

            $harness->assertSame(true, (bool)($result['available'] ?? false));
            $harness->assertSame(true, (bool)($taxReview['available'] ?? false));
            $harness->assertSame('review_required', (string)($taxReview['status'] ?? ''));
            $harness->assertSame(500.00, (float)($taxReview['exposure_amount'] ?? 0));
            $harness->assertSame(false, array_key_exists('statement', $taxReview));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'excludes existing offset journal and identifies current status', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability-payable');
            directorLoanReconciliationTestApproveOffset($service, $fixture);

            $postResult = $service->postOffset((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            $harness->assertSame(true, (bool)($postResult['success'] ?? false));

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(0.00, (float)($result['offset_amount'] ?? -1));
            $harness->assertSame(1000.00, (float)($result['required_offset_amount'] ?? 0));
            $harness->assertSame(1000.00, (float)($result['posted_offset_amount'] ?? 0));
            $harness->assertSame('current', (string)($result['offset_status'] ?? ''));
            $harness->assertSame(true, (bool)($result['current_offset_journal_posted'] ?? false));
            $harness->assertSame(true, (bool)($result['set_off_evidence_current'] ?? false));
            $harness->assertSame(false, (bool)($result['can_post'] ?? true));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'rejects revoking evidence while the current offset journal remains posted', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $companyId = (int)$fixture['company_id'];
            $accountingPeriodId = (int)$fixture['accounting_period_id'];
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability-payable');
            directorLoanReconciliationTestApproveOffset($service, $fixture);
            $service->postOffset($companyId, $accountingPeriodId, 'test');

            $result = $service->saveSetOffEvidence(
                $companyId,
                $accountingPeriodId,
                false,
                false,
                false,
                '',
                'test'
            );
            $acknowledgement = (new \eel_accounts\Service\YearEndAcknowledgementService())->fetch(
                $companyId,
                $accountingPeriodId,
                \eel_accounts\Service\DirectorLoanReconciliationService::SET_OFF_ACKNOWLEDGEMENT_CODE
            );
            $context = $service->fetchContext($companyId, $accountingPeriodId);

            $harness->assertSame(false, (bool)($result['success'] ?? true));
            $harness->assertSame(422, (int)($result['status'] ?? 0));
            $harness->assertSame(
                true,
                str_contains(implode(' ', array_map('strval', (array)($result['errors'] ?? []))), 'remains posted')
            );
            $harness->assertSame(true, is_array($acknowledgement));
            $harness->assertSame('current', (string)($context['offset_status'] ?? ''));
            $harness->assertSame(true, (bool)($context['set_off_evidence_current'] ?? false));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'allows revoking evidence before an offset journal is posted', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $companyId = (int)$fixture['company_id'];
            $accountingPeriodId = (int)$fixture['accounting_period_id'];
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability-payable');
            directorLoanReconciliationTestApproveOffset($service, $fixture);

            $result = $service->saveSetOffEvidence(
                $companyId,
                $accountingPeriodId,
                false,
                false,
                false,
                '',
                'test'
            );
            $acknowledgement = (new \eel_accounts\Service\YearEndAcknowledgementService())->fetch(
                $companyId,
                $accountingPeriodId,
                \eel_accounts\Service\DirectorLoanReconciliationService::SET_OFF_ACKNOWLEDGEMENT_CODE
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(null, $acknowledgement);
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'stale evidence makes the signed pending adjustment reverse the posted offset to gross', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability-payable');
            directorLoanReconciliationTestApproveOffset($service, $fixture);
            $service->postOffset((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');

            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 250.00, 0.00, 'asset-increase');

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(-1000.00, (float)($result['offset_amount'] ?? 0));
            $harness->assertSame(-1000.00, (float)($result['pending_adjustment_amount'] ?? 0));
            $harness->assertSame(1250.00, (float)($result['required_offset_amount'] ?? 0));
            $harness->assertSame(1000.00, (float)($result['posted_offset_amount'] ?? 0));
            $harness->assertSame('stale', (string)($result['offset_status'] ?? ''));
            $harness->assertSame(false, (bool)($result['set_off_evidence_current'] ?? true));
            $harness->assertSame(true, (bool)($result['can_post'] ?? false));
            $harness->assertSame(true, (bool)($result['offset_candidate_available'] ?? false));
            $harness->assertSame(true, (bool)($result['offset_journal_posted'] ?? false));

            $revoke = $service->saveSetOffEvidence(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                false,
                false,
                false,
                '',
                'test'
            );
            $harness->assertSame(false, (bool)($revoke['success'] ?? true));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'carries a prior offset without contaminating next-period gross balances and needs fresh evidence', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $companyId = (int)$fixture['company_id'];
            $priorPeriodId = (int)$fixture['prior_accounting_period_id'];
            $currentPeriodId = (int)$fixture['accounting_period_id'];
            directorLoanReconciliationTestInsertLineJournal(
                $fixture,
                (int)$fixture['asset_nominal_id'],
                1000.00,
                0.00,
                'prior-asset',
                $priorPeriodId,
                '2024-12-31'
            );
            directorLoanReconciliationTestInsertLineJournal(
                $fixture,
                (int)$fixture['liability_nominal_id'],
                0.00,
                1500.00,
                'prior-liability',
                $priorPeriodId,
                '2024-12-31'
            );
            directorLoanReconciliationTestApproveOffset($service, $fixture, $priorPeriodId);
            $posted = $service->postOffset($companyId, $priorPeriodId, 'test');
            $harness->assertSame(true, (bool)($posted['success'] ?? false));

            $beforeFreshEvidence = $service->fetchContext($companyId, $currentPeriodId);
            $harness->assertSame(1000.00, (float)($beforeFreshEvidence['asset_receivable'] ?? 0));
            $harness->assertSame(1500.00, (float)($beforeFreshEvidence['liability_payable'] ?? 0));
            $harness->assertSame(1000.00, (float)($beforeFreshEvidence['required_offset_amount'] ?? 0));
            $harness->assertSame(1000.00, (float)($beforeFreshEvidence['posted_offset_amount'] ?? 0));
            $harness->assertSame(0.00, (float)($beforeFreshEvidence['desired_offset_amount'] ?? -1));
            $harness->assertSame(-1000.00, (float)($beforeFreshEvidence['pending_adjustment_amount'] ?? 0));

            directorLoanReconciliationTestApproveOffset($service, $fixture, $currentPeriodId);
            $afterFreshEvidence = $service->fetchContext($companyId, $currentPeriodId);
            $harness->assertSame(true, (bool)($afterFreshEvidence['closing_balance_acknowledged'] ?? false));
            $harness->assertSame(true, (bool)($afterFreshEvidence['set_off_evidence_current'] ?? false));
            $harness->assertSame(1000.00, (float)($afterFreshEvidence['desired_offset_amount'] ?? 0));
            $harness->assertSame(0.00, (float)($afterFreshEvidence['pending_adjustment_amount'] ?? -1));

            $idempotent = $service->postOffset($companyId, $currentPeriodId, 'test');
            $harness->assertSame(true, (bool)($idempotent['success'] ?? false));
            $harness->assertSame(true, (bool)($idempotent['already_current'] ?? false));
            $harness->assertSame(1, directorLoanReconciliationTestOffsetJournalCount($companyId));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'stale evidence with unchanged required amount is reversed to gross before lock', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $companyId = (int)$fixture['company_id'];
            $accountingPeriodId = (int)$fixture['accounting_period_id'];
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability');
            directorLoanReconciliationTestApproveOffset($service, $fixture);
            $service->postOffset($companyId, $accountingPeriodId, 'test');

            directorLoanReconciliationTestInsertLineJournal(
                $fixture,
                $fixture['liability_nominal_id'],
                0.00,
                200.00,
                'liability-evidence-change'
            );
            $stale = $service->fetchContext($companyId, $accountingPeriodId);
            $harness->assertSame(1000.00, (float)($stale['required_offset_amount'] ?? 0));
            $harness->assertSame(false, (bool)($stale['set_off_evidence_current'] ?? true));
            $harness->assertSame(-1000.00, (float)($stale['pending_adjustment_amount'] ?? 0));

            $checklist = new \eel_accounts\Service\YearEndChecklistService();
            $apply = new ReflectionMethod($checklist, 'applyDirectorLoanOffsetBeforeLock');
            $apply->setAccessible(true);
            $result = $apply->invoke($checklist, $companyId, $accountingPeriodId, ['checks_flat' => []], 'test');
            $harness->assertSame(true, (bool)($result['success'] ?? false));

            $gross = $service->fetchContext($companyId, $accountingPeriodId);
            $harness->assertSame(0.00, (float)($gross['posted_offset_amount'] ?? -1));
            $harness->assertSame(0.00, (float)($gross['pending_adjustment_amount'] ?? -1));
            $harness->assertSame('gross_presentation', (string)($gross['offset_status'] ?? ''));
            $harness->assertSame(false, (bool)($gross['offset_journal_posted'] ?? true));
            $harness->assertSame(false, (bool)($gross['current_offset_journal_posted'] ?? true));
            $harness->assertSame(2, directorLoanReconciliationTestOffsetJournalCount($companyId));

            $revoked = $service->saveSetOffEvidence(
                $companyId,
                $accountingPeriodId,
                false,
                false,
                false,
                '',
                'test'
            );
            $harness->assertSame(true, (bool)($revoked['success'] ?? false));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'appends exact increase and decrease deltas and repeated posting is idempotent', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $companyId = (int)$fixture['company_id'];
            $accountingPeriodId = (int)$fixture['accounting_period_id'];
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability');
            directorLoanReconciliationTestApproveOffset($service, $fixture);
            $service->postOffset($companyId, $accountingPeriodId, 'test');

            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 250.00, 0.00, 'asset-increase');
            directorLoanReconciliationTestApproveOffset($service, $fixture);
            $increase = $service->fetchContext($companyId, $accountingPeriodId);
            $harness->assertSame(250.00, (float)($increase['pending_adjustment_amount'] ?? 0));
            $service->postOffset($companyId, $accountingPeriodId, 'test');
            $harness->assertSame(1250.00, directorLoanReconciliationTestCumulativeOffset($companyId, $fixture));

            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 0.00, 350.00, 'asset-decrease');
            directorLoanReconciliationTestApproveOffset($service, $fixture);
            $decrease = $service->fetchContext($companyId, $accountingPeriodId);
            $harness->assertSame(-350.00, (float)($decrease['pending_adjustment_amount'] ?? 0));
            $service->postOffset($companyId, $accountingPeriodId, 'test');
            $harness->assertSame(900.00, directorLoanReconciliationTestCumulativeOffset($companyId, $fixture));
            $harness->assertSame(3, directorLoanReconciliationTestOffsetJournalCount($companyId));

            $repeat = $service->postOffset($companyId, $accountingPeriodId, 'test');
            $harness->assertSame(true, (bool)($repeat['success'] ?? false));
            $harness->assertSame(true, (bool)($repeat['already_current'] ?? false));
            $harness->assertSame(3, directorLoanReconciliationTestOffsetJournalCount($companyId));
            $harness->assertSame(900.00, directorLoanReconciliationTestCumulativeOffset($companyId, $fixture));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'requires both FRS 105 criteria and a supporting note', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability-payable');

            $oneCriterion = $service->saveSetOffEvidence(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                true,
                true,
                false,
                'Agreement reviewed.',
                'test'
            );
            $noNote = $service->saveSetOffEvidence(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                true,
                true,
                true,
                '',
                'test'
            );
            $complete = $service->saveSetOffEvidence(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                true,
                true,
                true,
                'Executed agreement clause 4 and simultaneous settlement instruction.',
                'test'
            );

            $harness->assertSame(false, (bool)($oneCriterion['success'] ?? true));
            $harness->assertSame(false, (bool)($noNote['success'] ?? true));
            $harness->assertSame(true, (bool)($complete['success'] ?? false));
            $harness->assertSame(
                true,
                (bool)(($service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']))['set_off_evidence_current'] ?? false)
            );
        });
    });
});

function directorLoanReconciliationTestWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    if (!InterfaceDB::tableExists('nominal_accounts') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines')) {
        $harness->skip('Ledger tables are not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();
    try {
        StandardNominalTestFixture::ensureNominals(['1200', '2100']);
        $assetNominalId = StandardNominalTestFixture::id('1200');
        $liabilityNominalId = StandardNominalTestFixture::id('2100');

        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
            ['company_name' => 'Director Loan Offset Fixture Limited', 'company_number' => 'DLO' . $marker]
        );
        $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'DLO' . $marker]);
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'DLO Prior ' . $marker,
                'period_start' => '2024-01-01',
                'period_end' => '2024-12-31',
            ]
        );
        $priorAccountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'DLO Prior ' . $marker]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'DLO ' . $marker,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
            ]
        );
        $accountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'DLO ' . $marker]
        );
        $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $settings->set('director_loan_asset_nominal_id', $assetNominalId, 'int');
        $settings->set('director_loan_liability_nominal_id', $liabilityNominalId, 'int');
        $settings->flush();

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'prior_accounting_period_id' => $priorAccountingPeriodId,
            'accounting_period_id' => $accountingPeriodId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function directorLoanReconciliationTestInsertLineJournal(array $fixture, int $nominalId, float $debit, float $credit, string $key, ?int $accountingPeriodId = null, ?string $journalDate = null): void
{
    $sourceRef = 'test-director-loan-offset:' . $fixture['marker'] . ':' . $key;
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => $accountingPeriodId ?? (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $journalDate ?? '2025-12-31',
            'description' => 'Director loan test fixture ' . $key,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_type = :source_type AND source_ref = :source_ref',
        [
            'company_id' => (int)$fixture['company_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalId,
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'line_description' => 'Director loan test fixture',
        ]
    );
}

function directorLoanReconciliationTestApproveOffset(
    \eel_accounts\Service\DirectorLoanReconciliationService $service,
    array $fixture,
    ?int $accountingPeriodId = null
): void {
    $companyId = (int)$fixture['company_id'];
    $accountingPeriodId ??= (int)$fixture['accounting_period_id'];
    $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
    $directorLoanSummary = (new \eel_accounts\Service\YearEndMetricsService())
        ->directorLoanSummary($companyId, $accountingPeriodId);
    $acknowledgements->save(
        $companyId,
        $accountingPeriodId,
        'director_loan_closing_balance',
        $acknowledgements->buildBasis('director_loan_closing_balance', [
            'closing_balance' => number_format((float)($directorLoanSummary['closing_balance'] ?? 0), 2, '.', ''),
        ]),
        'test',
        'Closing balance agreed.'
    );
    $service->saveSetOffEvidence(
        $companyId,
        $accountingPeriodId,
        true,
        true,
        true,
        'Executed agreement clause 4 and simultaneous settlement instruction.',
        'test'
    );
}

function directorLoanReconciliationTestOffsetJournalCount(int $companyId): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM journal_entry_metadata
         WHERE company_id = :company_id
           AND journal_tag = :journal_tag',
        [
            'company_id' => $companyId,
            'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
        ]
    );
}

function directorLoanReconciliationTestCumulativeOffset(int $companyId, array $fixture): float
{
    $liabilitySigned = (float)InterfaceDB::fetchColumn(
        'SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
         FROM journal_entry_metadata jem
         INNER JOIN journal_lines jl ON jl.journal_id = jem.journal_id
         WHERE jem.company_id = :company_id
           AND jem.journal_tag = :journal_tag
           AND jl.nominal_account_id = :nominal_account_id',
        [
            'company_id' => $companyId,
            'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
            'nominal_account_id' => (int)$fixture['liability_nominal_id'],
        ]
    );

    return round($liabilitySigned, 2);
}
