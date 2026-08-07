<?php
/**
 * Deterministic, SQLite-only AP79-equivalent fixture for the unified filing
 * approval workflow.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IxbrlTestFixture.php';

final class UnifiedApprovalWorkflowTestFixture
{
    private const ACTOR = 'unified-approval-fixture';

    /** @return array{company_id:int,accounting_period_id:int,stale_approval_id:int} */
    public static function seed(): array
    {
        if (InterfaceDB::driverName() !== 'sqlite') {
            throw new RuntimeException('The unified approval fixture is restricted to isolated SQLite tests.');
        }

        GoldenAccountsFixture::build();
        $companyId = GoldenAccountsFixture::CT600A_COMPANY_ID;
        $accountingPeriodId = GoldenAccountsFixture::CT600A_ACCOUNTING_PERIOD_ID;
        self::resetPriorWorkflowState($companyId, $accountingPeriodId);
        self::prepareLockedCorporationTaxBasis($companyId, $accountingPeriodId);
        ixbrl_test_complete_disclosures($companyId, $accountingPeriodId, self::ACTOR);
        self::ensureReturnAuthorisation($companyId, $accountingPeriodId);

        $accounts = new \eel_accounts\Service\IxbrlAccountsFilingApprovalService();
        \eel_accounts\Support\RequestCache::reset();
        $accountsStatus = $accounts->status($companyId, $accountingPeriodId);
        $approval = is_array($accountsStatus['approval'] ?? null)
            ? (array)$accountsStatus['approval']
            : [];
        if (empty($accountsStatus['current'])
            || (string)($approval['basis_version'] ?? '') !== $accounts::BASIS_VERSION) {
            $created = $accounts->approveAndBuildFacts(
                $companyId,
                $accountingPeriodId,
                self::ACTOR,
                'Current native basis used to construct the stale v8 fixture.'
            );
            $approvalId = (int)($created['approval_id'] ?? 0);
            $approval = (array)(InterfaceDB::fetchOne(
                'SELECT * FROM ixbrl_accounts_filing_approvals WHERE id = :id',
                ['id' => $approvalId]
            ) ?: []);
        }
        if ((int)($approval['id'] ?? 0) <= 0) {
            throw new RuntimeException('The AP79-equivalent native accounts approval could not be prepared.');
        }
        if ((int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM hmrc_ct_filing_approvals
             WHERE company_id = :company_id AND accounting_period_id = :period_id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        ) !== 0) {
            throw new RuntimeException('The AP79-equivalent fixture unexpectedly already has an HMRC approval.');
        }

        $staleApprovalId = self::convertApprovalToLegacyV8($approval, $companyId, $accountingPeriodId);
        self::protectLegacyApprovalHistory($staleApprovalId, $companyId, $accountingPeriodId);
        self::advanceDisclosureRevision($companyId, $accountingPeriodId);
        \eel_accounts\Support\RequestCache::reset();

        $workflow = (new \eel_accounts\Service\IxbrlFilingApprovalWorkflowService())->status(
            $companyId,
            $accountingPeriodId
        );
        if ((int)(($workflow['accounts'] ?? [])['approval_id'] ?? 0) !== $staleApprovalId
            || (string)((($workflow['accounts'] ?? [])['approval'] ?? [])['basis_version'] ?? '')
                !== 'accounts-filing-approval-v8'
            || !empty(($workflow['accounts'] ?? [])['native_current'])
            || (int)(($workflow['hmrc'] ?? [])['approval_id'] ?? 0) !== 0
            || !empty($workflow['both_current'])
            || empty($workflow['can_approve'])
            || (array)($workflow['blockers'] ?? []) !== []) {
            throw new RuntimeException(
                'The deterministic AP79-equivalent fixture did not resolve to stale v8/no-HMRC readiness: '
                . implode(' ', array_map('strval', (array)($workflow['blockers'] ?? [])))
            );
        }

        return [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'stale_approval_id' => $staleApprovalId,
        ];
    }

    /**
     * The test runner intentionally shares its SQLite database between files.
     * Restore the mutable approval boundary that an earlier golden lifecycle
     * may have completed, while retaining its reusable ledger fixture.
     */
    private static function resetPriorWorkflowState(int $companyId, int $accountingPeriodId): void
    {
        InterfaceDB::transaction(static function () use ($companyId, $accountingPeriodId): void {
            // The golden CT600A lifecycle deliberately leaves later-relief
            // evidence and an accepted first CT period behind. Remove that
            // scenario-only mutation before reconstructing the base fixture.
            InterfaceDB::prepareExecute(
                'DELETE FROM journal_lines
                 WHERE journal_id IN (
                     SELECT id FROM journals
                     WHERE id = :journal_id AND company_id = :company_id
                 )',
                ['journal_id' => 9855, 'company_id' => $companyId]
            );
            InterfaceDB::prepareExecute(
                'DELETE FROM journals
                 WHERE id = :journal_id AND company_id = :company_id',
                ['journal_id' => 9855, 'company_id' => $companyId]
            );
            InterfaceDB::prepareExecute(
                'DELETE FROM transactions
                 WHERE id = :transaction_id AND company_id = :company_id',
                ['transaction_id' => 9845, 'company_id' => $companyId]
            );
            InterfaceDB::prepareExecute(
                'DELETE FROM statement_uploads
                 WHERE id = :upload_id AND company_id = :company_id',
                ['upload_id' => 9833, 'company_id' => $companyId]
            );
            InterfaceDB::prepareExecute(
                "UPDATE corporation_tax_periods
                 SET status = 'computed', latest_submission_id = NULL
                 WHERE company_id = :company_id
                   AND accounting_period_id = :period_id
                   AND status = 'accepted'",
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
            $approvalIds = array_map(
                'intval',
                array_column(
                    InterfaceDB::fetchAll(
                        'SELECT id FROM hmrc_ct_filing_approvals
                         WHERE company_id = :company_id
                           AND accounting_period_id = :period_id',
                        ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
                    ),
                    'id'
                )
            );
            foreach ($approvalIds as $approvalId) {
                InterfaceDB::prepareExecute(
                    'DELETE FROM hmrc_ct_filing_approval_period_bases
                     WHERE hmrc_ct_filing_approval_id = :approval_id',
                    ['approval_id' => $approvalId]
                );
                InterfaceDB::prepareExecute(
                    'UPDATE ct_period_filing_bases
                     SET hmrc_ct_filing_approval_id = NULL
                     WHERE hmrc_ct_filing_approval_id = :approval_id',
                    ['approval_id' => $approvalId]
                );
                InterfaceDB::prepareExecute(
                    'DELETE FROM hmrc_ct_filing_approvals WHERE id = :approval_id',
                    ['approval_id' => $approvalId]
                );
            }
            InterfaceDB::prepareExecute(
                'UPDATE year_end_reviews
                 SET is_locked = 0, locked_at = NULL, locked_by = NULL
                 WHERE company_id = :company_id
                   AND accounting_period_id = :period_id',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
        });
        \eel_accounts\Support\RequestCache::clear();
    }

    private static function prepareLockedCorporationTaxBasis(int $companyId, int $accountingPeriodId): void
    {
        $periods = new \eel_accounts\Service\CorporationTaxPeriodService();
        self::requireSuccess($periods->syncForAccountingPeriod($companyId, $accountingPeriodId));
        test_confirm_ct_period_facts($companyId, $accountingPeriodId);

        $scope = new \eel_accounts\Service\CorporationTaxFilingScopeService();
        foreach (array_keys($scope->definitions()) as $field) {
            self::requireSuccess($scope->saveAnswer(
                $companyId,
                $accountingPeriodId,
                (string)$field,
                'no',
                self::ACTOR
            ));
        }
        $ct600a = new \eel_accounts\Service\Ct600aService();
        self::requireSuccess($ct600a->saveReview(
            $companyId,
            $accountingPeriodId,
            array_fill_keys(array_keys($ct600a->reviewQuestions()), 'no'),
            'director',
            'Golden CT600A Director',
            'Deterministic AP79-equivalent approval workflow fixture.'
        ));
        self::requireSuccess((new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->save(
            $companyId,
            GoldenAccountsFixture::CT600A_PARTY_ID,
            [
                'interest_rate_percent' => 0,
                'security_type' => 'unsecured',
                'repayable_on_demand' => 1,
                'repayment_timing' => 'within_12_months',
                'deferment_right_confirmed' => 0,
                'set_off_right_confirmed' => 0,
                'settlement_intention' => 'independently',
                'advance_interest_rate_percent' => 0,
                'advance_security_type' => 'unsecured',
                'advance_repayment_basis' => 'on_demand',
            ],
            self::ACTOR
        ));

        $lock = new \eel_accounts\Service\YearEndLockService();
        if ($lock->isLocked($companyId, $accountingPeriodId)) {
            return;
        }

        InterfaceDB::transaction(static function () use ($companyId, $accountingPeriodId, $lock): void {
            $readiness = (new \eel_accounts\Service\YearEndTaxReadinessService())
                ->fetchAccountingPeriodCtSummary($companyId, $accountingPeriodId);
            $basis = (new \eel_accounts\Service\YearEndTaxFreezeService())->approvalBasis($readiness);
            if (!is_array($basis)) {
                throw new RuntimeException('The AP79-equivalent Corporation Tax basis is not approval-ready.');
            }
            self::requireSuccess((new \eel_accounts\Service\YearEndAcknowledgementService())->save(
                $companyId,
                $accountingPeriodId,
                'tax_readiness_acknowledgement',
                $basis,
                self::ACTOR,
                '',
                true
            ));
            $computations = new \eel_accounts\Service\CorporationTaxComputationService();
            self::requireSuccess($computations->persistSummariesForYearEndLock(
                $companyId,
                $accountingPeriodId
            ));
            self::requireSuccess((new \eel_accounts\Service\DirectorLoanReconciliationService())
                ->saveYearEndReview($companyId, $accountingPeriodId, true, self::ACTOR));
            self::requireSuccess($lock->lockPeriod($companyId, $accountingPeriodId, self::ACTOR));
            self::requireSuccess($computations->sealSummariesForYearEndLock(
                $companyId,
                $accountingPeriodId
            ));
        });
    }

    private static function ensureReturnAuthorisation(int $companyId, int $accountingPeriodId): void
    {
        $service = new \eel_accounts\Service\Ct600ReturnAuthorisationService();
        if ($service->current($companyId, $accountingPeriodId) !== []) {
            return;
        }
        $authorisers = $service->eligibleAuthorisers(
            $companyId,
            (new DateTimeImmutable('now'))->format('Y-m-d')
        );
        $reference = (string)($authorisers[0]['reference'] ?? '');
        if ($reference === '') {
            throw new RuntimeException('The AP79-equivalent fixture has no eligible CT declarant.');
        }
        self::requireSuccess($service->save(
            $companyId,
            $accountingPeriodId,
            [
                'declarant_authority' => $reference,
                'original_unfiled_confirmed' => '1',
                'authority_confirmed' => '1',
                'declaration_confirmed' => '1',
            ],
            self::ACTOR
        ));
    }

    /** @param array<string,mixed> $approval */
    private static function convertApprovalToLegacyV8(
        array $approval,
        int $companyId,
        int $accountingPeriodId
    ): int {
        $approvalId = (int)($approval['id'] ?? 0);
        $basis = json_decode((string)($approval['basis_json'] ?? ''), true);
        if ($approvalId <= 0 || !is_array($basis)) {
            throw new RuntimeException('The native accounts approval cannot be converted into the v8 test fixture.');
        }
        $authorisation = (new \eel_accounts\Service\Ct600ReturnAuthorisationService())->current(
            $companyId,
            $accountingPeriodId
        );
        $scope = (new \eel_accounts\Service\CorporationTaxFilingScopeService())->fetch(
            $companyId,
            $accountingPeriodId
        );
        $ctPeriods = (new \eel_accounts\Service\CorporationTaxPeriodService())
            ->fetchForAccountingPeriod($companyId, $accountingPeriodId);

        $basis['basis_version'] = 'accounts-filing-approval-v8';
        $basis['accounts_report'] = array_replace(
            (array)($basis['accounts_report'] ?? []),
            ['basis_version' => 'ixbrl-accounts-report-v8']
        );
        $basis['corporation_tax_return_authorisation'] = [
            'id' => (int)($authorisation['id'] ?? 0),
            'declarant_name' => (string)($authorisation['declarant_name'] ?? ''),
            'declarant_status' => (string)($authorisation['declarant_status'] ?? ''),
        ];
        $basis['corporation_tax_filing_scope'] = [
            'answers' => (array)($scope['answers'] ?? []),
        ];
        $basis['ct_periods'] = array_map(
            static fn(array $period): array => [
                'id' => (int)($period['id'] ?? 0),
                'sequence_no' => (int)($period['sequence_no'] ?? 0),
            ],
            array_values(array_filter(
                $ctPeriods,
                static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
            ))
        );

        $accounts = new \eel_accounts\Service\IxbrlAccountsFilingApprovalService();
        $canonical = new ReflectionMethod($accounts, 'canonicalJson');
        $canonical->setAccessible(true);
        $basisJson = (string)$canonical->invoke($accounts, $basis);
        $basisHash = hash('sha256', $basisJson);
        InterfaceDB::prepareExecute(
            'UPDATE ixbrl_accounts_filing_approvals
             SET basis_version = :basis_version,
                 basis_hash = :basis_hash,
                 basis_json = :basis_json,
                 declarant_name = :declarant_name,
                 declarant_status = :declarant_status,
                 original_unfiled_confirmed = 1,
                 authority_confirmed = 1,
                 declaration_confirmed = 1
             WHERE id = :id',
            [
                'basis_version' => 'accounts-filing-approval-v8',
                'basis_hash' => $basisHash,
                'basis_json' => $basisJson,
                'declarant_name' => (string)($authorisation['declarant_name'] ?? ''),
                'declarant_status' => (string)($authorisation['declarant_status'] ?? ''),
                'id' => $approvalId,
            ]
        );
        return $approvalId;
    }

    private static function protectLegacyApprovalHistory(
        int $approvalId,
        int $companyId,
        int $accountingPeriodId
    ): void {
        if ((int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM ixbrl_accounts_artifacts WHERE filing_approval_id = :approval_id',
            ['approval_id' => $approvalId]
        ) > 0) {
            return;
        }
        $approvalHash = (string)InterfaceDB::fetchColumn(
            'SELECT basis_hash FROM ixbrl_accounts_filing_approvals WHERE id = :id',
            ['id' => $approvalId]
        );
        $runId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM ixbrl_generation_runs
             WHERE filing_approval_id = :approval_id
             ORDER BY id DESC LIMIT 1',
            ['approval_id' => $approvalId]
        );
        if ($runId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $approvalHash) !== 1) {
            throw new RuntimeException('The stale v8 fixture has no generated accounts history to preserve.');
        }
        InterfaceDB::prepareExecute(
            'INSERT INTO ixbrl_accounts_artifacts (
                generation_run_id, company_id, accounting_period_id,
                filing_approval_id, filing_approval_hash,
                authority, filing_kind, profile_key, profile_version,
                profile_fingerprint, render_model_sha256, transformation_registry_uri,
                generation_status, output_path, output_filename, output_sha256,
                generated_at, created_at
             ) VALUES (
                :run_id, :company_id, :period_id,
                :approval_id, :approval_hash,
                :authority, :filing_kind, :profile_key, :profile_version,
                :profile_fingerprint, :render_model_sha256, :registry_uri,
                :generation_status, :output_path, :output_filename, :output_sha256,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )',
            [
                'run_id' => $runId,
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'approval_id' => $approvalId,
                'approval_hash' => $approvalHash,
                'authority' => 'COMPANIES_HOUSE',
                'filing_kind' => 'original',
                'profile_key' => 'fixture-companies-house',
                'profile_version' => 'fixture-v1',
                'profile_fingerprint' => hash('sha256', 'unified-approval-fixture-profile'),
                'render_model_sha256' => hash('sha256', 'unified-approval-fixture-render-model'),
                'registry_uri' => 'https://www.xbrl.org/inlineXBRL/transformationRegistry/5',
                'generation_status' => 'generated',
                'output_path' => 'TEST-ONLY/unified-approval/accounts.xhtml',
                'output_filename' => 'unified-approval-accounts.xhtml',
                'output_sha256' => hash('sha256', 'unified-approval-fixture-output'),
            ]
        );
    }

    private static function advanceDisclosureRevision(int $companyId, int $accountingPeriodId): void
    {
        $service = new \eel_accounts\Service\IxbrlAccountsDisclosureService();
        $current = $service->fetch($companyId, $accountingPeriodId);
        $input = array_replace(
            (array)($current['disclosures'] ?? []),
            (array)($current['trading_status_answers'] ?? [])
        );
        $employees = (int)($input['average_number_employees'] ?? 0);
        $input['average_number_employees'] = $employees === 1 ? 2 : 1;
        $saved = $service->save($companyId, $accountingPeriodId, $input, self::ACTOR);
        self::requireSuccess($saved);
        if (empty($saved['changed'])) {
            throw new RuntimeException('The AP79-equivalent disclosure revision did not advance.');
        }
    }

    /** @param array<string,mixed> $result */
    private static function requireSuccess(array $result): void
    {
        if (!empty($result['success'])) {
            return;
        }
        throw new RuntimeException((string)(
            ((array)($result['errors'] ?? []))[0]
            ?? 'The AP79-equivalent fixture operation failed.'
        ));
    }
}
