<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Support\PersistentJson;
use eel_accounts\Support\RequestCache;

/**
 * Coordinates the separate statutory-accounts and HMRC filing approvals.
 *
 * The browser presents one approval decision, but the evidence remains two
 * independently versioned, append-only records. All writes made by approveAll
 * participate in one outer database transaction.
 */
final class IxbrlFilingApprovalWorkflowService
{
    public const STATE_TOKEN_VERSION = 'ixbrl-filing-approval-workflow-state-v2';

    private const REQUIRED_AUDIT_AREAS = [
        'accounting_profit',
        'expense_treatments',
        'depreciation_capital',
        'capital_allowances',
        'losses',
        'tax_liability',
    ];

    /** @return array<string,mixed> */
    public function status(int $companyId, int $accountingPeriodId): array
    {
        $available = $this->schemaReady();
        $accountsStatus = $this->safeAccountsStatus($companyId, $accountingPeriodId);
        $hmrcStatus = $this->safeHmrcStatus($companyId, $accountingPeriodId);
        $accounts = $this->authorityStatus($accountsStatus, 'accounts');
        $hmrc = $this->authorityStatus($hmrcStatus, 'hmrc');
        $formBlockers = [];
        $externalBlockers = [];

        if (!$available) {
            $externalBlockers[] = 'Apply the unified accounts and Corporation Tax approval prerequisites before approving this filing.';
        }
        if (!$this->accountingContextExists($companyId, $accountingPeriodId)) {
            $externalBlockers[] = 'Select a company and accounting period.';
        }

        if ($available && $this->accountingContextExists($companyId, $accountingPeriodId)) {
            $yearEnd = (new YearEndLockService())->fetchReview($companyId, $accountingPeriodId);
            if (!is_array($yearEnd) || empty($yearEnd['is_locked']) || trim((string)($yearEnd['locked_at'] ?? '')) === '') {
                $externalBlockers[] = 'Complete and lock Year End before approving the accounts and Corporation Tax return.';
            }

            $disclosures = (new IxbrlAccountsDisclosureService())->fetch($companyId, $accountingPeriodId);
            $formBlockers = array_merge(
                $formBlockers,
                (array)($disclosures['approval_blockers'] ?? [])
            );
            if (empty($disclosures['available']) || empty($disclosures['complete'])
                || empty($disclosures['profile_supported'])) {
                $disclosureErrors = array_merge(
                    (array)($disclosures['errors'] ?? []),
                    (array)($disclosures['profile_errors'] ?? [])
                );
                if ($disclosureErrors === [] && (array)($disclosures['missing_labels'] ?? []) !== []) {
                    $disclosureErrors[] = 'Complete: '
                        . implode(', ', array_map('strval', (array)$disclosures['missing_labels'])) . '.';
                }
                $formBlockers = array_merge($formBlockers, $disclosureErrors ?: [
                    'Complete the supported statutory-accounts disclosures.',
                ]);
            }

            $authorisation = (new Ct600ReturnAuthorisationService())->current(
                $companyId,
                $accountingPeriodId
            );
            if ($authorisation === []) {
                $formBlockers[] = 'Complete and save all three Corporation Tax return authorisation confirmations.';
            }

            $scope = (new CorporationTaxFilingScopeService())->fetch($companyId, $accountingPeriodId);
            if (empty($scope['available']) || empty($scope['complete'])) {
                $externalBlockers = array_merge($externalBlockers, (array)($scope['errors'] ?? []));
                if ((array)($scope['unanswered_fields'] ?? []) !== []) {
                    $externalBlockers[] = 'Complete the Corporation Tax supplementary-page scope review.';
                }
                if ((array)($scope['errors'] ?? []) === []
                    && (array)($scope['unanswered_fields'] ?? []) === []) {
                    $externalBlockers[] = 'Complete the Corporation Tax supplementary-page scope review.';
                }
            }

            $profile = (new Frs105YearEndProfileService())->fetch($companyId, $accountingPeriodId);
            if (empty($profile['available']) || empty($profile['pass'])) {
                $externalBlockers = array_merge($externalBlockers, (array)($profile['errors'] ?? []));
                if ((array)($profile['errors'] ?? []) === []) {
                    $externalBlockers[] = 'The supported FRS 105 Corporation Tax return profile is not available.';
                }
            }

            $externalBlockers = array_merge(
                $externalBlockers,
                $this->ctReadinessBlockers($companyId, $accountingPeriodId)
            );

            // A stale or absent accounts approval is resolved by this combined
            // workflow. Only errors which prevent constructing the current
            // accounts candidate are blockers here.
            if (empty($accountsStatus['can_approve']) && empty($accountsStatus['current'])) {
                $externalBlockers = array_merge(
                    $externalBlockers,
                    (array)($accountsStatus['errors'] ?? [])
                );
            }
        }

        $formBlockers = $this->messages($formBlockers);
        $externalBlockers = $this->messages($externalBlockers);
        $blockers = $this->messages(array_merge($formBlockers, $externalBlockers));
        $currentAccountsFactRunId = !empty($accounts['native_current'])
            ? $this->currentFactRunId(
                (int)($accounts['approval_id'] ?? 0),
                (string)($accounts['approval_hash'] ?? '')
            )
            : 0;
        $accounts['fact_run_id'] = $currentAccountsFactRunId > 0
            ? $currentAccountsFactRunId
            : null;
        $bothCurrent = !empty($accounts['native_current'])
            && !empty($hmrc['native_current'])
            && $currentAccountsFactRunId > 0
            && (int)($accounts['approval_id'] ?? 0) > 0
            && (int)($hmrc['approval_id'] ?? 0) > 0
            && (int)(($hmrc['approval'] ?? [])['accounts_filing_approval_id'] ?? 0)
                === (int)$accounts['approval_id']
            && hash_equals(
                strtolower(trim((string)($accounts['approval_hash'] ?? ''))),
                strtolower(trim((string)(($hmrc['approval'] ?? [])['accounts_filing_approval_hash'] ?? '')))
            );

        $stateToken = '';
        if ($available && $this->accountingContextExists($companyId, $accountingPeriodId)) {
            try {
                $stateToken = $this->tokenForSnapshot(
                    $this->stateSnapshot($companyId, $accountingPeriodId, false)
                );
            } catch (\Throwable $exception) {
                $externalBlockers = $this->messages(array_merge(
                    $externalBlockers,
                    [$exception->getMessage()]
                ));
                $blockers = $this->messages(array_merge($formBlockers, $externalBlockers));
            }
        }

        return [
            'available' => $available,
            'can_approve' => $available && $stateToken !== '' && $blockers === [] && !$bothCurrent,
            'both_current' => $bothCurrent,
            'accounts' => $accounts,
            'hmrc' => $hmrc,
            'form_blockers' => $formBlockers,
            'external_blockers' => $externalBlockers,
            'blockers' => $blockers,
            'state_token_version' => self::STATE_TOKEN_VERSION,
            'state_token' => $stateToken,
        ];
    }

