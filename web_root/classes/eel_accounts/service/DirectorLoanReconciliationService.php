<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class DirectorLoanReconciliationService
{
    public const OFFSET_JOURNAL_TAG = 'director_loan_offset';
    public const OFFSET_JOURNAL_KEY = 'primary';
    public const OFFSET_JOURNAL_DESCRIPTION = 'Director loan control reclassification';
    public const LEGACY_REPAIR_JOURNAL_KEY_PREFIX = 'legacy-unattributed-reversal:';
    public const LEGACY_REPAIR_JOURNAL_DESCRIPTION = 'Legacy Director Loan offset reversal';
    public const YEAR_END_ACKNOWLEDGEMENT_CODE = 'director_loan_year_end_review';

    public function __construct(
        private readonly ?ManualJournalService $journalService = null,
        private readonly ?DirectorLoanService $directorLoanService = null,
        private readonly ?YearEndAcknowledgementService $acknowledgementService = null,
        private readonly ?YearEndLockService $lockService = null,
    ) {
    }

    public function fetchYearEndConfirmationContext(int $companyId, int $accountingPeriodId): array
    {
        return $this->fetchContext($companyId, $accountingPeriodId);
    }

    /** @return array{success: bool, errors: list<string>} */
    public function verifyJournalEvidence(int $companyId, int $accountingPeriodId, int $journalId): array
    {
        $journal = \InterfaceDB::fetchOne(
            'SELECT journal_date, is_posted
             FROM journals
             WHERE id = :journal_id
               AND company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            [
                'journal_id' => $journalId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
        if (!is_array($journal)) {
            return ['success' => false, 'errors' => ['The Director Loan offset journal could not be found in the selected period.']];
        }

        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return [
                'success' => false,
                'errors' => (array)($context['errors'] ?? ['The Director Loan evidence context is unavailable.']),
            ];
        }

        $errors = [];
        $accountingPeriod = (array)($context['accounting_period'] ?? []);
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        if ((int)($journal['is_posted'] ?? 0) !== 1) {
            $errors[] = 'The Director Loan offset journal is not posted.';
        }
        if ($periodEnd !== '' && (string)($journal['journal_date'] ?? '') !== $periodEnd) {
            $errors[] = 'The Director Loan offset journal date does not match the period end.';
        }
        if (empty($context['has_activity'])) {
            $errors[] = 'The Director Loan evidence context has no activity for this period.';
        }
        foreach ((array)($context['warnings'] ?? []) as $warning) {
            $errors[] = (string)$warning;
        }
        if (abs((float)($context['pending_adjustment_amount'] ?? 0)) >= 0.005) {
            $errors[] = 'The posted Director Loan reclassification is not current with the calculated period-end position.';
        }

        return [
            'success' => $errors === [],
            'errors' => array_values(array_unique(array_filter(array_map('strval', $errors)))),
        ];
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $statement = ($this->directorLoanService ?? new DirectorLoanService())
            ->fetchStatement($companyId, $accountingPeriodId);
        if (empty($statement['success'])) {
            return [
                'available' => false,
                'errors' => (array)($statement['errors'] ?? ['Director loan review is unavailable.']),
                'statement' => $statement,
            ];
        }

        $taxReview = ($this->directorLoanService ?? new DirectorLoanService())
            ->fetchTaxReviewSummary($companyId, $accountingPeriodId);
        $ackService = $this->acknowledgementService ?? new YearEndAcknowledgementService();
        $basis = $this->confirmationBasis($ackService, $statement, $taxReview);
        $acknowledgement = $ackService->fetch(
            $companyId,
            $accountingPeriodId,
            self::YEAR_END_ACKNOWLEDGEMENT_CODE
        );
        $evaluation = $ackService->evaluate($acknowledgement, $basis, false);

        $legacyOffset = $this->legacyUnattributedOffset(
            $companyId,
            (string)($statement['accounting_period']['period_end'] ?? ''),
            (int)($statement['asset_nominal']['id'] ?? 0),
            (int)($statement['liability_nominal']['id'] ?? 0)
        );
        $unresolvedPosted = 0.0;
        $legacyNetAmount = 0.0;
        $proposedLines = [];
        foreach ((array)$statement['per_director'] as $position) {
            $directorId = (int)($position['director_id'] ?? 0);
            $posted = round((float)($position['posted_reclassification'] ?? 0), 2);
            if ($directorId <= 0) {
                $unresolvedPosted = round($unresolvedPosted + abs($posted), 2);
                $legacyNetAmount = round($legacyNetAmount + $posted, 2);
                continue;
            }

            $pending = round((float)($position['pending_reclassification'] ?? 0), 2);
            if (abs($pending) < 0.005) {
                continue;
            }
            $proposedLines = array_merge(
                $proposedLines,
                $this->reclassificationLines(
                    (int)$statement['asset_nominal']['id'],
                    (int)$statement['liability_nominal']['id'],
                    $directorId,
                    $pending,
                    (string)($position['director_name'] ?? '')
                )
            );
        }

        $unattributedCount = (int)($statement['unattributed_count'] ?? 0)
            + (int)($statement['invalid_director_count'] ?? 0);
        $confirmationCurrent = !empty($evaluation['current']);
        $pendingAmount = round((float)($statement['pending_reclassification_magnitude'] ?? 0), 2);
        $canPost = abs($pendingAmount) >= 0.005
            && $confirmationCurrent
            && $unattributedCount === 0
            && $unresolvedPosted < 0.005
            && $proposedLines !== [];

        $warnings = [];
        if ($unattributedCount > 0) {
            $warnings[] = $unattributedCount . ' Director Loan entr'
                . ($unattributedCount === 1 ? 'y is' : 'ies are')
                . ' not attributed to a valid same-company director.';
        }
        if ($unresolvedPosted >= 0.005) {
            $warnings[] = 'A legacy Director Loan offset journal cannot be attributed deterministically and remains an unresolved historical accounting record.';
        }

        return [
            'available' => true,
            'errors' => [],
            'warnings' => $warnings,
            'statement' => $statement,
            'tax_review' => $taxReview,
            'accounting_period' => (array)$statement['accounting_period'],
            'asset_nominal' => (array)$statement['asset_nominal'],
            'liability_nominal' => (array)$statement['liability_nominal'],
            'per_director' => (array)$statement['per_director'],
            'unattributed_entries' => (array)$statement['unattributed_entries'],
            'invalid_director_entries' => (array)$statement['invalid_director_entries'],
            'unattributed_count' => $unattributedCount,
            'has_activity' => !empty($statement['has_activity']),
            'asset_receivable' => (float)$statement['asset_receivable'],
            'liability_payable' => (float)$statement['liability_payable'],
            'net_position' => (float)$statement['net_position'],
            'net_position_label' => (string)$statement['net_position_label'],
            'potential_s455_exposure' => (float)$statement['potential_s455_exposure'],
            'required_reclassification_amount' => (float)$statement['desired_reclassification'],
            'desired_reclassification_amount' => (float)$statement['desired_reclassification'],
            'posted_reclassification_amount' => (float)$statement['posted_reclassification'],
            'pending_adjustment_amount' => $pendingAmount,
            'proposed_lines' => $proposedLines,
            'legacy_unresolved_reclassification_amount' => $unresolvedPosted,
            'legacy_unresolved_reclassification_net_amount' => $legacyNetAmount,
            'legacy_unresolved_source_journal_ids' => (array)$legacyOffset['journal_ids'],
            'confirmation_basis' => $basis,
            'acknowledgement' => $acknowledgement,
            'acknowledgement_state' => (string)($evaluation['state'] ?? 'absent'),
            'acknowledgement_current' => $confirmationCurrent,
            'acknowledged_at' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledged_by' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'can_confirm' => !empty($statement['has_activity'])
                && $unattributedCount === 0
                && $unresolvedPosted < 0.005,
            'can_post' => $canPost,
            'post_blocked_reason' => $this->postBlockedReason(
                $statement,
                $confirmationCurrent,
                $unattributedCount,
                $unresolvedPosted,
                $pendingAmount
            ),
        ];
    }

    public function saveYearEndReview(
        int $companyId,
        int $accountingPeriodId,
        bool $acknowledged,
        string $changedBy = 'web_app'
    ): array {
        $scopeBlock = (new VatSupportScopeService())
            ->mutationBlockResult($companyId, 'save the Director Loan Year End Review');
        if ($scopeBlock !== null) {
            return $scopeBlock;
        }

        ($this->lockService ?? new YearEndLockService())
            ->assertUnlocked($companyId, $accountingPeriodId, 'change the Director Loan Year End Review');

        $service = $this->acknowledgementService ?? new YearEndAcknowledgementService();
        if (!$acknowledged) {
            return $service->revoke(
                $companyId,
                $accountingPeriodId,
                self::YEAR_END_ACKNOWLEDGEMENT_CODE,
                true
            );
        }

        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return ['success' => false, 'errors' => (array)$context['errors']];
        }
        if (empty($context['has_activity'])) {
            return ['success' => false, 'errors' => ['There is no Director Loan activity or balance requiring confirmation.']];
        }
        if (empty($context['can_confirm'])) {
            $errors = abs((float)($context['legacy_unresolved_reclassification_amount'] ?? 0)) >= 0.005
                ? ['Repair the legacy Director Loan offset journal before confirming the facts.']
                : ['Attribute every Director Loan entry to a valid same-company director before confirming the facts.'];
            return ['success' => false, 'errors' => $errors];
        }

        return $service->save(
            $companyId,
            $accountingPeriodId,
            self::YEAR_END_ACKNOWLEDGEMENT_CODE,
            (array)$context['confirmation_basis'],
            $changedBy,
            '',
            true
        );
    }

    public function postOffset(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array
    {
        $scopeBlock = (new VatSupportScopeService())
            ->mutationBlockResult($companyId, 'post the Director Loan control reclassification');
        if ($scopeBlock !== null) {
            return $scopeBlock;
        }

        ($this->lockService ?? new YearEndLockService())
            ->assertUnlocked($companyId, $accountingPeriodId, 'post the Director Loan control reclassification');

        $context = $this->fetchContext($companyId, $accountingPeriodId);
        $pending = round((float)($context['pending_adjustment_amount'] ?? 0), 2);
        if (abs($pending) < 0.005) {
            return ['success' => true, 'already_current' => true, 'context' => $context];
        }
        if (empty($context['can_post'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => [(string)($context['post_blocked_reason'] ?? 'The Director Loan control reclassification cannot be posted.')],
                'context' => $context,
            ];
        }

        $period = (array)$context['accounting_period'];
        $result = ($this->journalService ?? new ManualJournalService())->saveTaggedJournal(
            $companyId,
            $accountingPeriodId,
            self::OFFSET_JOURNAL_TAG,
            self::OFFSET_JOURNAL_KEY,
            (string)$period['period_end'],
            self::OFFSET_JOURNAL_DESCRIPTION,
            (array)$context['proposed_lines'],
            'system_generated',
            null,
            null,
            'Applies the calculated same-director control-account reclassification. No balances belonging to different directors are offset.',
            $changedBy
        );
        if (!empty($result['success'])) {
            $result['context'] = $this->fetchContext($companyId, $accountingPeriodId);
        }
        return $result;
    }

    /**
     * Reverse the combined legacy offset that has no director attribution without changing its source journals.
     *
     * @return array{success: bool, repaired?: bool, already_current?: bool, journal?: array|null, errors?: list<string>, context?: array}
     */
    public function repairLegacyOffset(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array
    {
        $scopeBlock = (new VatSupportScopeService())
            ->mutationBlockResult($companyId, 'repair the legacy Director Loan offset journal');
        if ($scopeBlock !== null) {
            return $scopeBlock;
        }

        ($this->lockService ?? new YearEndLockService())
            ->assertUnlocked($companyId, $accountingPeriodId, 'repair the legacy Director Loan offset journal');

        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return ['success' => false, 'errors' => (array)($context['errors'] ?? ['The Director Loan evidence context is unavailable.'])];
        }

        $netAmount = round((float)($context['legacy_unresolved_reclassification_net_amount'] ?? 0), 2);
        if (abs($netAmount) < 0.005) {
            return ['success' => true, 'already_current' => true, 'context' => $context];
        }

        $period = (array)($context['accounting_period'] ?? []);
        $periodEnd = trim((string)($period['period_end'] ?? ''));
        $assetNominalId = (int)(($context['asset_nominal'] ?? [])['id'] ?? 0);
        $liabilityNominalId = (int)(($context['liability_nominal'] ?? [])['id'] ?? 0);
        if ($periodEnd === '' || $assetNominalId <= 0 || $liabilityNominalId <= 0) {
            return ['success' => false, 'errors' => ['The legacy Director Loan offset cannot be repaired because its period or control accounts are unavailable.']];
        }

        $sourceJournalIds = array_values(array_unique(array_filter(array_map('intval', (array)(
            $context['legacy_unresolved_source_journal_ids'] ?? []
        )), static fn(int $id): bool => $id > 0)));
        $repairKey = self::LEGACY_REPAIR_JOURNAL_KEY_PREFIX . substr(hash(
            'sha256',
            $accountingPeriodId . ':' . number_format($netAmount, 2, '.', '') . ':' . implode(',', $sourceJournalIds)
        ), 0, 24);
        $notes = 'Reverses the combined net legacy Director Loan control-account offset with no deterministic director attribution.'
            . ' Source journal IDs: ' . ($sourceJournalIds !== [] ? implode(', ', $sourceJournalIds) : 'none') . '.';

        $result = ($this->journalService ?? new ManualJournalService())->saveTaggedJournal(
            $companyId,
            $accountingPeriodId,
            self::OFFSET_JOURNAL_TAG,
            $repairKey,
            $periodEnd,
            self::LEGACY_REPAIR_JOURNAL_DESCRIPTION,
            $this->legacyReversalLines($assetNominalId, $liabilityNominalId, $netAmount),
            'system_generated',
            count($sourceJournalIds) === 1 ? $sourceJournalIds[0] : null,
            null,
            $notes,
            $changedBy
        );
        if (empty($result['success'])) {
            return $result;
        }

        $journal = is_array($result['journal'] ?? null) ? $result['journal'] : null;
        ($this->lockService ?? new YearEndLockService())->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            'director_loan_legacy_offset_repaired',
            $changedBy,
            [
                'legacy_unresolved_reclassification_net_amount' => number_format($netAmount, 2, '.', ''),
                'source_journal_ids' => $sourceJournalIds,
            ],
            [
                'repair_journal_id' => (int)($journal['id'] ?? 0),
                'repair_journal_key' => $repairKey,
                'reversed_net_amount' => number_format(-$netAmount, 2, '.', ''),
            ],
            $notes
        );

        return [
            'success' => true,
            'repaired' => true,
            'journal' => $journal,
            'context' => $this->fetchContext($companyId, $accountingPeriodId),
        ];
    }

    public function confirmationBasisForContext(array $context): ?array
    {
        return isset($context['confirmation_basis']) && is_array($context['confirmation_basis'])
            ? $context['confirmation_basis']
            : null;
    }

    /** @return array{net_amount: float, journal_ids: list<int>} */
    private function legacyUnattributedOffset(
        int $companyId,
        string $periodEnd,
        int $assetNominalId,
        int $liabilityNominalId
    ): array {
        if ($companyId <= 0 || $periodEnd === '' || $assetNominalId <= 0 || $liabilityNominalId <= 0) {
            return ['net_amount' => 0.0, 'journal_ids' => []];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT j.id AS journal_id,
                    SUM(CASE
                      WHEN jl.nominal_account_id = :asset_nominal_id THEN jl.credit - jl.debit
                      WHEN jl.nominal_account_id = :liability_nominal_id THEN jl.debit - jl.credit
                      ELSE 0
                    END) / 2 AS posted_amount
             FROM journal_entry_metadata jem
             INNER JOIN journals j ON j.id = jem.journal_id
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date <= :period_end
               AND jem.journal_tag = :journal_tag
               AND jl.party_id IS NULL
               AND jl.nominal_account_id IN (:asset_nominal_id_match, :liability_nominal_id_match)
             GROUP BY j.id
             HAVING ABS(SUM(CASE
               WHEN jl.nominal_account_id = :asset_nominal_id_having THEN jl.credit - jl.debit
               WHEN jl.nominal_account_id = :liability_nominal_id_having THEN jl.debit - jl.credit
               ELSE 0
             END) / 2) >= 0.005
             ORDER BY j.journal_date ASC, j.id ASC',
            [
                'company_id' => $companyId,
                'period_end' => $periodEnd,
                'journal_tag' => self::OFFSET_JOURNAL_TAG,
                'asset_nominal_id' => $assetNominalId,
                'liability_nominal_id' => $liabilityNominalId,
                'asset_nominal_id_match' => $assetNominalId,
                'liability_nominal_id_match' => $liabilityNominalId,
                'asset_nominal_id_having' => $assetNominalId,
                'liability_nominal_id_having' => $liabilityNominalId,
            ]
        );

        $netAmount = 0.0;
        $journalIds = [];
        foreach ($rows as $row) {
            $amount = round((float)($row['posted_amount'] ?? 0), 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $netAmount = round($netAmount + $amount, 2);
            $journalId = (int)($row['journal_id'] ?? 0);
            if ($journalId > 0) {
                $journalIds[] = $journalId;
            }
        }

        if (abs($netAmount) < 0.005) {
            return ['net_amount' => 0.0, 'journal_ids' => []];
        }

        return [
            'net_amount' => $netAmount,
            'journal_ids' => array_values(array_unique($journalIds)),
        ];
    }

    /** @return list<array{nominal_account_id: int, party_id: null, debit: string, credit: string, line_description: string}> */
    private function legacyReversalLines(int $assetNominalId, int $liabilityNominalId, float $legacyNetAmount): array
    {
        $amount = number_format(abs($legacyNetAmount), 2, '.', '');
        $description = 'Reverse legacy unattributed Director Loan offset';
        if ($legacyNetAmount > 0) {
            return [
                ['nominal_account_id' => $assetNominalId, 'party_id' => null, 'debit' => $amount, 'credit' => '0.00', 'line_description' => $description],
                ['nominal_account_id' => $liabilityNominalId, 'party_id' => null, 'debit' => '0.00', 'credit' => $amount, 'line_description' => $description],
            ];
        }

        return [
            ['nominal_account_id' => $liabilityNominalId, 'party_id' => null, 'debit' => $amount, 'credit' => '0.00', 'line_description' => $description],
            ['nominal_account_id' => $assetNominalId, 'party_id' => null, 'debit' => '0.00', 'credit' => $amount, 'line_description' => $description],
        ];
    }

    private function confirmationBasis(
        YearEndAcknowledgementService $service,
        array $statement,
        array $taxReview
    ): array {
        $entryFacts = [];
        foreach ((array)$statement['attribution_entries'] as $entry) {
            $entryFacts[] = [
                'journal_line_id' => (int)$entry['journal_line_id'],
                'journal_id' => (int)$entry['journal_id'],
                'journal_date' => (string)$entry['journal_date'],
                'source_type' => (string)$entry['source_type'],
                'source_key' => (string)$entry['source_ref'],
                'nominal_account_id' => (int)$entry['nominal_account_id'],
                'director_id' => (int)($entry['director_id'] ?? 0),
                'debit_amount' => number_format((float)$entry['debit'], 2, '.', ''),
                'credit_amount' => number_format((float)$entry['credit'], 2, '.', ''),
            ];
        }

        $directorFacts = [];
        $legacyUnresolvedNetAmount = 0.0;
        foreach ((array)$statement['per_director'] as $position) {
            if ((int)($position['director_id'] ?? 0) <= 0) {
                $legacyUnresolvedNetAmount = round(
                    $legacyUnresolvedNetAmount + (float)($position['posted_reclassification'] ?? 0),
                    2
                );
                continue;
            }
            $directorFacts[] = [
                'director_id' => (int)$position['director_id'],
                'director_identity_key' => implode('|', [
                    (int)$position['director_id'],
                    (string)$position['director_name'],
                    (string)($position['appointed_on'] ?? ''),
                    (string)($position['resigned_on'] ?? ''),
                ]),
                'gross_asset_amount' => number_format((float)$position['gross_asset'], 2, '.', ''),
                'gross_liability_amount' => number_format((float)$position['gross_liability'], 2, '.', ''),
                'desired_reclassification_amount' => number_format((float)$position['desired_reclassification'], 2, '.', ''),
                'net_closing_balance' => number_format((float)$position['net_closing_position'], 2, '.', ''),
                'potential_s455_exposure_amount' => number_format((float)$position['potential_s455_exposure'], 2, '.', ''),
            ];
        }

        return $service->buildBasis(self::YEAR_END_ACKNOWLEDGEMENT_CODE, [
            'accounting_period_id' => (int)$statement['accounting_period']['id'],
            'entry_count' => count($entryFacts),
            'entry_facts' => $entryFacts,
            'director_facts' => $directorFacts,
            'unattributed_count' => (int)$statement['unattributed_count'],
            'invalid_director_count' => (int)$statement['invalid_director_count'],
            'legacy_unresolved_reclassification_net_amount' => number_format($legacyUnresolvedNetAmount, 2, '.', ''),
            'potential_s455_exposure_amount' => number_format((float)($taxReview['exposure_amount'] ?? 0), 2, '.', ''),
            'desired_reclassification_amount' => number_format((float)$statement['desired_reclassification'], 2, '.', ''),
        ]);
    }

    private function reclassificationLines(
        int $assetNominalId,
        int $liabilityNominalId,
        int $directorId,
        float $pending,
        string $directorName
    ): array {
        $amount = number_format(abs($pending), 2, '.', '');
        $description = 'Director loan control reclassification - ' . trim($directorName);
        if ($pending > 0) {
            return [
                [
                    'nominal_account_id' => $liabilityNominalId,
                    'party_id' => $directorId,
                    'debit' => $amount,
                    'credit' => '0.00',
                    'line_description' => $description,
                ],
                [
                    'nominal_account_id' => $assetNominalId,
                    'party_id' => $directorId,
                    'debit' => '0.00',
                    'credit' => $amount,
                    'line_description' => $description,
                ],
            ];
        }

        return [
            [
                'nominal_account_id' => $assetNominalId,
                'party_id' => $directorId,
                'debit' => $amount,
                'credit' => '0.00',
                'line_description' => $description . ' reversal',
            ],
            [
                'nominal_account_id' => $liabilityNominalId,
                'party_id' => $directorId,
                'debit' => '0.00',
                'credit' => $amount,
                'line_description' => $description . ' reversal',
            ],
        ];
    }

    private function postBlockedReason(
        array $statement,
        bool $confirmationCurrent,
        int $unattributedCount,
        float $unresolvedPosted,
        float $pending
    ): string {
        if (abs($pending) < 0.005) {
            return 'The Director Loan control reclassification is already current.';
        }
        if ($unattributedCount > 0) {
            return 'Attribute every Director Loan entry before applying the control reclassification.';
        }
        if ($unresolvedPosted >= 0.005) {
            return 'A legacy unattributed offset journal must be resolved through the normal unlock, review and re-lock workflow.';
        }
        if (!$confirmationCurrent) {
            return 'Save the current factual Director Loan Year End Review before applying the control reclassification.';
        }
        if (empty($statement['has_activity'])) {
            return 'There is no Director Loan activity requiring reclassification.';
        }
        return '';
    }
}
