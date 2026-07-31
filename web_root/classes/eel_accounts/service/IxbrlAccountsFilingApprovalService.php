<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Freezes the complete post-Year-End filing basis and builds its accounts facts atomically. */
final class IxbrlAccountsFilingApprovalService
{
    public const BASIS_VERSION = 'accounts-filing-approval-v8';
    public const CT_BASIS_VERSION = 'ct-period-filing-model-v11';
    private const REQUIRED_AUDIT_AREAS = [
        'accounting_profit', 'expense_treatments', 'depreciation_capital',
        'capital_allowances', 'losses', 'tax_liability',
    ];
    private ?\Closure $factBuilder;

    /** @param null|callable(int,int,array,int,string):int $factBuilder */
    public function __construct(?callable $factBuilder = null)
    {
        $this->factBuilder = $factBuilder !== null ? \Closure::fromCallable($factBuilder) : null;
    }

    public function status(int $companyId, int $accountingPeriodId, ?callable $progress = null): array
    {
        $cacheKey = \eel_accounts\Support\RequestCache::key($companyId, $accountingPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'ixbrl.filing-approval.status',
            $cacheKey,
            function () use ($companyId, $accountingPeriodId, $progress): array {
        $report = static function (string $message) use ($progress): void {
            if ($progress !== null) {
                $progress($message);
            }
        };
        $report('Checking the accounts filing-approval schema and latest approval…');
        if (!$this->schemaReady()) {
            return $this->statusResult('absent', false, null, null, [
                'Apply the accounts filing approval migration before approving disclosures.',
            ]);
        }

        $latest = $this->latestApproval($companyId, $accountingPeriodId);
        try {
            $candidate = $this->candidate(
                $companyId,
                $accountingPeriodId,
                false,
                $report
            );
        } catch (\Throwable $exception) {
            return $this->statusResult(
                $latest === null ? 'absent' : 'stale',
                false,
                $latest,
                null,
                [$exception->getMessage()]
            );
        }

        $report('Comparing the rebuilt filing basis with the immutable approval…');
        $matching = $this->matchingApprovalForCandidate($companyId, $accountingPeriodId, $candidate);
        $current = $matching !== null;
        $errors = $current
            ? []
            : $this->staleApprovalErrors($latest, (array)$candidate['basis']);
        return $this->statusResult(
            $current ? 'current' : ($latest === null ? 'absent' : 'stale'),
            true,
            $matching ?? $latest,
            $candidate,
            $errors,
            $latest
        );
            }
        );
    }

    /**
     * Lightweight status for render-time consumers.
     *
     * A locked Year End owns the immutable calculation evidence. Rendering
     * therefore only needs to verify the persisted approval hash and the
     * mutable records that can explicitly invalidate it: the Year End lock,
     * disclosure revision and Corporation Tax authorisation. Generation and
     * transmission continue to use status(), which rebuilds the full candidate.
     */
    public function statusForReadModel(int $companyId, int $accountingPeriodId): array
    {
        $cacheKey = \eel_accounts\Support\RequestCache::key($companyId, $accountingPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'ixbrl.filing-approval.status-read-model',
            $cacheKey,
            function () use ($companyId, $accountingPeriodId): array {
                if (!$this->schemaReady()) {
                    return $this->statusResult('absent', false, null, null, [
                        'Apply the accounts filing approval migration before approving disclosures.',
                    ]);
                }
                $approval = $this->latestApproval($companyId, $accountingPeriodId);
                if (!is_array($approval)) {
                    return $this->statusResult('absent', true, null, null, [
                        'Approve the current disclosures and filing basis before preparing CT filing output.',
                    ]);
                }

                $errors = [];
                $basisJson = (string)($approval['basis_json'] ?? '');
                $basisHash = strtolower(trim((string)($approval['basis_hash'] ?? '')));
                $basis = $basisJson !== '' ? json_decode($basisJson, true) : null;
                if ((string)($approval['basis_version'] ?? '') !== self::BASIS_VERSION
                    || !is_array($basis)
                    || preg_match('/^[a-f0-9]{64}$/D', $basisHash) !== 1
                    || !hash_equals($basisHash, hash('sha256', $basisJson))) {
                    $errors[] = 'The approved accounts filing basis failed its integrity check.';
                    $basis = [];
                }

                $yearEnd = \InterfaceDB::fetchOne(
                    'SELECT id, is_locked, locked_at
                     FROM year_end_reviews
                     WHERE company_id = :company_id AND accounting_period_id = :period_id
                     LIMIT 1',
                    ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
                );
                $frozenLock = (array)($basis['year_end_lock'] ?? []);
                if (!is_array($yearEnd)
                    || empty($yearEnd['is_locked'])
                    || (int)($yearEnd['id'] ?? 0) !== (int)($frozenLock['id'] ?? 0)
                    || !hash_equals(
                        trim((string)($yearEnd['locked_at'] ?? '')),
                        trim((string)($frozenLock['locked_at'] ?? ''))
                    )
                    || !hash_equals(
                        trim((string)($yearEnd['locked_at'] ?? '')),
                        trim((string)($approval['year_end_locked_at'] ?? ''))
                    )) {
                    $errors[] = 'The Year End lock has changed since the accounts filing approval.';
                }

                $disclosure = \InterfaceDB::fetchOne(
                    'SELECT id, revision
                     FROM ixbrl_accounts_disclosures
                     WHERE company_id = :company_id AND accounting_period_id = :period_id
                     LIMIT 1',
                    ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
                );
                $frozenDisclosure = (array)($basis['disclosures'] ?? []);
                if (!is_array($disclosure)
                    || (int)($disclosure['id'] ?? 0) !== (int)($frozenDisclosure['id'] ?? 0)
                    || (int)($disclosure['revision'] ?? 0) !== (int)($frozenDisclosure['revision'] ?? 0)
                    || (int)($disclosure['id'] ?? 0) !== (int)($approval['disclosure_id'] ?? 0)
                    || (int)($disclosure['revision'] ?? 0) !== (int)($approval['disclosure_revision'] ?? 0)) {
                    $errors[] = 'The accounts disclosures have changed since the filing approval.';
                }

                $authorisation = (new Ct600ReturnAuthorisationService())->current(
                    $companyId,
                    $accountingPeriodId
                );
                $frozenAuthorisation = (array)(
                    $basis['corporation_tax_return_authorisation'] ?? []
                );
                if ($authorisation === []
                    || $frozenAuthorisation === []
                    || !hash_equals(
                        $this->canonicalJson($this->authorisationBasis($authorisation)),
                        $this->canonicalJson($frozenAuthorisation)
                    )
                    || !hash_equals(
                        trim((string)($approval['declarant_name'] ?? '')),
                        trim((string)($frozenAuthorisation['declarant_name'] ?? ''))
                    )
                    || !hash_equals(
                        trim((string)($approval['declarant_status'] ?? '')),
                        trim((string)($frozenAuthorisation['declarant_status'] ?? ''))
                    )) {
                    $errors[] = 'The Corporation Tax return authorisation has changed since the filing approval.';
                }

                return $this->statusResult(
                    $errors === [] ? 'current' : 'stale',
                    true,
                    $approval,
                    null,
                    $errors,
                    $approval
                );
            }
        );
    }