    /**
     * Saves only the mutable form records. No approval, fact run or CT-period
     * filing basis is created by this method.
     *
     * @return array<string,mixed>
     */
    public function saveDraft(
        int $companyId,
        int $accountingPeriodId,
        array $submitted,
        string $actor,
        string $expectedToken
    ): array {
        $this->assertActorAndToken($actor, $expectedToken);
        $this->assertSchemaReady();

        return $this->transactionWithCacheReset(function () use (
            $companyId,
            $accountingPeriodId,
            $submitted,
            $actor,
            $expectedToken
        ): array {
            $this->assertExpectedState($companyId, $accountingPeriodId, $expectedToken);
            $input = $this->mergePersistedWhenLocked(
                $companyId,
                $accountingPeriodId,
                $submitted
            );

            $disclosures = (new IxbrlAccountsDisclosureService())->save(
                $companyId,
                $accountingPeriodId,
                $input,
                $actor
            );
            $this->assertSuccessfulResult($disclosures, 'The accounts disclosures could not be saved.');
            RequestCache::clear();

            $authorisation = (new Ct600ReturnAuthorisationService())->saveDraftIfChanged(
                $companyId,
                $accountingPeriodId,
                $input,
                $actor
            );
            $this->assertSuccessfulResult(
                $authorisation,
                'The Corporation Tax return authorisation could not be saved.'
            );
            RequestCache::clear();

            $savedAuthorisation = (array)($authorisation['authorisation'] ?? []);
            return [
                'success' => true,
                'errors' => [],
                'warnings' => [],
                'messages' => ['Draft accounts disclosures and Corporation Tax authorisation saved.'],
                'disclosures_changed' => !empty($disclosures['changed']),
                'authorisation_changed' => !empty($authorisation['changed']),
                'authorisation_id' => (int)($savedAuthorisation['id'] ?? 0) ?: null,
                'state_token' => $this->tokenForSnapshot(
                    $this->stateSnapshot($companyId, $accountingPeriodId, false)
                ),
            ];
        });
    }

