<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\JournalCutOffReviewService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\JournalCutOffReviewService $service): void {
    $harness->check(\eel_accounts\Service\JournalCutOffReviewService::class, 'returns missing-context access state without an acknowledgement', static function () use ($harness, $service): void {
        $context = $service->fetchContext(0, 0);

        $harness->assertSame(null, $context['acknowledgement'] ?? null);
        $harness->assertSame(false, (bool)($context['access']['permitted'] ?? true));
        $harness->assertSame('missing_context', (string)($context['access']['reason_code'] ?? ''));
    });

    $harness->check(\eel_accounts\Service\JournalCutOffReviewService::class, 'returns unlocked access for an unacknowledged deterministic period', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
            $companyNumber = 'JC' . $marker;
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active)
                 VALUES (:company_name, :company_number, 1)',
                ['company_name' => 'Journal Cut Off Fixture ' . $marker, 'company_number' => $companyNumber]
            );
            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'Journal Cut Off FY',
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                ]
            );
            $accountingPeriodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
                ['company_id' => $companyId]
            );

            $context = $service->fetchContext($companyId, $accountingPeriodId);
            $harness->assertSame(null, $context['acknowledgement'] ?? null);
            $harness->assertSame(true, (bool)($context['access']['permitted'] ?? false));
            $harness->assertSame(false, (bool)($context['access']['is_locked'] ?? true));
            $harness->assertSame('', (string)($context['access']['reason_code'] ?? 'missing'));

            $basisResult = $service->fetchApprovalBasis($companyId, $accountingPeriodId);
            $harness->assertSame(true, (bool)($basisResult['available'] ?? false));
            $harness->assertSame('cut_off_journals_review', (string)(($basisResult['basis'] ?? [])['check_code'] ?? ''));

            $checklist = (new \eel_accounts\Service\YearEndChecklistService())->fetchChecklist($companyId, $accountingPeriodId);
            $checklistBasis = null;
            foreach ((array)($checklist['sections'] ?? []) as $checks) {
                foreach ((array)$checks as $check) {
                    if ((string)($check['check_code'] ?? '') === 'cut_off_journals_review') {
                        $checklistBasis = $check['basis_data'] ?? null;
                    }
                }
            }
            $harness->assertSame(
                (new \eel_accounts\Service\YearEndAcknowledgementService())->hashBasis((array)$checklistBasis),
                (new \eel_accounts\Service\YearEndAcknowledgementService())->hashBasis((array)$basisResult['basis'])
            );

            $request = new stdClass();
            $scope = \eel_accounts\Support\RequestCache::beginFor($request);
            $firstQuestions = journalCutOffReviewQuestions();
            $first = $service->fetchApprovalBasis($companyId, $accountingPeriodId);
            $afterFirstQuestions = journalCutOffReviewQuestions();
            $second = $service->fetchApprovalBasis($companyId, $accountingPeriodId);
            $afterSecondQuestions = journalCutOffReviewQuestions();
            $harness->assertSame($first, $second);
            if (InterfaceDB::driverName() === 'mysql') {
                $harness->assertSame(true, ($afterSecondQuestions - $afterFirstQuestions) < ($afterFirstQuestions - $firstQuestions));
            }

            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'Journal Cut Off FY 2',
                    'period_start' => '2026-01-01',
                    'period_end' => '2026-12-31',
                ]
            );
            $secondPeriodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
                ['company_id' => $companyId]
            );
            $beforeDifferentPeriodQuestions = journalCutOffReviewQuestions();
            $differentPeriod = $service->fetchApprovalBasis($companyId, $secondPeriodId);
            $afterDifferentPeriodQuestions = journalCutOffReviewQuestions();
            $harness->assertSame(true, (bool)($differentPeriod['available'] ?? false));
            if (InterfaceDB::driverName() === 'mysql') {
                $harness->assertSame(true, ($afterDifferentPeriodQuestions - $beforeDifferentPeriodQuestions) > ($afterSecondQuestions - $afterFirstQuestions));
            }

            \eel_accounts\Support\RequestCache::clear();
            $beforeFreshQuestions = journalCutOffReviewQuestions();
            $fresh = $service->fetchApprovalBasis($companyId, $accountingPeriodId);
            $afterFreshQuestions = journalCutOffReviewQuestions();
            $harness->assertSame($first, $fresh);
            if (InterfaceDB::driverName() === 'mysql') {
                $harness->assertSame(true, ($afterFreshQuestions - $beforeFreshQuestions) > ($afterSecondQuestions - $afterFirstQuestions));
            }
            unset($scope);
            \eel_accounts\Support\RequestCache::reset();

            $beforeDirectQuestions = journalCutOffReviewQuestions();
            $directFirst = $service->fetchApprovalBasis($companyId, $accountingPeriodId);
            $afterDirectFirstQuestions = journalCutOffReviewQuestions();
            $directSecond = $service->fetchApprovalBasis($companyId, $accountingPeriodId);
            $afterDirectSecondQuestions = journalCutOffReviewQuestions();
            $harness->assertSame($directFirst, $directSecond);
            if (InterfaceDB::driverName() === 'mysql') {
                $harness->assertSame(true, ($afterDirectFirstQuestions - $beforeDirectQuestions) > 1);
                $harness->assertSame(true, ($afterDirectSecondQuestions - $afterDirectFirstQuestions) > 1);
            }
        } finally {
            \eel_accounts\Support\RequestCache::reset();
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\JournalCutOffReviewService::class, 'rejects an unavailable approval basis', static function () use ($harness, $service): void {
        $result = $service->fetchApprovalBasis(999999999, 999999999);

        $harness->assertSame(false, (bool)($result['available'] ?? true));
        $harness->assertSame('missing', $result['basis'] ?? 'missing');
        $harness->assertSame(true, str_contains((string)(($result['errors'] ?? [])[0] ?? ''), 'could not be found'));
    });

    $harness->check(\eel_accounts\Service\JournalCutOffReviewService::class, 'keeps the approved cut-off review current through expected lock journals and stales it again when reopened', static function () use ($harness): void {
        foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'nominal_accounts', 'year_end_reviews', 'year_end_review_acknowledgements', 'year_end_section_review_bundles'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        $nominalAccountId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts ORDER BY id LIMIT 1');
        if ($nominalAccountId <= 0) {
            $harness->skip('A nominal account is required for the cut-off lock regression fixture.');
        }

        InterfaceDB::beginTransaction();
        try {
            $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
            $companyNumber = 'JCL' . $marker;
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active)
                 VALUES (:company_name, :company_number, 1)',
                ['company_name' => 'Journal Cut Off Lock Fixture ' . $marker, 'company_number' => $companyNumber]
            );
            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'Journal Cut Off Lock ' . $marker,
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                ]
            );
            $accountingPeriodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
                ['company_id' => $companyId]
            );

            $approvals = new \eel_accounts\Service\YearEndSectionApprovalService();
            $approved = $approvals->approve($companyId, $accountingPeriodId, 'cut_off_journals_review', [], 'lock-regression-test');
            $harness->assertSame(true, (bool)($approved['success'] ?? false));
            $before = (new \eel_accounts\Service\YearEndAcknowledgementService())
                ->fetch($companyId, $accountingPeriodId, 'cut_off_journals_review');
            $harness->assertSame(true, is_array($before));

            InterfaceDB::prepareExecute(
                'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                 VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'source_type' => 'director_loan_offset',
                    'source_ref' => 'lock-regression-offset-' . $marker,
                    'journal_date' => '2025-12-31',
                    'description' => 'Expected Director Loan lock journal',
                ]
            );
            $journalId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref LIMIT 1',
                ['company_id' => $companyId, 'source_ref' => 'lock-regression-offset-' . $marker]
            );
            foreach ([[25.00, 0.00], [0.00, 25.00]] as [$debit, $credit]) {
                InterfaceDB::prepareExecute(
                    'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                     VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
                    [
                        'journal_id' => $journalId,
                        'nominal_account_id' => $nominalAccountId,
                        'debit' => $debit,
                        'credit' => $credit,
                        'line_description' => 'Expected lock journal regression fixture',
                    ]
                );
            }
            InterfaceDB::prepareExecute(
                'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked)
                 VALUES (:company_id, :accounting_period_id, 1)',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
            \eel_accounts\Support\RequestCache::clear();

            $approvedPreClosePosition = new ReflectionMethod($approvals, 'approvedPreClosePosition');
            $harness->assertSame(true, (bool)$approvedPreClosePosition->invoke($approvals, $companyId, $accountingPeriodId, 'cut_off_journals_review'));
            $harness->assertSame(true, (bool)$approvedPreClosePosition->invoke($approvals, $companyId, $accountingPeriodId, 'tax_readiness_acknowledgement'));
            $harness->assertSame(true, (bool)$approvedPreClosePosition->invoke($approvals, $companyId, $accountingPeriodId, 'companies_house_mismatch_acknowledgement'));
            $harness->assertSame(false, (bool)$approvedPreClosePosition->invoke($approvals, $companyId, $accountingPeriodId, 'director_loan_year_end_review'));

            $lockedReview = $approvals->fetchReview($companyId, $accountingPeriodId, 'cut_off_journals_review');
            $harness->assertSame(true, (bool)($lockedReview['acknowledgement_current'] ?? false));
            $lockedChecklist = (new \eel_accounts\Service\YearEndChecklistService())->fetchChecklist($companyId, $accountingPeriodId);
            $lockedCheck = array_values(array_filter(
                (array)($lockedChecklist['checks_flat'] ?? []),
                static fn(array $check): bool => (string)($check['check_code'] ?? '') === 'cut_off_journals_review'
            ))[0] ?? [];
            $harness->assertSame('pass', (string)($lockedCheck['status'] ?? ''));
            $harness->assertSame(false, str_contains((string)($lockedCheck['detail_text'] ?? ''), 'underlying data changed'));

            $after = (new \eel_accounts\Service\YearEndAcknowledgementService())
                ->fetch($companyId, $accountingPeriodId, 'cut_off_journals_review');
            $harness->assertSame((string)($before['acknowledged_at'] ?? ''), (string)($after['acknowledged_at'] ?? ''));
            $harness->assertSame((string)($before['acknowledged_by'] ?? ''), (string)($after['acknowledged_by'] ?? ''));

            InterfaceDB::prepareExecute(
                'UPDATE year_end_reviews SET is_locked = 0 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
            \eel_accounts\Support\RequestCache::clear();
            $reopenedReview = $approvals->fetchReview($companyId, $accountingPeriodId, 'cut_off_journals_review');
            $harness->assertSame(false, (bool)($reopenedReview['acknowledgement_current'] ?? true));
            $harness->assertSame('stale', (string)($reopenedReview['acknowledgement_state'] ?? ''));
        } finally {
            \eel_accounts\Support\RequestCache::reset();
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function journalCutOffReviewQuestions(): int
{
    if (InterfaceDB::driverName() !== 'mysql') {
        return 0;
    }

    $row = InterfaceDB::fetchOne("SHOW SESSION STATUS LIKE 'Questions'");
    return (int)($row['Value'] ?? $row['value'] ?? 0);
}