    /** @return array{approval_id:int, approval_hash:string, fact_run_id:int, ct_basis_ids:list<int>, history_cleanup:array<string,mixed>} */
    public function approveAndBuildFacts(
        int $companyId,
        int $accountingPeriodId,
        string $approvedBy,
        string $note = '',
        ?\Closure $progress = null
    ): array {
        $this->assertSchemaReady();
        $approvedBy = trim($approvedBy);
        if ($approvedBy === '') {
            throw new \RuntimeException('The filing approval must identify its approver.');
        }
        if (\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('Filing approval must own its transaction so every stage can be rolled back atomically.');
        }

        return (array)\InterfaceDB::transaction(function () use ($companyId, $accountingPeriodId, $approvedBy, $note, $progress): array {
            $authorisation = (new Ct600ReturnAuthorisationService())->current($companyId, $accountingPeriodId);
            if ($authorisation === []) {
                throw new \RuntimeException('Save the complete Corporation Tax return authorisation before approving the filing basis.');
            }
            $candidate = $this->candidate($companyId, $accountingPeriodId, true);
            if ($this->matchingApprovalForCandidate($companyId, $accountingPeriodId, $candidate) !== null) {
                throw new \RuntimeException('This Statement of Fact is already approved and current for this locked accounting period.');
            }
            $disclosure = (array)$candidate['disclosure'];
            $yearEnd = (array)$candidate['year_end'];

            $progress?->__invoke('Recording the approved accounts filing basis…', 20);
            \InterfaceDB::prepareExecute(
                'INSERT INTO ixbrl_accounts_filing_approvals (
                    company_id, accounting_period_id, disclosure_id, disclosure_revision,
                    year_end_review_id, year_end_locked_at, basis_version, basis_hash,
                    basis_json, approved_by, approval_note, declarant_name, declarant_status,
                    original_unfiled_confirmed, authority_confirmed, declaration_confirmed
                 ) VALUES (
                    :company_id, :accounting_period_id, :disclosure_id, :disclosure_revision,
                    :year_end_review_id, :year_end_locked_at, :basis_version, :basis_hash,
                    :basis_json, :approved_by, :approval_note, :declarant_name, :declarant_status,
                    :original_unfiled_confirmed, :authority_confirmed, :declaration_confirmed
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'disclosure_id' => (int)$disclosure['id'],
                    'disclosure_revision' => (int)$disclosure['revision'],
                    'year_end_review_id' => (int)$yearEnd['id'],
                    'year_end_locked_at' => (string)$yearEnd['locked_at'],
                    'basis_version' => self::BASIS_VERSION,
                    'basis_hash' => (string)$candidate['basis_hash'],
                    'basis_json' => (string)$candidate['basis_json'],
                    'approved_by' => $approvedBy,
                    'approval_note' => trim($note) !== '' ? trim($note) : null,
                    'declarant_name' => trim((string)($authorisation['declarant_name'] ?? '')) !== ''
                        ? (string)$authorisation['declarant_name']
                        : null,
                    'declarant_status' => (string)$authorisation['declarant_status'],
                    'original_unfiled_confirmed' => 1,
                    'authority_confirmed' => 1,
                    'declaration_confirmed' => 1,
                ]
            );
            $approvalId = (int)\InterfaceDB::fetchColumn(
                'SELECT id FROM ixbrl_accounts_filing_approvals
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND basis_hash = :basis_hash AND approved_by = :approved_by
                 ORDER BY id DESC LIMIT 1',
                [
                    'company_id' => $companyId, 'period_id' => $accountingPeriodId,
                    'basis_hash' => (string)$candidate['basis_hash'], 'approved_by' => $approvedBy,
                ]
            );
            if ($approvalId <= 0) {
                throw new \RuntimeException('The filing approval could not be persisted.');
            }
            (new FilingEvidenceService())->linkApproval(
                $approvalId,
                $companyId,
                $accountingPeriodId,
                $approvedBy
            );

            $ctBasisIds = [];
            $ctPeriods = array_values((array)$candidate['ct_periods']);
            $ctPeriodCount = count($ctPeriods);
            foreach ($ctPeriods as $ctPeriodIndex => $period) {
                $percent = 35 + (int)floor(($ctPeriodIndex / max(1, $ctPeriodCount)) * 35);
                $progress?->__invoke(
                    'Creating the locked Corporation Tax filing basis for period '
                    . ($ctPeriodIndex + 1) . ' of ' . $ctPeriodCount . '…',
                    $percent
                );
                $model = $this->ctModel($candidate, $period, $approvalId, $approvedBy);
                \InterfaceDB::prepareExecute(
                    'INSERT INTO ct_period_filing_bases (
                        filing_approval_id, company_id, accounting_period_id, ct_period_id,
                        computation_run_id, calculation_basis_version, calculation_basis_hash,
                        basis_version, basis_hash, basis_json
                     ) VALUES (
                        :approval_id, :company_id, :accounting_period_id, :ct_period_id,
                        :run_id, :calculation_version, :calculation_hash,
                        :basis_version, :basis_hash, :basis_json
                     )',
                    [
                        'approval_id' => $approvalId,
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'ct_period_id' => (int)$period['id'],
                        'run_id' => (int)$period['computation_run_id'],
                        'calculation_version' => (string)$period['calculation_basis_version'],
                        'calculation_hash' => (string)$period['calculation_basis_hash'],
                        'basis_version' => self::CT_BASIS_VERSION,
                        'basis_hash' => (string)$model['basis_hash'],
                        'basis_json' => (string)$model['basis_json'],
                    ]
                );
                $ctBasisId = (int)\InterfaceDB::fetchColumn(
                    'SELECT id FROM ct_period_filing_bases
                     WHERE filing_approval_id = :approval_id AND ct_period_id = :ct_period_id LIMIT 1',
                    ['approval_id' => $approvalId, 'ct_period_id' => (int)$period['id']]
                );
                if ($ctBasisId <= 0) {
                    throw new \RuntimeException('The CT-period filing basis could not be persisted.');
                }
                $ctBasisIds[] = $ctBasisId;
            }

            $progress?->__invoke('Building the immutable accounts facts snapshot for iXBRL generation…', 78);
            $factRunId = $this->factBuilder !== null
                ? (int)($this->factBuilder)(
                    $companyId,
                    $accountingPeriodId,
                    (array)$candidate['report'],
                    $approvalId,
                    (string)$candidate['basis_hash']
                )
                : (new IxbrlFactBuilderService())->buildFactsFromApprovedReport(
                    $companyId,
                    $accountingPeriodId,
                    (array)$candidate['report'],
                    $approvalId,
                    (string)$candidate['basis_hash']
                );
            $progress?->__invoke('Verifying the approval and fact snapshot…', 95);
            $this->verifyPersisted($approvalId, $factRunId, $candidate, $ctBasisIds);
            $this->verifyCurrentCandidate($companyId, $accountingPeriodId, $candidate);
            $progress?->__invoke('Removing superseded unsubmitted filing history…', 98);
            $historyCleanup = (new IxbrlUntransmittedHistoryCleanupService())->clean(
                $companyId,
                $accountingPeriodId,
                $approvalId,
                $approvedBy
            );

            return [
                'approval_id' => $approvalId,
                'approval_hash' => (string)$candidate['basis_hash'],
                'fact_run_id' => $factRunId,
                'ct_basis_ids' => $ctBasisIds,
                'history_cleanup' => $historyCleanup,
            ];
        });
    }

    public function rebuildFactsFromCurrentApproval(int $companyId, int $accountingPeriodId): int
    {
        $status = $this->status($companyId, $accountingPeriodId);
        if (($status['state'] ?? '') !== 'current' || !is_array($status['approval'] ?? null)) {
            throw new \RuntimeException('Approve the current disclosures and filing basis before building iXBRL facts.');
        }
        $approval = (array)$status['approval'];
        $report = (new IxbrlAccountsReportService())->build($companyId, $accountingPeriodId);
        return (int)\InterfaceDB::transaction(fn(): int => (new IxbrlFactBuilderService())->buildFactsFromApprovedReport(
            $companyId,
            $accountingPeriodId,
            $report,
            (int)$approval['id'],
            (string)$approval['basis_hash']
        ));
    }

    private function candidate(
        int $companyId,
        int $accountingPeriodId,
        bool $lock,
        ?callable $progress = null
    ): array
    {
        $progressReport = static function (string $message) use ($progress): void {
            if ($progress !== null) {
                $progress($message);
            }
        };
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            throw new \RuntimeException('Select a company and accounting period.');
        }
        $suffix = $lock && \InterfaceDB::driverName() !== 'sqlite' ? ' FOR UPDATE' : '';
        $progressReport('Loading the locked Year End and saved accounts disclosures…');
        $yearEnd = \InterfaceDB::fetchOne(
            'SELECT * FROM year_end_reviews WHERE company_id = :company_id AND accounting_period_id = :period_id LIMIT 1' . $suffix,
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        if (!is_array($yearEnd) || empty($yearEnd['is_locked']) || trim((string)$yearEnd['locked_at']) === '') {
            throw new \RuntimeException('Lock Year End before approving the filing basis.');
        }
        $disclosure = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_accounts_disclosures WHERE company_id = :company_id AND accounting_period_id = :period_id LIMIT 1' . $suffix,
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        if (!is_array($disclosure)) {
            throw new \RuntimeException('Complete the accounts disclosures before approving the filing basis.');
        }
        $progressReport('Checking the saved Corporation Tax return authorisation…');
        $authorisation = (new Ct600ReturnAuthorisationService())->current($companyId, $accountingPeriodId);
        if ($authorisation === []) {
            throw new \RuntimeException('Complete and save the Corporation Tax return authorisation before approving the filing basis.');
        }

        $progressReport('Checking accounts disclosure completeness and filing profile…');
        $disclosureStatus = (new IxbrlAccountsDisclosureService())->fetch($companyId, $accountingPeriodId);
        if (empty($disclosureStatus['complete']) || empty($disclosureStatus['profile_supported'])) {
            $errors = (array)($disclosureStatus['profile_errors'] ?? $disclosureStatus['missing_labels'] ?? []);
            throw new \RuntimeException((string)($errors[0] ?? 'Complete all supported accounts disclosures before approval.'));
        }
        $progressReport('Checking Companies House revised-accounts readiness…');
        $revisedAccounts = (new CompaniesHouseRevisedAccountsReadinessService())
            ->assess($companyId, $accountingPeriodId);
        if (!empty($revisedAccounts['applicable']) && empty($revisedAccounts['ready'])) {
            throw new \RuntimeException((string)(($revisedAccounts['errors'] ?? [])[0]
                ?? 'Resolve the Companies House revised-accounts approval date before approval.'));
        }
        $progressReport('Checking the supported FRS 105 return profile and CT filing scope…');
        $profile = (new Frs105YearEndProfileService())->fetch($companyId, $accountingPeriodId);
        if (empty($profile['available']) || empty($profile['pass'])) {
            throw new \RuntimeException((string)(($profile['errors'] ?? [])[0] ?? 'The FRS 105 filing profile is not supported.'));
        }
        $filingScope = (new CorporationTaxFilingScopeService())->fetch($companyId, $accountingPeriodId);
        if (empty($filingScope['available']) || empty($filingScope['complete'])) {
            throw new \RuntimeException((string)(($filingScope['errors'] ?? [])[0] ?? 'Complete the Corporation Tax supplementary-page scope review.'));
        }
        $progressReport('Rebuilding the approved accounts-report evidence…');
        $report = (new IxbrlAccountsReportService())->build($companyId, $accountingPeriodId);
        $companySettings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $utr = preg_replace('/\s+/', '', trim((string)($companySettings['utr'] ?? ''))) ?? '';
        $turnover = ($report['basis']['current_mapping']['buckets']['turnover'] ?? null);
        if (!is_int($turnover) && !is_float($turnover)) {
            throw new \RuntimeException('The accounts filing basis has no numeric turnover fact to freeze.');
        }
        $progressReport('Loading the immutable Corporation Tax period calculation evidence…');
        $periods = $this->calculationPeriods($companyId, $accountingPeriodId, $lock);
        if ($periods === []) {
            throw new \RuntimeException('No active Corporation Tax periods are available for filing approval.');
        }
        $this->assertTurnoverReconciles((float)$turnover, $periods);

        $authorisationBasis = $this->authorisationBasis($authorisation);

        $basis = [
            'basis_version' => self::BASIS_VERSION,
            'company' => (array)$report['basis']['company'],
            'filing_identity' => [
                'utr' => $utr,
            ],
            'accounts_facts' => [
                'turnover' => (float)$turnover,
                'presentation_currency' => (string)$report['presentation_currency'],
            ],
            'accounting_period' => [
                'id' => (int)$report['accounting_period']['id'],
                'start_date' => (string)$report['accounting_period']['period_start'],
                'end_date' => (string)$report['accounting_period']['period_end'],
            ],
            'year_end_lock' => [
                'id' => (int)$yearEnd['id'],
                'locked_at' => (string)$yearEnd['locked_at'],
            ],
            'disclosures' => [
                'id' => (int)$disclosure['id'],
                'revision' => (int)$disclosure['revision'],
                'values' => (array)$report['basis']['disclosures'],
            ],
            'corporation_tax_filing_scope' => [
                'scope_version' => CorporationTaxFilingScopeService::SCOPE_VERSION,
                'revision' => (int)$filingScope['revision'],
                'answers' => (array)$filingScope['answers'],
                'basis_hash' => (string)$filingScope['basis_hash'],
            ],
            'supported_return_profile' => (array)$profile['supported_return_profile'],
            'profile_diagnostics' => [
                'checks' => (array)$profile['checks'],
                'errors' => (array)$profile['errors'],
            ],
            'accounts_report' => [
                'basis_version' => IxbrlAccountsReportService::BASIS_VERSION,
                'basis_hash' => (string)$report['basis_hash'],
            ],
            'ct_periods' => array_map(static fn(array $period): array => [
                'id' => (int)$period['id'],
                'sequence_no' => (int)$period['sequence_no'],
                'start_date' => (string)$period['period_start'],
                'end_date' => (string)$period['period_end'],
                'computation_run_id' => (int)$period['computation_run_id'],
                'computation_hash' => (string)$period['computation_hash'],
                'calculation_basis_version' => (string)$period['calculation_basis_version'],
                'calculation_basis_hash' => (string)$period['calculation_basis_hash'],
                'tax_audit_snapshot_id' => (int)$period['snapshot_id'],
                'tax_audit_basis_version' => (string)$period['snapshot_basis_version'],
                'tax_audit_basis_hash' => (string)$period['snapshot_basis_hash'],
                'actual_trading_turnover' => (float)($period['summary']['actual_trading_turnover'] ?? 0),
                'ct600_box_145_turnover' => (float)($period['summary']['ct600_box_145_turnover'] ?? 0),
                'ct600_turnover_rounding_adjustment' => (int)($period['summary']['ct600_turnover_rounding_adjustment'] ?? 0),
                'ct600a_required' => !empty($period['ct600a']['required']),
                'ct600a_basis_hash' => (string)$period['ct600a']['basis_hash'],
                'ct600a_review_hash' => (string)($period['ct600a']['review']['basis_hash'] ?? ''),
            ], $periods),
        ];
        if ($this->shouldIncludeAuthorisationBasis(
            $authorisation,
            $this->latestApproval($companyId, $accountingPeriodId)
        )) {
            $basis['corporation_tax_return_authorisation'] = $authorisationBasis;
        }
        $json = $this->canonicalJson($basis);
        return [
            'basis' => $basis,
            'basis_json' => $json,
            'basis_hash' => hash('sha256', $json),
            'year_end' => $yearEnd,
            'disclosure' => $disclosure,
            'report' => $report,
            'profile' => $profile,
            'filing_scope' => $filingScope,
            'ct_periods' => $periods,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function calculationPeriods(int $companyId, int $accountingPeriodId, bool $lock): array
    {
        $suffix = $lock && \InterfaceDB::driverName() !== 'sqlite' ? ' FOR UPDATE' : '';
        $rows = \InterfaceDB::fetchAll(
            'SELECT ctp.id, ctp.sequence_no, ctp.period_start, ctp.period_end,
                    r.id AS computation_run_id, r.computation_hash, r.summary_json,
                    s.id AS snapshot_id, s.basis_version AS snapshot_basis_version, s.basis_hash AS snapshot_basis_hash
             FROM corporation_tax_periods ctp
             LEFT JOIN corporation_tax_computation_runs r ON r.id = ctp.latest_computation_run_id
             LEFT JOIN corporation_tax_audit_snapshots s ON s.computation_run_id = r.id
             WHERE ctp.company_id = :company_id AND ctp.accounting_period_id = :period_id
               AND ctp.status <> :superseded
             ORDER BY ctp.sequence_no, ctp.id' . $suffix,
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'superseded' => 'superseded']
        );
        foreach ($rows as &$row) {
            $summary = json_decode((string)($row['summary_json'] ?? ''), true);
            $seal = is_array($summary) && is_array($summary['frozen_calculation_basis'] ?? null)
                ? (array)$summary['frozen_calculation_basis'] : [];
            $hash = trim((string)($seal['basis_hash'] ?? ''));
            $hashBasis = $seal;
            unset($hashBasis['basis_hash']);
            if ((int)($row['computation_run_id'] ?? 0) <= 0 || (int)($row['snapshot_id'] ?? 0) <= 0
                || $hash === '' || !hash_equals($hash, (new YearEndAcknowledgementService())->hashBasis($hashBasis))
                || (int)($seal['computation_run_id'] ?? 0) !== (int)$row['computation_run_id']
                || !hash_equals((string)($seal['computation_hash'] ?? ''), (string)($row['computation_hash'] ?? ''))
                || !hash_equals((string)($seal['tax_audit_basis_hash'] ?? ''), (string)($row['snapshot_basis_hash'] ?? ''))) {
                throw new \RuntimeException('CT period ' . (int)$row['sequence_no'] . ' has no current immutable calculation seal.');
            }
            $row['summary'] = $summary;
            $row['calculation_basis_version'] = (string)$seal['basis_version'];
            $row['calculation_basis_hash'] = $hash;
            $row['ct600a'] = (new Ct600aService())->build($companyId, $accountingPeriodId, (int)$row['id']);
            if (empty($row['ct600a']['available']) || empty($row['ct600a']['complete'])) {
                throw new \RuntimeException((string)(($row['ct600a']['blocking_errors'] ?? $row['ct600a']['errors'] ?? [])[0]
                    ?? 'Complete the CT600A evidence and section 464A review for CT period ' . (int)$row['sequence_no'] . '.'));
            }
        }
        unset($row);
        return $rows;
    }

    private function ctModel(array $candidate, array $period, int $approvalId, string $approvedBy): array
    {
        $summary = (array)$period['summary'];
        unset($summary['frozen_calculation_basis'], $summary['frozen_filing_basis']);
        if ((array)($summary['hard_gate_diagnostics'] ?? []) !== []) {
            throw new \RuntimeException('The approved CT calculation contains an unresolved blocking diagnostic.');
        }
        $areas = [];
        $areaHashes = [];
        foreach (\InterfaceDB::fetchAll(
            'SELECT area_code, area_hash, detail_json FROM corporation_tax_audit_areas WHERE snapshot_id = :snapshot_id ORDER BY area_code',
            ['snapshot_id' => (int)$period['snapshot_id']]
        ) as $area) {
            $detail = json_decode((string)$area['detail_json'], true);
            if (!is_array($detail)) {
                throw new \RuntimeException('A frozen Tax Audit schedule is unreadable.');
            }
            $hashBasis = $detail;
            unset($hashBasis['area_hash'], $hashBasis['pagination']);
            $calculatedAreaHash = hash('sha256', $this->canonicalJson($hashBasis));
            if (!hash_equals((string)$area['area_hash'], (string)($detail['area_hash'] ?? ''))
                || !hash_equals((string)$area['area_hash'], $calculatedAreaHash)) {
                throw new \RuntimeException('A frozen Tax Audit schedule failed its integrity check.');
            }
            $areas[(string)$area['area_code']] = $detail;
            $areaHashes[(string)$area['area_code']] = (string)$area['area_hash'];
        }
        ksort($areaHashes, SORT_STRING);
        foreach (self::REQUIRED_AUDIT_AREAS as $requiredArea) {
            if (!isset($areas[$requiredArea])) {
                throw new \RuntimeException('The frozen filing basis is missing the ' . $requiredArea . ' Tax Audit schedule.');
            }
        }
        if ($areaHashes === []
            || !hash_equals((string)$period['snapshot_basis_hash'], hash('sha256', $this->canonicalJson($areaHashes)))) {
            throw new \RuntimeException('The frozen Tax Audit snapshot failed its integrity check.');
        }
        $basis = (array)$candidate['basis'];
        $sameAccountsPeriod = (string)$basis['accounting_period']['start_date'] === (string)$period['period_start']
            && (string)$basis['accounting_period']['end_date'] === (string)$period['period_end'];
        $warnings = [];
        foreach ((array)($summary['warnings'] ?? []) as $warning) {
            $diagnostic = is_array($warning) ? $warning : ['message' => trim((string)$warning)];
            $message = trim((string)($diagnostic['message'] ?? $diagnostic['detail'] ?? ''));
            if ($message === '') {
                continue;
            }
            $warnings[] = array_replace($diagnostic, [
                'code' => trim((string)($diagnostic['code'] ?? '')) !== ''
                    ? (string)$diagnostic['code']
                    : 'frozen_warning_' . substr(hash('sha256', $message), 0, 12),
                'category' => (string)($diagnostic['category'] ?? 'tax_computation'),
                'severity' => (string)($diagnostic['severity'] ?? 'warning'),
                'message' => $message,
            ]);
        }
        $model = [
            'identity' => [
                'company_id' => (int)$basis['company']['id'],
                'company_name' => (string)$basis['company']['company_name'],
                'company_number' => (string)$basis['company']['company_number'],
            ],
            'filing_identity' => (array)$basis['filing_identity'],
            'accounts_facts' => (array)$basis['accounts_facts'],
            'ct_period_facts' => [
                'actual_trading_turnover' => (float)($summary['actual_trading_turnover'] ?? 0),
                'ct600_box_145_turnover' => (float)($summary['ct600_box_145_turnover'] ?? 0),
                'ct600_turnover_rounding_adjustment' => (int)($summary['ct600_turnover_rounding_adjustment'] ?? 0),
                'turnover_basis_version' => (string)($summary['turnover_basis_version'] ?? ''),
            ],
            'accounts_report' => (array)$basis['accounts_report'],
            'corporation_tax_filing_scope' => (array)$basis['corporation_tax_filing_scope'],
            'filing_decisions' => $this->filingDecisions(
                $summary,
                $sameAccountsPeriod,
                count((array)$candidate['ct_periods']) > 1,
                (array)$period['ct600a']
            ),
            'accounting_period' => (array)$basis['accounting_period'],
            'ct_period' => [
                'id' => (int)$period['id'], 'sequence_no' => (int)$period['sequence_no'],
                'start_date' => (string)$period['period_start'], 'end_date' => (string)$period['period_end'],
            ],
            'approval' => [
                'id' => $approvalId, 'basis_version' => self::BASIS_VERSION,
                'basis_hash' => (string)$candidate['basis_hash'], 'approved_by' => $approvedBy,
                'year_end_locked_at' => (string)$candidate['year_end']['locked_at'],
            ],
            'supported_return_profile' => (array)$candidate['profile']['supported_return_profile'],
            'diagnostics' => [
                'blocking' => (array)($summary['hard_gate_diagnostics'] ?? []),
                'warnings' => $warnings,
            ],
            'computation' => [
                'run_id' => (int)$period['computation_run_id'],
                'hash' => (string)$period['computation_hash'],
                'summary' => $summary,
            ],
            'audit' => $areas,
            'ct600a' => (array)$period['ct600a'],
        ];
        $json = $this->canonicalJson($model);
        return ['basis_json' => $json, 'basis_hash' => hash('sha256', self::CT_BASIS_VERSION . '|' . (string)$candidate['basis_hash'] . '|' . (string)$period['calculation_basis_hash'] . '|' . $json)];
    }

    /** @param list<array<string,mixed>> $periods */
    private function assertTurnoverReconciles(float $accountsTurnover, array $periods): void
    {
        $accountsPence = (int)round($accountsTurnover * 100, 0, PHP_ROUND_HALF_UP);
        $periodPence = 0;
        $periodWholePounds = 0;
        foreach ($periods as $period) {
            $summary = (array)($period['summary'] ?? []);
            if ((string)($summary['turnover_basis_version'] ?? '') !== CtPeriodTurnoverService::BASIS_VERSION
                || !is_numeric($summary['actual_trading_turnover'] ?? null)
                || !is_numeric($summary['ct600_box_145_turnover'] ?? null)) {
                throw new \RuntimeException(
                    'CT period ' . (int)($period['sequence_no'] ?? 0)
                    . ' has no approved trading-turnover evidence. Unlock and relock the accounting period.'
                );
            }
            $periodPence += (int)round(
                (float)$summary['actual_trading_turnover'] * 100,
                0,
                PHP_ROUND_HALF_UP
            );
            $boxValue = (float)$summary['ct600_box_145_turnover'];
            if ($boxValue < 0 || abs($boxValue - round($boxValue)) > 0.0001) {
                throw new \RuntimeException('A frozen CT600 box 145 turnover value is not a non-negative whole-pound amount.');
            }
            $periodWholePounds += (int)round($boxValue);
        }
        if ($periodPence !== $accountsPence) {
            throw new \RuntimeException('The frozen CT-period trading turnover does not equal statutory accounts turnover.');
        }
        $accountsWholePounds = intdiv($accountsPence + 50, 100);
        if ($periodWholePounds !== $accountsWholePounds) {
            throw new \RuntimeException('The frozen CT600 box 145 values do not equal rounded statutory accounts turnover.');
        }
    }

    /**
     * Freeze the narrow CT600 presentation/claim choices approved with the
     * disclosures. These values only classify already-frozen computation
     * evidence; they never recalculate the tax result.
     *
     * @return array<string,mixed>
     */
    private function filingDecisions(array $summary, bool $sameAccountsPeriod, bool $multipleReturns, array $ct600a): array
    {
        foreach ([
            'taxable_before_losses', 'taxable_profit', 'taxable_loss',
            'loss_created_in_period', 'losses_brought_forward', 'losses_used',
            'capital_allowances', 'ordinary_corporation_tax', 'estimated_corporation_tax',
            'associated_company_count',
        ] as $key) {
            if (!array_key_exists($key, $summary) || !is_numeric($summary[$key])) {
                throw new \RuntimeException('The frozen calculation cannot support CT600 decision ' . $key . '.');
            }
        }

        $beforeLosses = round((float)$summary['taxable_before_losses'], 2);
        $taxableProfit = round((float)$summary['taxable_profit'], 2);
        $taxableLoss = round((float)$summary['taxable_loss'], 2);
        $lossCreated = round((float)$summary['loss_created_in_period'], 2);
        $lossesBroughtForward = round((float)$summary['losses_brought_forward'], 2);
        $lossesUsed = round((float)$summary['losses_used'], 2);
        $expectedProfit = max(0.0, round($beforeLosses - $lossesUsed, 2));
        $expectedLoss = max(0.0, round(-$beforeLosses, 2));
        if ($lossesUsed < 0.0 || $lossesBroughtForward < $lossesUsed
            || ($lossesUsed > 0.0 && $beforeLosses <= 0.0)
            || abs($taxableProfit - $expectedProfit) > 0.009
            || abs($taxableLoss - $expectedLoss) > 0.009
            || abs($lossCreated - $expectedLoss) > 0.009) {
            throw new \RuntimeException('The frozen loss evidence cannot be classified safely for the CT600 main return.');
        }

        $capital = $this->capitalAllowanceDecisions($summary);
        $taxBands = $this->taxBandDecisions($summary, $taxableProfit);

        return array_replace([
            'return_type' => 'new',
            'company_type' => 0,
            'this_period_return' => true,
            'multiple_returns' => $multipleReturns,
            'accounts_attached' => true,
            'accounts_same_period' => $sameAccountsPeriod,
            'computations_attached' => true,
            'computations_same_period' => true,
            'supplementary_pages' => !empty($ct600a['required']) ? ['CT600A'] : [],
            'ct600a_tax_payable' => round((float)($ct600a['tax_payable'] ?? 0), 2),
            'ct600a_relief_due' => !empty($ct600a['relief_due']),
            // Post-1 April 2017 carried-forward trading losses are claimed
            // against total profits (CT600 box 285), not as same-trade losses
            // brought forward in box 160.  These are presentation decisions
            // derived solely from the frozen loss-restriction schedule.
            'loss_relief_treatment' => $lossesUsed > 0.0
                ? 'post_2017_carried_forward_against_total_profits'
                : 'none',
            'trading_profit_before_losses' => max(0.0, $beforeLosses),
            'trading_losses_brought_forward_used' => 0.0,
            'trading_losses_current_or_later_claimed' => 0.0,
            'trading_losses_carried_forward_claimed' => $lossesUsed,
            'net_trading_profits' => max(0.0, $beforeLosses),
            'profits_before_other_deductions' => max(0.0, $beforeLosses),
            'total_deductions_and_reliefs' => $lossesUsed,
            'profits_before_donations_group_relief' => $taxableProfit,
            'associated_company_count' => (int)$summary['associated_company_count'],
            'tax_calculation_bands' => $taxBands,
        ], $capital);
    }

    /** @return array<string,float> */
    private function capitalAllowanceDecisions(array $summary): array
    {
        $claimed = round((float)$summary['capital_allowances'], 2);
        $breakdown = (array)($summary['capital_allowance_breakdown'] ?? []);
        $rows = (array)($breakdown['rows'] ?? []);
        if ($claimed > 0.0 && $rows === []) {
            throw new \RuntimeException('The frozen capital-allowance total has no pool breakdown for CT600.');
        }

        $result = [
            'aia_claimed_in_trade' => 0.0,
            'main_pool_capital_allowances' => 0.0,
            'main_pool_balancing_charges' => 0.0,
            'special_rate_pool_capital_allowances' => 0.0,
            'special_rate_pool_balancing_charges' => 0.0,
            'qualifying_expenditure_other_machinery_plant' => 0.0,
        ];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('A frozen capital-allowance pool row is unreadable.');
            }
            $pool = (string)($row['pool_type'] ?? '');
            if (!in_array($pool, ['main_pool', 'special_rate_pool'], true)) {
                foreach (['aia_claimed', 'fya_claimed', 'wda_claimed', 'balancing_allowance', 'balancing_charge'] as $key) {
                    if (abs((float)($row[$key] ?? 0)) > 0.004) {
                        throw new \RuntimeException('A capital-allowance pool is outside the supported CT600 MVP: ' . $pool . '.');
                    }
                }
                continue;
            }
            $aia = round((float)($row['aia_claimed'] ?? 0), 2);
            $fya = round((float)($row['fya_claimed'] ?? 0), 2);
            $wda = round((float)($row['wda_claimed'] ?? 0), 2);
            $balancingAllowance = round((float)($row['balancing_allowance'] ?? 0), 2);
            $balancingCharge = round((float)($row['balancing_charge'] ?? 0), 2);
            if ($fya > 0.0) {
                throw new \RuntimeException('First-year capital allowances require CT600 boxes outside the supported MVP.');
            }
            foreach ([$aia, $wda, $balancingAllowance, $balancingCharge] as $amount) {
                if ($amount < 0.0) {
                    throw new \RuntimeException('A frozen capital-allowance pool amount is negative.');
                }
            }
            $result['aia_claimed_in_trade'] += $aia;
            $result[$pool . '_capital_allowances'] += $aia + $wda + $balancingAllowance;
            $result[$pool . '_balancing_charges'] += $balancingCharge;
        }

        foreach ((array)($breakdown['asset_calculations'] ?? []) as $asset) {
            if (!is_array($asset)) {
                throw new \RuntimeException('A frozen capital-allowance asset row is unreadable.');
            }
            $addition = round((float)($asset['addition_amount'] ?? 0), 2);
            if ($addition < 0.0) {
                throw new \RuntimeException('A frozen qualifying-expenditure amount is negative.');
            }
            if ($addition > 0.0) {
                $pool = (string)($asset['pool_type'] ?? '');
                $allowanceType = (string)($asset['allowance_type'] ?? '');
                if (!in_array($pool, ['main_pool', 'special_rate_pool'], true)
                    || !in_array($allowanceType, ['aia', 'wda', 'none', ''], true)) {
                    throw new \RuntimeException('Qualifying expenditure is outside the supported plant-and-machinery MVP.');
                }
                $result['qualifying_expenditure_other_machinery_plant'] += $addition;
            }
        }

        foreach ($result as $key => $value) {
            $result[$key] = round($value, 2);
        }
        $classified = round(
            $result['main_pool_capital_allowances'] + $result['special_rate_pool_capital_allowances'],
            2
        );
        if (abs($classified - $claimed) > 0.009) {
            throw new \RuntimeException('The frozen capital allowances do not reconcile to the supported CT600 pool boxes.');
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function taxBandDecisions(array $summary, float $taxableProfit): array
    {
        $bands = (array)($summary['ct_rate_bands'] ?? []);
        $ordinaryTax = round((float)$summary['ordinary_corporation_tax'], 2);
        if ($taxableProfit <= 0.0) {
            if ($ordinaryTax !== 0.0 || $bands !== []) {
                throw new \RuntimeException('A nil-profit CT600 has inconsistent frozen tax-band evidence.');
            }
            return [];
        }
        if ($bands === [] || count($bands) > 2) {
            throw new \RuntimeException('A profitable CT600 requires one or two frozen financial-year tax bands.');
        }
        $normalised = [];
        $profitTotal = 0.0;
        $netTaxTotal = 0.0;
        foreach ($bands as $band) {
            if (!is_array($band) || preg_match('/^FY([0-9]{4})$/', (string)($band['financial_year'] ?? ''), $match) !== 1) {
                throw new \RuntimeException('A frozen Corporation Tax rate band has no valid financial year.');
            }
            $basis = (string)($band['basis'] ?? '');
            $rate = $basis === 'small_profits_rate'
                ? (float)($band['small_profits_rate'] ?? -1)
                : (float)($band['main_rate'] ?? -1);
            $profit = round((float)($band['taxable_profit'] ?? -1), 2);
            $netTax = round((float)($band['liability'] ?? -1), 2);
            $marginalRelief = round((float)($band['marginal_relief'] ?? 0), 2);
            $grossTax = round($netTax + $marginalRelief, 2);
            if ($profit < 0.0 || $netTax < 0.0 || $marginalRelief < 0.0 || $rate < 0.0 || $rate > 1.0
                || !in_array($basis, ['flat_main_rate', 'main_rate', 'small_profits_rate', 'main_rate_less_marginal_relief'], true)) {
                throw new \RuntimeException('A frozen Corporation Tax rate band is outside the supported ordinary-rate model.');
            }
            $normalised[] = [
                'financial_year' => (string)$match[1],
                'profit' => $profit,
                'tax_rate_percent' => round($rate * 100, 4),
                'gross_tax' => $grossTax,
                'marginal_relief' => $marginalRelief,
                'net_tax' => $netTax,
                'basis' => $basis,
            ];
            $profitTotal += $profit;
            $netTaxTotal += $netTax;
        }
        if (abs(round($profitTotal, 2) - $taxableProfit) > 0.009
            || abs(round($netTaxTotal, 2) - $ordinaryTax) > 0.009) {
            throw new \RuntimeException('The frozen Corporation Tax bands do not reconcile to profit and liability.');
        }
        return $normalised;
    }

    private function verifyPersisted(int $approvalId, int $factRunId, array $candidate, array $ctBasisIds): void
    {
        $approval = \InterfaceDB::fetchOne('SELECT basis_hash, basis_json FROM ixbrl_accounts_filing_approvals WHERE id = :id', ['id' => $approvalId]);
        if (!is_array($approval) || !hash_equals((string)$candidate['basis_hash'], (string)$approval['basis_hash'])
            || !hash_equals((string)$candidate['basis_json'], (string)$approval['basis_json'])
            || !hash_equals((string)$approval['basis_hash'], hash('sha256', (string)$approval['basis_json']))) {
            throw new \RuntimeException('The persisted filing approval failed its integrity check.');
        }
        foreach ($ctBasisIds as $ctBasisId) {
            $stored = \InterfaceDB::fetchOne('SELECT * FROM ct_period_filing_bases WHERE id = :id', ['id' => $ctBasisId]);
            $model = is_array($stored) ? json_decode((string)$stored['basis_json'], true) : null;
            $calculated = is_array($model) ? hash(
                'sha256',
                self::CT_BASIS_VERSION . '|' . (string)$candidate['basis_hash'] . '|'
                . (string)$stored['calculation_basis_hash'] . '|' . $this->canonicalJson($model)
            ) : '';
            if (!is_array($stored) || !is_array($model)
                || (int)$stored['filing_approval_id'] !== $approvalId
                || (string)$stored['basis_version'] !== self::CT_BASIS_VERSION
                || !hash_equals((string)$stored['basis_hash'], $calculated)) {
                throw new \RuntimeException('A persisted CT-period filing basis failed its integrity check.');
            }
        }
        $run = \InterfaceDB::fetchOne(
            'SELECT r.filing_approval_id, r.filing_approval_hash, r.basis_hash, r.status,
                    COUNT(f.id) AS fact_count
             FROM ixbrl_generation_runs r
             LEFT JOIN ixbrl_generation_facts f ON f.run_id = r.id
             WHERE r.id = :id GROUP BY r.id',
            ['id' => $factRunId]
        );
        if (!is_array($run) || (int)$run['filing_approval_id'] !== $approvalId
            || !hash_equals((string)$candidate['basis_hash'], (string)$run['filing_approval_hash'])
            || !hash_equals((string)$candidate['report']['basis_hash'], (string)$run['basis_hash'])
            || (string)$run['status'] !== 'ready' || (int)$run['fact_count'] <= 0
            || count($ctBasisIds) !== count((array)$candidate['ct_periods'])) {
            throw new \RuntimeException('The approved facts or CT filing bases failed their integrity check.');
        }
    }

    private function verifyCurrentCandidate(int $companyId, int $accountingPeriodId, array $approvedCandidate): void
    {
        $current = $this->candidate($companyId, $accountingPeriodId, true);
        if (!hash_equals(
            (string)($approvedCandidate['basis_hash'] ?? ''),
            (string)($current['basis_hash'] ?? '')
        ) || !hash_equals(
            (string)($approvedCandidate['basis_json'] ?? ''),
            (string)($current['basis_json'] ?? '')
        )) {
            throw new \RuntimeException(
                'The filing basis changed while approval was being created. No approval was saved; refresh the application runtime and approve again.'
            );
        }
    }

    /** @param array<string,mixed>|null $approval */
    private function staleApprovalErrors(?array $approval, array $currentBasis): array
    {
        $storedBasis = is_array($approval)
            ? json_decode((string)($approval['basis_json'] ?? ''), true)
            : null;
        if (!is_array($storedBasis)) {
            return ['The previous filing approval cannot be compared with the current filing basis. Approve again.'];
        }

        $changed = $this->changedBasisSections($storedBasis, $currentBasis);
        if ($changed === ['accounts_report']) {
            return [
                'The Accounts Report generation basis changed. Reload the PHP web runtime if this is a deployment, then approve the current filing basis again.',
            ];
        }

        $messages = [];
        foreach ($changed as $section) {
            $messages[] = match ($section) {
                'disclosures' => 'Accounts disclosures changed after the previous filing approval.',
                'year_end_lock' => 'The Year End lock changed after the previous filing approval.',
                'corporation_tax_filing_scope', 'ct_periods' => 'The Corporation Tax filing basis changed after the previous filing approval.',
                'company', 'filing_identity', 'accounts_facts', 'accounting_period' => 'The accounts identity or figures changed after the previous filing approval.',
                'accounts_report' => 'The Accounts Report basis changed after the previous filing approval.',
                default => 'The current filing basis changed after the previous approval.',
            };
        }

        return array_values(array_unique($messages !== [] ? $messages : [
            'The current filing basis changed after the previous approval.',
        ]));
    }

    /** @return list<string> */
    private function changedBasisSections(array $storedBasis, array $currentBasis): array
    {
        $sections = array_values(array_unique(array_merge(array_keys($storedBasis), array_keys($currentBasis))));
        sort($sections, SORT_STRING);
        $changed = [];
        foreach ($sections as $section) {
            $stored = array_key_exists($section, $storedBasis) ? $storedBasis[$section] : null;
            $current = array_key_exists($section, $currentBasis) ? $currentBasis[$section] : null;
            if (!hash_equals($this->canonicalJson(['value' => $stored]), $this->canonicalJson(['value' => $current]))) {
                $changed[] = (string)$section;
            }
        }

        return $changed;
    }

    private function authorisationBasis(array $authorisation): array
    {
        if (!(new Ct600ReturnAuthorisationService())->isStructured($authorisation)) {
            return [
                'declarant_status' => (string)$authorisation['declarant_status'],
                'original_unfiled_confirmed' => true,
                'authority_confirmed' => true,
                'declaration_confirmed' => true,
            ];
        }

        return [
            'declarant_name' => (string)$authorisation['declarant_name'],
            'declarant_status' => (string)$authorisation['declarant_status'],
            'declaration_at' => (string)$authorisation['saved_at'],
            'declarant_party_id' => (int)($authorisation['declarant_party_id'] ?? 0) ?: null,
            'declarant_director_id' => (int)($authorisation['declarant_director_id'] ?? 0) ?: null,
            'declarant_role_id' => (int)($authorisation['declarant_role_id'] ?? 0) ?: null,
            'original_unfiled_confirmed' => true,
            'authority_confirmed' => true,
            'declaration_confirmed' => true,
        ];
    }

    private function shouldIncludeAuthorisationBasis(array $authorisation, ?array $latestApproval): bool
    {
        if ((new Ct600ReturnAuthorisationService())->isStructured($authorisation)) {
            return true;
        }

        $storedBasis = is_array($latestApproval)
            ? json_decode((string)($latestApproval['basis_json'] ?? ''), true)
            : null;
        return is_array($storedBasis)
            && array_key_exists('corporation_tax_return_authorisation', $storedBasis);
    }

    private function latestApproval(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_accounts_filing_approvals WHERE company_id = :company_id AND accounting_period_id = :period_id ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        return is_array($row) ? $row : null;
    }

    private function approvalByHash(int $companyId, int $accountingPeriodId, string $basisHash): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_accounts_filing_approvals
             WHERE company_id = :company_id AND accounting_period_id = :period_id AND basis_hash = :basis_hash
             ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'basis_hash' => $basisHash]
        );
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $candidate */
    private function matchingApprovalForCandidate(int $companyId, int $accountingPeriodId, array $candidate): ?array
    {
        $matching = $this->approvalByHash($companyId, $accountingPeriodId, (string)($candidate['basis_hash'] ?? ''));
        if ($matching === null
            || (string)($matching['basis_version'] ?? '') !== self::BASIS_VERSION
            || !hash_equals((string)($candidate['basis_json'] ?? ''), (string)($matching['basis_json'] ?? ''))
            || !hash_equals((string)($candidate['basis_hash'] ?? ''), hash('sha256', (string)($matching['basis_json'] ?? '')))) {
            return null;
        }

        return $matching;
    }

    private function statusResult(
        string $state,
        bool $canApprove,
        ?array $approval,
        ?array $candidate,
        array $errors,
        ?array $latestApproval = null
    ): array
    {
        return [
            'available' => $this->schemaReady(), 'state' => $state, 'current' => $state === 'current',
            'can_approve' => $canApprove, 'approval' => $approval, 'candidate_hash' => $candidate['basis_hash'] ?? null,
            'latest_approval' => $latestApproval ?? $approval,
            'errors' => array_values(array_unique(array_map('strval', $errors))),
        ];
    }

    private function schemaReady(): bool
    {
        return \InterfaceDB::tableExists('ixbrl_accounts_filing_approvals')
            && \InterfaceDB::tableExists('ct_period_filing_bases')
            && \InterfaceDB::columnExists('ixbrl_generation_runs', 'filing_approval_id')
            && \InterfaceDB::columnExists('ixbrl_generation_runs', 'filing_approval_hash');
    }

    private function assertSchemaReady(): void
    {
        if (!$this->schemaReady()) {
            throw new \RuntimeException('Apply the accounts filing approval migration before approving disclosures.');
        }
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as $key => $child) { $item[$key] = $normalise($child); }
            return $item;
        };
        return \eel_accounts\Support\Utf8::json($normalise($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