    /**
     * Saves the submitted facts and records/reuses both immutable approvals in
     * one database transaction. This method never transmits a filing.
     *
     * @return array<string,mixed>
     */
    public function approveAll(
        int $companyId,
        int $accountingPeriodId,
        array $submitted,
        string $actor,
        string $note,
        string $expectedToken,
        ?callable $progress = null
    ): array {
        $this->assertActorAndToken($actor, $expectedToken);
        $this->assertSchemaReady();
        $actor = trim($actor);
        $note = trim($note);
        $progressClosure = $progress !== null ? \Closure::fromCallable($progress) : null;

        return $this->transactionWithCacheReset(function () use (
            $companyId,
            $accountingPeriodId,
            $submitted,
            $actor,
            $note,
            $expectedToken,
            $progressClosure
        ): array {
            $this->report($progressClosure, 'Securing the current filing-approval state…', 5);
            $this->assertExpectedState($companyId, $accountingPeriodId, $expectedToken);
            $input = $this->mergePersistedWhenLocked(
                $companyId,
                $accountingPeriodId,
                $submitted
            );

            $this->report($progressClosure, 'Saving and validating the accounts disclosures…', 10);
            $disclosures = (new IxbrlAccountsDisclosureService())->save(
                $companyId,
                $accountingPeriodId,
                $input,
                $actor
            );
            $this->assertSuccessfulResult($disclosures, 'The accounts disclosures could not be saved.');
            RequestCache::clear();
            $approvalBlockers = (array)($disclosures['approval_blockers'] ?? []);
            if ($approvalBlockers === []) {
                $savedDisclosures = (new IxbrlAccountsDisclosureService())->fetch(
                    $companyId,
                    $accountingPeriodId
                );
                $approvalBlockers = (array)($savedDisclosures['approval_blockers'] ?? []);
            }
            if ($approvalBlockers !== []) {
                throw new \RuntimeException((string)reset($approvalBlockers));
            }

            $this->report($progressClosure, 'Saving the Corporation Tax return authorisation…', 18);
            $authorisationResult = (new Ct600ReturnAuthorisationService())->saveIfChanged(
                $companyId,
                $accountingPeriodId,
                $input,
                $actor
            );
            $this->assertSuccessfulResult(
                $authorisationResult,
                'The Corporation Tax return authorisation could not be saved.'
            );
            $authorisation = (array)($authorisationResult['authorisation'] ?? []);
            if ((int)($authorisation['id'] ?? 0) <= 0) {
                throw new \RuntimeException('The saved Corporation Tax return authorisation has no evidence identifier.');
            }
            RequestCache::clear();

            // A repeated unchanged submit is deliberately idempotent. The two
            // immutable approvals, their facts and bound CT bases are reused,
            // and no second "approval" audit event is manufactured.
            if (empty($disclosures['changed']) && empty($authorisationResult['changed'])) {
                $unchanged = $this->status($companyId, $accountingPeriodId);
                if (!empty($unchanged['both_current'])) {
                    $accounts = (array)($unchanged['accounts'] ?? []);
                    $hmrc = (array)($unchanged['hmrc'] ?? []);
                    $accountsApprovalId = (int)($accounts['approval_id'] ?? 0);
                    $hmrcApprovalId = (int)($hmrc['approval_id'] ?? 0);
                    $ctBasisIds = $this->ctBasisIdsForHmrcApproval($hmrcApprovalId);
                    return [
                        'success' => true,
                        'errors' => [],
                        'warnings' => [],
                        'messages' => ['Accounts and Corporation Tax approvals are already current. No filing was transmitted.'],
                        'accounts_approval_id' => $accountsApprovalId,
                        'accounts_approval_hash' => strtolower((string)($accounts['approval_hash'] ?? '')),
                        'accounts_approval_created' => false,
                        'accounts_approval_reused' => true,
                        'fact_run_id' => (int)($accounts['fact_run_id'] ?? 0),
                        'fact_run_created' => false,
                        'fact_run_reused' => true,
                        'ct_basis_ids' => $ctBasisIds,
                        'ct_basis_created_ids' => [],
                        'ct_basis_reused_ids' => $ctBasisIds,
                        'hmrc_approval_id' => $hmrcApprovalId,
                        'hmrc_approval_hash' => strtolower((string)($hmrc['approval_hash'] ?? '')),
                        'hmrc_approval_created' => false,
                        'hmrc_approval_reused' => true,
                        'authorisation_id' => (int)$authorisation['id'],
                        'authorisation_changed' => false,
                        'already_current' => true,
                        'state_token' => (string)($unchanged['state_token'] ?? ''),
                    ];
                }
            }

            $accountsService = new IxbrlAccountsFilingApprovalService();
            $accountsStatus = $accountsService->status($companyId, $accountingPeriodId);
            $accountsApproval = is_array($accountsStatus['approval'] ?? null)
                ? (array)$accountsStatus['approval']
                : [];
            $accountsNativeCurrent = !empty($accountsStatus['current'])
                && (string)($accountsApproval['basis_version'] ?? '')
                    === IxbrlAccountsFilingApprovalService::BASIS_VERSION
                && (string)($accountsStatus['approval_source'] ?? $accountsApproval['approval_source'] ?? '')
                    !== 'legacy_combined';

            $accountsCreated = false;
            $factRunCreated = false;
            if ($accountsNativeCurrent) {
                $accountsApprovalId = (int)($accountsApproval['id'] ?? 0);
                $accountsApprovalHash = (string)($accountsApproval['basis_hash'] ?? '');
                $factRunId = $this->currentFactRunId($accountsApprovalId, $accountsApprovalHash);
                if ($factRunId <= 0) {
                    $this->report($progressClosure, 'Rebuilding the neutral approved accounts facts…', 35);
                    $factRunId = $accountsService->rebuildFactsFromCurrentApproval(
                        $companyId,
                        $accountingPeriodId
                    );
                    $factRunCreated = true;
                }
            } else {
                $this->report($progressClosure, 'Recording the statutory-accounts approval and facts…', 28);
                $accountsResult = $accountsService->approveAndBuildFacts(
                    $companyId,
                    $accountingPeriodId,
                    $actor,
                    $note,
                    $progressClosure
                );
                $accountsApprovalId = (int)($accountsResult['approval_id'] ?? 0);
                $accountsApprovalHash = (string)($accountsResult['approval_hash'] ?? '');
                $factRunId = (int)($accountsResult['fact_run_id'] ?? 0);
                $accountsCreated = true;
                $factRunCreated = true;
            }
            if ($accountsApprovalId <= 0 || $factRunId <= 0
                || preg_match('/^[a-f0-9]{64}$/Di', $accountsApprovalHash) !== 1) {
                throw new \RuntimeException('The statutory-accounts approval and fact evidence could not be verified.');
            }
            RequestCache::clear();

            $existingBasisIds = $this->ctBasisIdsForAccountsApproval(
                $companyId,
                $accountingPeriodId,
                $accountsApprovalId
            );
            $this->report($progressClosure, 'Preparing the immutable Corporation Tax period bases…', 65);
            $prepared = $accountsService->prepareHmrcCtPeriodFilingBases(
                $companyId,
                $accountingPeriodId,
                $actor
            );
            $ctBasisIds = $this->positiveIds((array)($prepared['ct_basis_ids'] ?? []));
            if ($ctBasisIds === []) {
                throw new \RuntimeException('No immutable Corporation Tax period bases were prepared.');
            }
            $createdBasisIds = array_values(array_diff($ctBasisIds, $existingBasisIds));
            $reusedBasisIds = array_values(array_intersect($ctBasisIds, $existingBasisIds));
            sort($createdBasisIds, SORT_NUMERIC);
            sort($reusedBasisIds, SORT_NUMERIC);
            RequestCache::clear();

            $hmrcService = new HmrcCtFilingApprovalService();
            $hmrcStatus = $hmrcService->status($companyId, $accountingPeriodId);
            $hmrcApproval = is_array($hmrcStatus['approval'] ?? null)
                ? (array)$hmrcStatus['approval']
                : [];
            $hmrcNativeCurrent = !empty($hmrcStatus['current'])
                && (string)($hmrcStatus['source'] ?? '') === 'native'
                && (int)($hmrcApproval['id'] ?? 0) > 0
                && (int)($hmrcApproval['accounts_filing_approval_id'] ?? 0) === $accountsApprovalId
                && hash_equals(
                    strtolower($accountsApprovalHash),
                    strtolower(trim((string)($hmrcApproval['accounts_filing_approval_hash'] ?? '')))
                );

            $hmrcCreated = false;
            if ($hmrcNativeCurrent) {
                $hmrcApprovalId = (int)$hmrcApproval['id'];
                $hmrcApprovalHash = (string)($hmrcApproval['basis_hash'] ?? '');
            } else {
                $this->report($progressClosure, 'Recording the separate HMRC Corporation Tax approval…', 82);
                $hmrcResult = $hmrcService->approve(
                    $companyId,
                    $accountingPeriodId,
                    $actor,
                    $note,
                    $ctBasisIds,
                    $authorisation
                );
                $hmrcApprovalId = (int)($hmrcResult['approval_id'] ?? 0);
                $hmrcApprovalHash = (string)($hmrcResult['approval_hash'] ?? '');
                $hmrcCreated = true;
            }
            if ($hmrcApprovalId <= 0 || preg_match('/^[a-f0-9]{64}$/Di', $hmrcApprovalHash) !== 1) {
                throw new \RuntimeException('The HMRC Corporation Tax filing approval could not be verified.');
            }
            RequestCache::clear();

            $this->report($progressClosure, 'Verifying both immutable approval records…', 94);
            $verified = $this->status($companyId, $accountingPeriodId);
            if (empty($verified['both_current'])
                || (int)(($verified['accounts'] ?? [])['approval_id'] ?? 0) !== $accountsApprovalId
                || (int)(($verified['hmrc'] ?? [])['approval_id'] ?? 0) !== $hmrcApprovalId) {
                throw new \RuntimeException(
                    (string)(($verified['blockers'] ?? [])[0]
                        ?? 'The combined filing approval did not remain current after persistence.')
                );
            }

            $auditValue = [
                'workflow_version' => self::STATE_TOKEN_VERSION,
                'accounts_approval_id' => $accountsApprovalId,
                'accounts_approval_hash' => strtolower($accountsApprovalHash),
                'accounts_approval_created' => $accountsCreated,
                'fact_run_id' => $factRunId,
                'fact_run_created' => $factRunCreated,
                'hmrc_approval_id' => $hmrcApprovalId,
                'hmrc_approval_hash' => strtolower($hmrcApprovalHash),
                'hmrc_approval_created' => $hmrcCreated,
                'ct_basis_ids' => $ctBasisIds,
                'ct_basis_created_ids' => $createdBasisIds,
                'authorisation_id' => (int)$authorisation['id'],
                'authorisation_changed' => !empty($authorisationResult['changed']),
            ];
            (new YearEndLockService())->writeAuditLog(
                $companyId,
                $accountingPeriodId,
                'ixbrl_accounts_and_ct_approved',
                $actor,
                null,
                $auditValue,
                $note !== '' ? $note : null
            );

            RequestCache::clear();
            $this->report($progressClosure, 'Accounts and Corporation Tax approval complete.', 100);
            return [
                'success' => true,
                'errors' => [],
                'warnings' => [],
                'messages' => ['Accounts and Corporation Tax return approved. No filing was transmitted.'],
                'accounts_approval_id' => $accountsApprovalId,
                'accounts_approval_hash' => strtolower($accountsApprovalHash),
                'accounts_approval_created' => $accountsCreated,
                'accounts_approval_reused' => !$accountsCreated,
                'fact_run_id' => $factRunId,
                'fact_run_created' => $factRunCreated,
                'fact_run_reused' => !$factRunCreated,
                'ct_basis_ids' => $ctBasisIds,
                'ct_basis_created_ids' => $createdBasisIds,
                'ct_basis_reused_ids' => $reusedBasisIds,
                'hmrc_approval_id' => $hmrcApprovalId,
                'hmrc_approval_hash' => strtolower($hmrcApprovalHash),
                'hmrc_approval_created' => $hmrcCreated,
                'hmrc_approval_reused' => !$hmrcCreated,
                'authorisation_id' => (int)$authorisation['id'],
                'authorisation_changed' => !empty($authorisationResult['changed']),
                'state_token' => $this->tokenForSnapshot(
                    $this->stateSnapshot($companyId, $accountingPeriodId, false)
                ),
            ];
        });
    }

    /** @return array<string,mixed> */
    private function safeAccountsStatus(int $companyId, int $accountingPeriodId): array
    {
        try {
            return (new IxbrlAccountsFilingApprovalService())->status($companyId, $accountingPeriodId);
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'state' => 'absent',
                'current' => false,
                'can_approve' => false,
                'approval' => null,
                'errors' => [$exception->getMessage()],
            ];
        }
    }

    /** @return array<string,mixed> */
    private function safeHmrcStatus(int $companyId, int $accountingPeriodId): array
    {
        try {
            return (new HmrcCtFilingApprovalService())->status($companyId, $accountingPeriodId);
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'state' => 'absent',
                'current' => false,
                'can_approve' => false,
                'source' => 'none',
                'approval' => null,
                'errors' => [$exception->getMessage()],
            ];
        }
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private function authorityStatus(array $status, string $authority): array
    {
        $approval = is_array($status['approval'] ?? null) ? (array)$status['approval'] : [];
        $source = (string)($status['source'] ?? $status['approval_source']
            ?? $approval['approval_source'] ?? 'none');
        $version = trim((string)($approval['basis_version'] ?? ''));
        $nativeVersion = $authority === 'accounts'
            ? IxbrlAccountsFilingApprovalService::BASIS_VERSION
            : HmrcCtFilingApprovalService::BASIS_VERSION;
        $nativeCurrent = !empty($status['current'])
            && $version === $nativeVersion
            && $source !== 'legacy_combined'
            && (int)($approval['id'] ?? 0) > 0;

        return [
            'available' => !empty($status['available']),
            'state' => (string)($status['state'] ?? 'absent'),
            'current' => !empty($status['current']),
            'native_current' => $nativeCurrent,
            'can_approve' => !empty($status['can_approve']),
            'source' => $source,
            'approval_id' => (int)($approval['id'] ?? 0) ?: null,
            'approval_hash' => trim((string)($approval['basis_hash'] ?? '')) ?: null,
            'basis_version' => $version !== '' ? $version : null,
            'approval' => $approval,
            'errors' => $this->messages((array)($status['errors'] ?? [])),
        ];
    }

    /** @return list<string> */
    private function ctReadinessBlockers(int $companyId, int $accountingPeriodId): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT ctp.id, ctp.sequence_no, ctp.latest_computation_run_id,
                    run.id AS run_id, run.computation_hash, run.summary_json,
                    snapshot.id AS snapshot_id, snapshot.basis_hash AS snapshot_basis_hash
             FROM corporation_tax_periods ctp
             LEFT JOIN corporation_tax_computation_runs run
               ON run.id = ctp.latest_computation_run_id
             LEFT JOIN corporation_tax_audit_snapshots snapshot
               ON snapshot.computation_run_id = run.id
             WHERE ctp.company_id = :company_id
               AND ctp.accounting_period_id = :period_id
               AND ctp.status <> :superseded
             ORDER BY ctp.sequence_no, ctp.id',
            [
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'superseded' => 'superseded',
            ]
        );
        if ($rows === []) {
            return ['No active Corporation Tax periods are available for HMRC approval.'];
        }

        $errors = [];
        foreach ($rows as $row) {
            $sequence = (int)($row['sequence_no'] ?? 0);
            $prefix = 'CT period ' . ($sequence > 0 ? $sequence : (int)($row['id'] ?? 0));
            $summary = json_decode((string)($row['summary_json'] ?? ''), true);
            $seal = is_array($summary) && is_array($summary['frozen_calculation_basis'] ?? null)
                ? (array)$summary['frozen_calculation_basis']
                : [];
            $sealHash = trim((string)($seal['basis_hash'] ?? ''));
            $sealBasis = $seal;
            unset($sealBasis['basis_hash']);
            if ((int)($row['run_id'] ?? 0) <= 0
                || (int)($row['snapshot_id'] ?? 0) <= 0
                || $sealHash === ''
                || !hash_equals(
                    $sealHash,
                    (new YearEndAcknowledgementService())->hashBasis($sealBasis)
                )
                || (int)($seal['computation_run_id'] ?? 0) !== (int)($row['run_id'] ?? 0)
                || !hash_equals(
                    (string)($seal['computation_hash'] ?? ''),
                    (string)($row['computation_hash'] ?? '')
                )
                || !hash_equals(
                    (string)($seal['tax_audit_basis_hash'] ?? ''),
                    (string)($row['snapshot_basis_hash'] ?? '')
                )) {
                $errors[] = $prefix . ' has no current immutable calculation seal.';
                continue;
            }

            if ((array)($summary['hard_gate_diagnostics'] ?? []) !== []) {
                $errors[] = $prefix . ' contains an unresolved blocking Corporation Tax diagnostic.';
            }
            $errors = array_merge(
                $errors,
                $this->auditAreaBlockers((int)$row['snapshot_id'], $prefix)
            );
            try {
                $ct600a = (new Ct600aService())->build(
                    $companyId,
                    $accountingPeriodId,
                    (int)$row['id']
                );
                if (empty($ct600a['available']) || empty($ct600a['complete'])) {
                    $errors[] = (string)(
                        ($ct600a['blocking_errors'] ?? $ct600a['errors'] ?? [])[0]
                        ?? ($prefix . ' has incomplete CT600A evidence.')
                    );
                }
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return $this->messages($errors);
    }

    /** @return list<string> */
    private function auditAreaBlockers(int $snapshotId, string $periodPrefix): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT area_code, area_hash, detail_json
             FROM corporation_tax_audit_areas
             WHERE snapshot_id = :snapshot_id
             ORDER BY area_code',
            ['snapshot_id' => $snapshotId]
        );
        $seen = [];
        $errors = [];
        foreach ($rows as $row) {
            $code = trim((string)($row['area_code'] ?? ''));
            $detail = json_decode((string)($row['detail_json'] ?? ''), true);
            $basis = is_array($detail) ? $detail : [];
            unset($basis['area_hash'], $basis['pagination']);
            $calculated = is_array($detail)
                ? hash('sha256', $this->canonicalJson($basis))
                : '';
            if ($code === '' || !is_array($detail)
                || !hash_equals((string)($row['area_hash'] ?? ''), (string)($detail['area_hash'] ?? ''))
                || !hash_equals((string)($row['area_hash'] ?? ''), $calculated)) {
                $errors[] = $periodPrefix . ' has an unreadable or invalid frozen Tax Audit schedule.';
                continue;
            }
            $seen[$code] = true;
        }
        foreach (self::REQUIRED_AUDIT_AREAS as $required) {
            if (!isset($seen[$required])) {
                $errors[] = $periodPrefix . ' is missing the ' . str_replace('_', ' ', $required)
                    . ' frozen Tax Audit schedule.';
            }
        }
        return $this->messages($errors);
    }

    private function assertExpectedState(
        int $companyId,
        int $accountingPeriodId,
        string $expectedToken
    ): void {
        if (!\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('The filing approval state can only be secured inside a database transaction.');
        }
        $actual = $this->tokenForSnapshot(
            $this->stateSnapshot($companyId, $accountingPeriodId, true)
        );
        if (!hash_equals(strtolower(trim($expectedToken)), $actual)) {
            throw new \RuntimeException(
                'The accounts or Corporation Tax filing basis changed after this page was loaded. Reload and review it before approving.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function stateSnapshot(int $companyId, int $accountingPeriodId, bool $lock): array
    {
        if (!$this->accountingContextExists($companyId, $accountingPeriodId)) {
            throw new \RuntimeException('Select a company and accounting period.');
        }
        $suffix = $lock && \InterfaceDB::driverName() !== 'sqlite' ? ' FOR UPDATE' : '';
        $params = ['company_id' => $companyId, 'period_id' => $accountingPeriodId];

        $period = \InterfaceDB::fetchOne(
            'SELECT id, company_id, period_start, period_end, created_at
             FROM accounting_periods
             WHERE id = :period_id AND company_id = :company_id
             LIMIT 1' . $suffix,
            $params
        );
        $company = \InterfaceDB::fetchOne(
            'SELECT * FROM companies
             WHERE id = :company_id
             LIMIT 1' . $suffix,
            ['company_id' => $companyId]
        );
        $companySettings = \InterfaceDB::fetchAll(
            'SELECT id, setting, type, value, created_at, updated_at
             FROM company_settings
             WHERE company_id = :company_id
             ORDER BY setting, id' . $suffix,
            ['company_id' => $companyId]
        );
        $yearEnd = \InterfaceDB::fetchOne(
            'SELECT id, is_locked, locked_at, locked_by, review_notes, updated_at
             FROM year_end_reviews
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             LIMIT 1' . $suffix,
            $params
        );
        $disclosure = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_accounts_disclosures
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             LIMIT 1' . $suffix,
            $params
        );
        $directorsReportSources = \InterfaceDB::tableExists('year_end_review_acknowledgements')
            ? \InterfaceDB::fetchAll(
                'SELECT check_code, acknowledged_at, note, basis_version, basis_hash
                 FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                 ORDER BY acknowledged_at, check_code' . $suffix,
                $params
            )
            : [];
        $authorisation = \InterfaceDB::fetchOne(
            'SELECT * FROM ct600_return_authorisations
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             LIMIT 1' . $suffix,
            $params
        );
        $scope = \InterfaceDB::fetchOne(
            'SELECT * FROM corporation_tax_scope_confirmations
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             LIMIT 1' . $suffix,
            $params
        );
        $accountsApprovals = \InterfaceDB::fetchAll(
            'SELECT id, disclosure_id, disclosure_revision, year_end_review_id,
                    year_end_locked_at, basis_version, basis_hash, approved_at
             FROM ixbrl_accounts_filing_approvals
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             ORDER BY id' . $suffix,
            $params
        );
        $hmrcApprovals = \InterfaceDB::fetchAll(
            'SELECT id, accounts_filing_approval_id, accounts_filing_approval_hash,
                    return_authorisation_id, return_authorisation_hash, ct_scope_hash,
                    basis_version, basis_hash, legacy_combined_approval_id, approved_at
             FROM hmrc_ct_filing_approvals
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             ORDER BY id' . $suffix,
            $params
        );
        $ctPeriods = \InterfaceDB::fetchAll(
            'SELECT ctp.id, ctp.sequence_no, ctp.period_start, ctp.period_end,
                    ctp.status, ctp.latest_computation_run_id, ctp.updated_at,
                    run.status AS run_status, run.computation_hash, run.summary_json,
                    snapshot.id AS snapshot_id, snapshot.basis_version AS snapshot_basis_version,
                    snapshot.basis_hash AS snapshot_basis_hash
             FROM corporation_tax_periods ctp
             LEFT JOIN corporation_tax_computation_runs run
               ON run.id = ctp.latest_computation_run_id
             LEFT JOIN corporation_tax_audit_snapshots snapshot
               ON snapshot.computation_run_id = run.id
             WHERE ctp.company_id = :company_id
               AND ctp.accounting_period_id = :period_id
               AND ctp.status <> :superseded
             ORDER BY ctp.sequence_no, ctp.id' . $suffix,
            $params + ['superseded' => 'superseded']
        );
        foreach ($ctPeriods as &$ctPeriod) {
            $ctPeriod['summary_sha256'] = hash('sha256', (string)($ctPeriod['summary_json'] ?? ''));
            unset($ctPeriod['summary_json']);
        }
        unset($ctPeriod);

        $ctAuditAreas = \InterfaceDB::fetchAll(
            'SELECT area.id, area.snapshot_id, area.area_code, area.area_hash,
                    area.detail_json
             FROM corporation_tax_audit_areas area
             INNER JOIN corporation_tax_audit_snapshots snapshot
               ON snapshot.id = area.snapshot_id
             INNER JOIN corporation_tax_periods ctp
               ON ctp.id = snapshot.ct_period_id
              AND ctp.latest_computation_run_id = snapshot.computation_run_id
             WHERE ctp.company_id = :company_id
               AND ctp.accounting_period_id = :period_id
               AND ctp.status <> :superseded
             ORDER BY area.snapshot_id, area.area_code, area.id' . $suffix,
            $params + ['superseded' => 'superseded']
        );
        foreach ($ctAuditAreas as &$ctAuditArea) {
            $ctAuditArea['detail_sha256'] = hash(
                'sha256',
                (string)($ctAuditArea['detail_json'] ?? '')
            );
            unset($ctAuditArea['detail_json']);
        }
        unset($ctAuditArea);

        $ctBases = \InterfaceDB::fetchAll(
            'SELECT id, filing_approval_id, ct_period_id, computation_run_id,
                    calculation_basis_version, calculation_basis_hash, basis_version, basis_hash
             FROM ct_period_filing_bases
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             ORDER BY id' . $suffix,
            $params
        );
        $hmrcLinks = \InterfaceDB::fetchAll(
            'SELECT link.hmrc_ct_filing_approval_id, link.ct_period_filing_basis_id,
                    link.ct_period_id, link.basis_hash
             FROM hmrc_ct_filing_approval_period_bases link
             INNER JOIN hmrc_ct_filing_approvals approval
               ON approval.id = link.hmrc_ct_filing_approval_id
             WHERE approval.company_id = :company_id
               AND approval.accounting_period_id = :period_id
             ORDER BY link.hmrc_ct_filing_approval_id, link.ct_period_id' . $suffix,
            $params
        );
        $factRuns = \InterfaceDB::fetchAll(
            'SELECT run.id, run.status, run.basis_version, run.basis_hash,
                    run.filing_approval_id, run.filing_approval_hash, COUNT(fact.id) AS fact_count
             FROM ixbrl_generation_runs run
             LEFT JOIN ixbrl_generation_facts fact ON fact.run_id = run.id
             WHERE run.company_id = :company_id AND run.accounting_period_id = :period_id
               AND run.filing_approval_id IS NOT NULL
             GROUP BY run.id, run.status, run.basis_version, run.basis_hash,
                      run.filing_approval_id, run.filing_approval_hash
             ORDER BY run.id' . $suffix,
            $params
        );

        return [
            'state_version' => self::STATE_TOKEN_VERSION,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'company_identity' => is_array($company) ? $company : null,
            'company_settings' => $companySettings,
            'accounting_period' => is_array($period) ? $period : null,
            'year_end_lock' => is_array($yearEnd) ? $yearEnd : null,
            'directors_report_sources' => $directorsReportSources,
            'disclosure' => is_array($disclosure) ? $disclosure : null,
            'return_authorisation' => is_array($authorisation) ? $authorisation : null,
            'ct_scope' => is_array($scope) ? $scope : null,
            'accounts_approvals' => $accountsApprovals,
            'hmrc_approvals' => $hmrcApprovals,
            'active_ct_periods_and_runs' => $ctPeriods,
            'active_ct_audit_areas' => $ctAuditAreas,
            'ct_period_filing_bases' => $ctBases,
            'hmrc_approval_period_links' => $hmrcLinks,
            'approved_fact_runs' => $factRuns,
        ];
    }

    private function tokenForSnapshot(array $snapshot): string
    {
        return hash(
            'sha256',
            self::STATE_TOKEN_VERSION . '|' . $this->canonicalJson($snapshot)
        );
    }

    /** @return array<string,mixed> */
    private function mergePersistedWhenLocked(
        int $companyId,
        int $accountingPeriodId,
        array $submitted
    ): array {
        $editing = in_array(
            strtolower(trim((string)($submitted['ixbrl_approval_editing']
                ?? $submitted['form_editing'] ?? '0'))),
            ['1', 'on', 'true', 'yes'],
            true
        );
        if ($editing) {
            return $submitted;
        }

        $disclosureStatus = (new IxbrlAccountsDisclosureService())->fetch(
            $companyId,
            $accountingPeriodId
        );
        $base = (array)($disclosureStatus['disclosures'] ?? []);
        $base = array_replace($base, (array)($disclosureStatus['trading_status_answers'] ?? []));
        $authorisation = (new Ct600ReturnAuthorisationService())->fetch(
            $companyId,
            $accountingPeriodId
        );
        if ($authorisation !== []) {
            if ((int)($authorisation['declarant_director_id'] ?? 0) > 0) {
                $base['declarant_authority'] = 'director:'
                    . (int)$authorisation['declarant_director_id'];
            } elseif ((int)($authorisation['declarant_role_id'] ?? 0) > 0) {
                $base['declarant_authority'] = 'party-role:'
                    . (int)$authorisation['declarant_role_id'];
            }
            foreach ([
                'original_unfiled_confirmed',
                'authority_confirmed',
                'declaration_confirmed',
            ] as $key) {
                $base[$key] = !empty($authorisation[$key]) ? '1' : '0';
            }
        }

        // Disabled browser controls may be absent or represented as null.
        // Preserve the immutable page display in that mode, while retaining
        // explicit non-null hidden/action values from the request.
        $overlay = array_filter(
            $submitted,
            static fn(mixed $value): bool => $value !== null
        );
        return array_replace($base, $overlay);
    }

    private function currentFactRunId(int $approvalId, string $approvalHash): int
    {
        if ($approvalId <= 0 || preg_match('/^[a-f0-9]{64}$/Di', $approvalHash) !== 1) {
            return 0;
        }
        $runs = \InterfaceDB::fetchAll(
            'SELECT run.id, run.basis_version, run.basis_hash,
                    COUNT(fact.id) AS fact_count
             FROM ixbrl_generation_runs run
             LEFT JOIN ixbrl_generation_facts fact ON fact.run_id = run.id
             WHERE run.filing_approval_id = :approval_id
               AND run.filing_approval_hash = :approval_hash
               AND run.status IN (:ready, :generated)
             GROUP BY run.id, run.basis_version, run.basis_hash
             HAVING COUNT(fact.id) > 0
             ORDER BY run.id DESC',
            [
                'approval_id' => $approvalId,
                'approval_hash' => strtolower($approvalHash),
                'ready' => 'ready',
                'generated' => 'generated',
            ]
        );
        $facts = new IxbrlFactBuilderService();
        foreach ($runs as $run) {
            if ((string)($run['basis_version'] ?? '') !== IxbrlTaxonomyProfileService::BASIS_VERSION
                || trim((string)($run['basis_hash'] ?? '')) === ''
                || (int)($run['fact_count'] ?? 0) <= 0) {
                continue;
            }
            $freshness = $facts->getRunFreshness((int)($run['id'] ?? 0));
            if ((string)($freshness['state'] ?? '') === 'current') {
                return (int)$run['id'];
            }
        }
        return 0;
    }

    /** @return list<int> */
    private function ctBasisIdsForAccountsApproval(
        int $companyId,
        int $accountingPeriodId,
        int $accountsApprovalId
    ): array {
        return $this->positiveIds(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            \InterfaceDB::fetchAll(
                'SELECT id FROM ct_period_filing_bases
                 WHERE company_id = :company_id
                   AND accounting_period_id = :period_id
                   AND filing_approval_id = :approval_id
                 ORDER BY id',
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                    'approval_id' => $accountsApprovalId,
                ]
            )
        ));
    }

    /** @return list<int> */
    private function ctBasisIdsForHmrcApproval(int $hmrcApprovalId): array
    {
        if ($hmrcApprovalId <= 0) {
            return [];
        }
        return $this->positiveIds(array_map(
            static fn(array $row): int => (int)($row['ct_period_filing_basis_id'] ?? 0),
            \InterfaceDB::fetchAll(
                'SELECT ct_period_filing_basis_id
                 FROM hmrc_ct_filing_approval_period_bases
                 WHERE hmrc_ct_filing_approval_id = :approval_id
                 ORDER BY ct_period_id, ct_period_filing_basis_id',
                ['approval_id' => $hmrcApprovalId]
            )
        ));
    }

    /** @return list<int> */
    private function positiveIds(array $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function accountingContextExists(int $companyId, int $accountingPeriodId): bool
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists('accounting_periods')) {
            return false;
        }
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM accounting_periods
             WHERE id = :period_id AND company_id = :company_id',
            ['period_id' => $accountingPeriodId, 'company_id' => $companyId]
        ) === 1;
    }

    private function schemaReady(): bool
    {
        foreach ([
            'companies',
            'company_settings',
            'accounting_periods',
            'year_end_reviews',
            'ixbrl_accounts_disclosures',
            'ct600_return_authorisations',
            'corporation_tax_scope_confirmations',
            'corporation_tax_periods',
            'corporation_tax_computation_runs',
            'corporation_tax_audit_snapshots',
            'corporation_tax_audit_areas',
            'ixbrl_accounts_filing_approvals',
            'hmrc_ct_filing_approvals',
            'ct_period_filing_bases',
            'hmrc_ct_filing_approval_period_bases',
            'ixbrl_generation_runs',
            'ixbrl_generation_facts',
            'year_end_audit_log',
        ] as $table) {
            if (!\InterfaceDB::tableExists($table)) {
                return false;
            }
        }
        return \InterfaceDB::columnExists('ixbrl_generation_runs', 'filing_approval_id')
            && \InterfaceDB::columnExists('ixbrl_generation_runs', 'filing_approval_hash')
            && \InterfaceDB::columnExists('hmrc_ct_filing_approvals', 'accounts_filing_approval_id');
    }

    private function assertSchemaReady(): void
    {
        if (!$this->schemaReady()) {
            throw new \RuntimeException(
                'Apply the unified accounts and Corporation Tax approval prerequisites before approving this filing.'
            );
        }
    }

    private function assertActorAndToken(string $actor, string $expectedToken): void
    {
        if (trim($actor) === '') {
            throw new \RuntimeException('The filing approval must identify its approver.');
        }
        if (preg_match('/^[a-f0-9]{64}$/Di', trim($expectedToken)) !== 1) {
            throw new \RuntimeException('Reload the approval page before saving or approving this filing.');
        }
    }

    /** @param array<string,mixed> $result */
    private function assertSuccessfulResult(array $result, string $fallback): void
    {
        if (!empty($result['success'])) {
            return;
        }
        $errors = $this->messages((array)($result['errors'] ?? []));
        throw new \RuntimeException((string)($errors[0] ?? $fallback));
    }

    /** @return array<string,mixed> */
    private function transactionWithCacheReset(\Closure $operation): array
    {
        try {
            return (array)\InterfaceDB::transaction($operation);
        } finally {
            // Status services cache read models for the duration of a request.
            // A late failure can roll the database transaction back after
            // those services observed provisional approval rows, so never let
            // an in-transaction read model escape into the card rerender.
            RequestCache::clear();
        }
    }

    /** @return list<string> */
    private function messages(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $values
        ), static fn(string $value): bool => $value !== '')));
    }

    private function report(?\Closure $progress, string $message, int $percent): void
    {
        if ($progress !== null) {
            $progress($message, max(0, min(100, $percent)));
        }
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            return $item;
        };
        return PersistentJson::encode(
            $normalise($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
