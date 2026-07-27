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
    public const OFFSET_JOURNAL_SOURCE_TYPE = 'director_loan_offset';
    public const OFFSET_JOURNAL_DESCRIPTION = 'Director loan control reclassification';
    public const LEGACY_REPAIR_JOURNAL_KEY_PREFIX = 'legacy-unattributed-reversal:';
    public const LEGACY_REPAIR_JOURNAL_DESCRIPTION = 'Legacy Director Loan offset reversal';
    public const UNLOCK_REVERSAL_JOURNAL_KEY_PREFIX = 'unlock-party-reversal:';
    public const UNLOCK_REVERSAL_JOURNAL_DESCRIPTION = 'Director loan offset reversal on Year End unlock';
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
        if ((float)($context['unsupported_posted_set_off_amount'] ?? 0) > 0.004) {
            $errors[] = 'The posted Director Loan set-off is unsupported because both legal set-off confirmations and their evidence are not current.';
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
        $acknowledgement = $ackService->fetch(
            $companyId,
            $accountingPeriodId,
            self::YEAR_END_ACKNOWLEDGEMENT_CODE
        );
        $ct600a = (new Ct600aService())->fetchReviewForAccountingPeriod($companyId, $accountingPeriodId);
        $ct600aReview = (array)($ct600a['review'] ?? []);
        $sectionAnswers = $this->sectionApprovalAnswers($acknowledgement);
        if ($sectionAnswers !== []) {
            $allNo = true;
            $answers = [];
            foreach ((array)($ct600a['questions'] ?? []) as $key => $_prompt) {
                $answer = (string)($sectionAnswers['ct600a.' . (string)$key] ?? '');
                $answers[(string)$key] = $answer;
                $allNo = $allNo && $answer === 'no';
            }
            $ct600aReview = [
                'stored' => true,
                'current' => $allNo,
                'complete' => $allNo,
                'answers' => $answers,
                'basis_hash' => (string)($acknowledgement['basis_hash'] ?? ''),
            ];
            $ct600a['review'] = $ct600aReview;
        }
        $ct600aReviewEvidenceCurrent = !empty($ct600aReview['current']);
        $ct600aReviewComplete = !empty($ct600aReview['complete']);
        $ct600aReviewCurrent = $ct600aReviewEvidenceCurrent && $ct600aReviewComplete;
        $s455 = (new S455ReviewService())->fetchForAccountingPeriod($companyId, $accountingPeriodId);

        $legacyOffset = $this->legacyUnattributedOffset(
            $companyId,
            (string)($statement['accounting_period']['period_end'] ?? ''),
            (int)($statement['asset_nominal']['id'] ?? 0),
            (int)($statement['liability_nominal']['id'] ?? 0)
        );
        $unresolvedPosted = 0.0;
        $legacyNetAmount = 0.0;
        $proposedLines = [];
        $requiresSetOffIncrease = false;
        $requiresSetOffReversal = false;
        $unsupportedPostedSetOff = 0.0;
        $pendingMagnitude = 0.0;
        $pendingPartyCount = 0;
        $unsupportedReversalPartyCount = 0;
        $missingTerms = [];
        $partyFacts = [];
        $warnings = [];
        foreach ((array)$statement['per_director'] as $position) {
            $directorId = (int)($position['party_id'] ?? $position['director_id'] ?? 0);
            $posted = round((float)($position['posted_reclassification'] ?? 0), 2);
            if ($directorId <= 0) {
                $unresolvedPosted = round($unresolvedPosted + abs($posted), 2);
                $legacyNetAmount = round($legacyNetAmount + $posted, 2);
                continue;
            }

            $pending = round((float)($position['pending_reclassification'] ?? 0), 2);
            $setOffPermitted = !empty($position['set_off_permitted']);
            $hasPeriodMovement = !empty($position['has_period_movement'])
                || (int)($position['period_movement_count'] ?? 0) > 0;
            $hasClosingPosition = !empty($position['has_closing_position'])
                || abs((float)($position['gross_asset'] ?? 0)) >= 0.005
                || abs((float)($position['gross_liability'] ?? 0)) >= 0.005;
            $termsSaved = !empty($position['terms_saved'])
                || !empty(($position['party_terms'] ?? [])['explicit']);
            $partyName = trim((string)($position['party_name'] ?? $position['director_name'] ?? ''))
                ?: 'Participator #' . $directorId;
            if (($hasPeriodMovement || $hasClosingPosition) && !$termsSaved) {
                $missingTerms[$directorId] = $partyName;
            }

            $unsupported = !$setOffPermitted ? round(max(0.0, $posted), 2) : 0.0;
            $unsupportedPostedSetOff = round($unsupportedPostedSetOff + $unsupported, 2);
            if (abs($pending) >= 0.005) {
                $pendingMagnitude = round($pendingMagnitude + abs($pending), 2);
                $pendingPartyCount++;
                if ($pending > 0.004) {
                    $requiresSetOffIncrease = true;
                } else {
                    $requiresSetOffReversal = true;
                    if ($unsupported >= 0.005) {
                        $unsupportedReversalPartyCount++;
                    }
                }
                $proposedLines = array_merge(
                    $proposedLines,
                    $this->reclassificationLines(
                        (int)$statement['asset_nominal']['id'],
                        (int)($position['liability_nominal_account_id']
                            ?? $statement['liability_nominal']['id']),
                        $directorId,
                        $pending,
                        $partyName
                    )
                );
            }

            if (!$setOffPermitted
                && min(
                    max(0.0, (float)($position['gross_asset'] ?? 0)),
                    max(0.0, (float)($position['gross_liability'] ?? 0))
                ) >= 0.005) {
                $warnings[] = $partyName . ': Same-party Director Loan asset and liability balances remain gross because the legal right of set-off and net-or-simultaneous settlement intention have not both been evidenced.';
            }

            $terms = (array)($position['party_terms'] ?? []);
            $partyFacts[] = array_replace($position, [
                'party_id' => $directorId,
                'party_name' => $partyName,
                'director_id' => $directorId,
                'director_name' => (string)($position['director_name'] ?? $partyName),
                'linked_director_id' => (int)($position['linked_director_id'] ?? 0),
                'is_director' => !empty($position['is_director'])
                    || (int)($position['linked_director_id'] ?? 0) > 0,
                'has_period_movement' => $hasPeriodMovement,
                'has_closing_position' => $hasClosingPosition,
                'terms_saved' => $termsSaved,
                'terms_source' => (string)($position['terms_source'] ?? $terms['terms_source'] ?? 'default'),
                'terms_revision' => max(0, (int)($position['terms_revision'] ?? $terms['revision'] ?? 0)),
                'terms_snapshot' => !empty($position['terms_snapshot'])
                    || (string)($position['terms_source'] ?? $terms['terms_source'] ?? '') === 'locked_snapshot',
                'terms' => $this->termsFact($terms),
                'maturity_classification' => (string)($position['maturity_classification']
                    ?? $position['classification']
                    ?? DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR),
                'set_off_permitted' => $setOffPermitted,
                'set_off_conclusion' => (string)($position['set_off_conclusion']
                    ?? ($setOffPermitted ? 'permitted' : ($termsSaved ? 'balances_remain_gross' : 'terms_required'))),
                'desired_reclassification' => round((float)($position['desired_reclassification'] ?? 0), 2),
                'posted_reclassification' => $posted,
                'pending_reclassification' => $pending,
                'journal_adjustment_state' => $pending > 0.004
                    ? 'increase_required'
                    : ($pending < -0.004 ? 'reversal_required' : 'current'),
                'unsupported_posted_set_off_amount' => $unsupported,
            ]);
        }

        $unattributedCount = (int)($statement['unattributed_count'] ?? 0)
            + (int)($statement['invalid_director_count'] ?? 0);
        if ($unattributedCount > 0) {
            $warnings[] = $unattributedCount . ' Participator Loan entr'
                . ($unattributedCount === 1 ? 'y is' : 'ies are')
                . ' not attributed to a valid same-company party.';
        }
        if ($unresolvedPosted >= 0.005) {
            $warnings[] = 'A legacy Director Loan offset journal cannot be attributed deterministically and remains an unresolved historical accounting record.';
        }

        $exposure = round((float)($statement['potential_s455_exposure'] ?? 0), 2);
        $s455Ready = $this->s455Ready($s455, $exposure);
        $ct600aReady = $exposure < 0.005
            || (!empty($ct600a['available']) && $ct600aReviewCurrent);
        $aggregateTaxStatus = $missingTerms !== []
            ? 'terms_required'
            : ($exposure >= 0.005
                ? ($s455Ready && $ct600aReady ? 'reviewed_exposure' : 'review_required')
                : 'no_exposure');
        $aggregateTaxLabel = match ($aggregateTaxStatus) {
            'terms_required' => 'Terms required',
            'review_required' => 'Review required',
            'reviewed_exposure' => 'Reviewed — exposure recorded',
            default => 'No exposure flagged',
        };
        $partyFlags = [];
        foreach ($partyFacts as &$partyFact) {
            $partyExposure = round((float)($partyFact['potential_s455_exposure'] ?? 0), 2);
            $partyStatus = empty($partyFact['terms_saved'])
                && (!empty($partyFact['has_period_movement']) || !empty($partyFact['has_closing_position']))
                ? 'terms_required'
                : ($partyExposure >= 0.005
                    ? ($s455Ready && $ct600aReady ? 'reviewed_exposure' : 'review_required')
                    : 'no_exposure');
            $partyFact['tax_status'] = $partyStatus;
            $partyFact['tax_status_code'] = $partyStatus;
            $partyFact['tax_status_label'] = match ($partyStatus) {
                'terms_required' => 'Terms required',
                'review_required' => 'Review required',
                'reviewed_exposure' => 'Reviewed — exposure recorded',
                default => 'No exposure flagged',
            };
            $partyFlags[] = [
                'party_id' => (int)$partyFact['party_id'],
                'party_name' => (string)$partyFact['party_name'],
                'director_id' => (int)$partyFact['director_id'],
                'director_name' => (string)$partyFact['director_name'],
                'linked_director_id' => (int)($partyFact['linked_director_id'] ?? 0),
                'terms_saved' => !empty($partyFact['terms_saved']),
                'potential_s455_exposure' => $partyExposure,
                'tax_status' => $partyStatus,
                'tax_status_code' => $partyStatus,
                'status' => $partyStatus,
                'status_label' => (string)$partyFact['tax_status_label'],
                'review_required' => in_array($partyStatus, ['terms_required', 'review_required'], true),
            ];
        }
        unset($partyFact);
        $taxReview = array_replace($taxReview, [
            'success' => true,
            'available' => true,
            'status' => $aggregateTaxStatus,
            'status_code' => $aggregateTaxStatus,
            'status_label' => $aggregateTaxLabel,
            'review_required' => in_array($aggregateTaxStatus, ['terms_required', 'review_required'], true),
            'director_owes_company' => $exposure >= 0.005,
            'exposure_amount' => $exposure,
            'potential_s455_exposure' => $exposure,
            'missing_terms_count' => count($missingTerms),
            'unsupported_posted_set_off_amount' => $unsupportedPostedSetOff,
            's455_ready' => $s455Ready,
            'ct600a_ready' => $ct600aReady,
            'party_flags' => $partyFlags,
            'director_flags' => $partyFlags,
        ]);

        $basis = $this->confirmationBasis(
            $statement,
            $taxReview,
            $ct600aReview,
            $partyFacts,
            $s455Ready,
            $ct600aReady
        );
        $evaluation = (string)($acknowledgement['basis_version'] ?? '') === YearEndSectionApprovalService::CONTRACT_VERSION
            ? $this->evaluateSectionApproval($ackService, $acknowledgement, $basis)
            : $ackService->evaluate($acknowledgement, $basis, false);
        $confirmationCurrent = !empty($evaluation['current']);
        $setOffPermitted = !empty(array_filter(
            $partyFacts,
            static fn(array $fact): bool => !empty($fact['set_off_permitted'])
        ));
        $isLocked = ($this->lockService ?? new YearEndLockService())
            ->isLocked($companyId, $accountingPeriodId);
        $onlyUnsupportedReversals = $pendingPartyCount > 0
            && $pendingPartyCount === $unsupportedReversalPartyCount;
        $canPost = $pendingMagnitude >= 0.005
            && ($confirmationCurrent || $onlyUnsupportedReversals)
            && $unattributedCount === 0
            && $unresolvedPosted < 0.005
            && !$isLocked
            && $proposedLines !== [];

        return [
            'available' => true,
            'errors' => [],
            'warnings' => array_values(array_unique($warnings)),
            'statement' => $statement,
            'tax_review' => $taxReview,
            'tax_status' => $aggregateTaxStatus,
            'tax_status_code' => $aggregateTaxStatus,
            'tax_status_label' => $aggregateTaxLabel,
            's455' => $s455,
            's455_ready' => $s455Ready,
            'ct600a' => $ct600a,
            'ct600a_review' => $ct600aReview,
            'ct600a_review_evidence_current' => $ct600aReviewEvidenceCurrent,
            'ct600a_review_complete' => $ct600aReviewComplete,
            'ct600a_review_current' => $ct600aReviewCurrent,
            'ct600a_ready' => $ct600aReady,
            'accounting_period' => (array)$statement['accounting_period'],
            'asset_nominal' => (array)$statement['asset_nominal'],
            'liability_nominal' => (array)$statement['liability_nominal'],
            'party_facts' => $partyFacts,
            'party_flags' => $partyFlags,
            'per_director' => (array)$statement['per_director'],
            'unattributed_entries' => (array)$statement['unattributed_entries'],
            'invalid_director_entries' => (array)$statement['invalid_director_entries'],
            'unattributed_count' => $unattributedCount,
            'missing_terms_count' => count($missingTerms),
            'missing_terms_parties' => array_map(
                static fn(int $partyId, string $partyName): array => [
                    'party_id' => $partyId,
                    'party_name' => $partyName,
                ],
                array_map('intval', array_keys($missingTerms)),
                array_values($missingTerms)
            ),
            'has_activity' => !empty($statement['has_activity']),
            'asset_receivable' => (float)$statement['asset_receivable'],
            'liability_payable' => (float)$statement['liability_payable'],
            'net_position' => (float)$statement['net_position'],
            'net_position_label' => (string)$statement['net_position_label'],
            'potential_s455_exposure' => $exposure,
            'required_reclassification_amount' => (float)$statement['desired_reclassification'],
            'desired_reclassification_amount' => (float)$statement['desired_reclassification'],
            'posted_reclassification_amount' => (float)$statement['posted_reclassification'],
            'pending_adjustment_amount' => $pendingMagnitude,
            'set_off_permitted' => $setOffPermitted,
            'set_off_evidence' => (string)($statement['set_off_evidence'] ?? ''),
            'requires_set_off_increase' => $requiresSetOffIncrease,
            'requires_set_off_reversal' => $requiresSetOffReversal,
            'unsupported_posted_set_off_amount' => $unsupportedPostedSetOff,
            'reporting_presentation' => [],
            'is_locked' => $isLocked,
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
                && $unresolvedPosted < 0.005
                && $missingTerms === []
                && $s455Ready
                && $ct600aReady,
            'can_post' => $canPost,
            'post_blocked_reason' => $this->postBlockedReason(
                $statement,
                $confirmationCurrent,
                $unattributedCount,
                $unresolvedPosted,
                $pendingMagnitude,
                $requiresSetOffIncrease,
                $requiresSetOffReversal,
                $onlyUnsupportedReversals,
                count($missingTerms),
                $isLocked
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
            if ((int)($context['missing_terms_count'] ?? 0) > 0) {
                $names = array_values(array_filter(array_map(
                    static fn(array $party): string => trim((string)($party['party_name'] ?? '')),
                    (array)($context['missing_terms_parties'] ?? [])
                )));
                $errors = ['Save Participator Loan terms for every relevant party'
                    . ($names !== [] ? ': ' . implode(', ', $names) : '')
                    . '.'];
            } elseif (empty($context['s455_ready'])) {
                $errors = ['Complete the s455 and close-company evidence review before confirming the Director Loan Year End Review.'];
            } elseif (empty($context['ct600a_review_evidence_current'])) {
                $errors = ['Review the Section 464A and 464C declaration again before re-approving the Director Loan Year End Confirmation.'];
            } elseif (empty($context['ct600a_review_complete'])) {
                $errors = ['Complete the Section 464A and 464C declaration with No answers, or resolve every Yes answer through the relevant records before confirming the Director Loan Year End Review.'];
            } else {
                $errors = abs((float)($context['legacy_unresolved_reclassification_amount'] ?? 0)) >= 0.005
                    ? ['Repair the legacy Director Loan offset journal before confirming the facts.']
                    : ['Attribute every Director Loan entry to a valid same-company director before confirming the facts.'];
            }
            return ['success' => false, 'errors' => $errors];
        }

        $approval = new YearEndSectionApprovalService();
        $review = $approval->fetchReview(
            $companyId,
            $accountingPeriodId,
            self::YEAR_END_ACKNOWLEDGEMENT_CODE
        );
        $answers = [];
        foreach ((array)($review['questions'] ?? []) as $question) {
            if (!is_array($question) || trim((string)($question['id'] ?? '')) === '') {
                continue;
            }
            $answers[(string)$question['id']] = (string)($question['required_value'] ?? '');
        }

        return $approval->approve(
            $companyId,
            $accountingPeriodId,
            self::YEAR_END_ACKNOWLEDGEMENT_CODE,
            $answers,
            $changedBy
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
        $journalNotes = !empty($context['requires_set_off_reversal'])
            ? 'Adjusts party-specific set-off journals to the current independently resolved terms and balances.'
            : 'Applies evidenced same-party control-account set-off. Balances belonging to different parties are never offset.';
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
            $journalNotes,
            $changedBy,
            self::OFFSET_JOURNAL_SOURCE_TYPE
        );
        if (!empty($result['success'])) {
            $result['context'] = $this->fetchContext($companyId, $accountingPeriodId);
        }
        return $result;
    }

    /**
     * Append exact party-specific reversals for the currently effective offset
     * journals. The caller must already have reopened the period and must own
     * the surrounding unlock transaction.
     */
    public function reverseOffsetForUnlock(
        int $companyId,
        int $accountingPeriodId,
        string $changedBy = 'web_app'
    ): array {
        if (!\InterfaceDB::inTransaction()) {
            return [
                'success' => false,
                'errors' => ['Director Loan unlock reversals must run inside the Year End unlock transaction.'],
            ];
        }
        ($this->lockService ?? new YearEndLockService())
            ->assertUnlocked($companyId, $accountingPeriodId, 'reverse the Director Loan offsets during unlock');

        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return [
                'success' => false,
                'errors' => (array)($context['errors'] ?? ['The Director Loan evidence context is unavailable.']),
            ];
        }

        $effectivePartyFacts = array_values(array_filter(
            (array)($context['party_facts'] ?? []),
            static fn(array $fact): bool =>
                (int)($fact['party_id'] ?? 0) > 0
                && abs((float)($fact['posted_reclassification'] ?? 0)) >= 0.005
        ));
        if ($effectivePartyFacts === []) {
            return ['success' => true, 'already_current' => true, 'reversed_party_count' => 0];
        }
        try {
            $frozenLiabilityNominalId = (new ParticipatorLoanPartyTermsService())
                ->periodLiabilityNominalAccountId($companyId, $accountingPeriodId);
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
        if ((int)$frozenLiabilityNominalId <= 0) {
            return [
                'success' => false,
                'errors' => [
                    'The frozen Participator Loan liability nominal mapping is missing, so the effective party-specific offset cannot be reversed safely.',
                ],
            ];
        }

        $assetNominalId = (int)(($context['asset_nominal'] ?? [])['id'] ?? 0);
        $periodEnd = trim((string)(($context['accounting_period'] ?? [])['period_end'] ?? ''));
        $lines = [];
        $reversedFacts = [];
        foreach ($effectivePartyFacts as $fact) {
            $partyId = (int)($fact['party_id'] ?? 0);
            $posted = round((float)($fact['posted_reclassification'] ?? 0), 2);
            $liabilityNominalId = (int)$frozenLiabilityNominalId;
            if ($assetNominalId <= 0 || $liabilityNominalId <= 0 || $periodEnd === '') {
                return [
                    'success' => false,
                    'errors' => ['A party-specific Director Loan offset cannot be reversed because its frozen control-account mapping is unavailable.'],
                ];
            }
            $partyName = trim((string)($fact['party_name'] ?? '')) ?: 'Participator #' . $partyId;
            $lines = array_merge(
                $lines,
                $this->reclassificationLines(
                    $assetNominalId,
                    $liabilityNominalId,
                    $partyId,
                    -$posted,
                    $partyName
                )
            );
            $reversedFacts[] = [
                'party_id' => $partyId,
                'party_name' => $partyName,
                'liability_nominal_account_id' => $liabilityNominalId,
                'posted_reclassification' => number_format($posted, 2, '.', ''),
                'reversal_amount' => number_format(-$posted, 2, '.', ''),
            ];
        }
        if ($lines === []) {
            return ['success' => true, 'already_current' => true, 'reversed_party_count' => 0];
        }

        $keyFacts = $reversedFacts;
        usort($keyFacts, static fn(array $left, array $right): int =>
            (int)$left['party_id'] <=> (int)$right['party_id']
        );
        $journalKey = self::UNLOCK_REVERSAL_JOURNAL_KEY_PREFIX . substr(
            hash('sha256', json_encode($keyFacts, JSON_UNESCAPED_SLASHES)),
            0,
            24
        );
        $notes = 'Appends full reversals of the effective party-specific Director Loan set-off when reopening the Year End. Source journals are preserved.';
        $result = ($this->journalService ?? new ManualJournalService())->saveTaggedJournal(
            $companyId,
            $accountingPeriodId,
            self::OFFSET_JOURNAL_TAG,
            $journalKey,
            $periodEnd,
            self::UNLOCK_REVERSAL_JOURNAL_DESCRIPTION,
            $lines,
            'system_generated',
            null,
            null,
            $notes,
            $changedBy,
            self::OFFSET_JOURNAL_SOURCE_TYPE
        );
        if (empty($result['success'])) {
            return $result;
        }

        ($this->lockService ?? new YearEndLockService())->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            'director_loan_party_offsets_reversed',
            $changedBy,
            ['party_facts' => $reversedFacts],
            [
                'reversal_journal_id' => (int)(($result['journal'] ?? [])['id'] ?? 0),
                'journal_key' => $journalKey,
                'reversed_party_count' => count($reversedFacts),
            ],
            $notes
        );
        return $result + [
            'reversed_party_count' => count($reversedFacts),
            'reversed_party_facts' => $reversedFacts,
        ];
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
        if (!$this->hasTaggedOffsetLines($companyId, $accountingPeriodId, false)) {
            return ['success' => true, 'already_current' => true];
        }

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
            $changedBy,
            self::OFFSET_JOURNAL_SOURCE_TYPE
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

    private function hasTaggedOffsetLines(
        int $companyId,
        int $accountingPeriodId,
        bool $attributed
    ): bool {
        if (!\InterfaceDB::tableExists('journal_entry_metadata')
            || !\InterfaceDB::tableExists('journals')
            || !\InterfaceDB::tableExists('journal_lines')) {
            return false;
        }
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM journal_entry_metadata jem
             INNER JOIN journals j ON j.id = jem.journal_id
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE jem.company_id = :company_id
               AND jem.accounting_period_id = :accounting_period_id
               AND jem.journal_tag = :journal_tag
               AND j.is_posted = 1
               AND jl.party_id IS ' . ($attributed ? 'NOT NULL' : 'NULL'),
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'journal_tag' => self::OFFSET_JOURNAL_TAG,
            ]
        ) > 0;
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

    /** @return array<string,mixed> */
    private function termsFact(array $terms): array
    {
        return [
            'interest_rate_percent' => round((float)($terms['interest_rate_percent'] ?? 0), 4),
            'security_type' => (string)($terms['security_type'] ?? 'unsecured'),
            'repayable_on_demand' => !empty($terms['repayable_on_demand']),
            'repayment_timing' => (string)($terms['repayment_timing'] ?? 'within_12_months'),
            'deferment_right_confirmed' => !empty($terms['deferment_right_confirmed']),
            'set_off_right_confirmed' => !empty($terms['set_off_right_confirmed']),
            'settlement_intention' => (string)($terms['settlement_intention'] ?? 'independently'),
        ];
    }

    private function s455Ready(array $s455, float $exposure): bool
    {
        if ($exposure < 0.005) {
            return true;
        }
        $periods = (array)($s455['periods'] ?? []);
        if (empty($s455['available']) || $periods === []) {
            return false;
        }
        foreach ($periods as $period) {
            if (empty($period['available'])
                || empty($period['close_status_calculated'])
                || (string)($period['close_company_status'] ?? 'unconfirmed') === 'unconfirmed'
                || (array)($period['errors'] ?? []) !== []
                || (array)($period['unattributed_movements'] ?? []) !== []
                || (array)($period['unsupported_movements'] ?? []) !== []) {
                return false;
            }
        }
        return true;
    }

    private function confirmationBasis(
        array $statement,
        array $taxReview,
        array $ct600aReview,
        array $partyFacts,
        bool $s455Ready,
        bool $ct600aReady
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

        $canonicalPartyFacts = [];
        $legacyUnresolvedNetAmount = 0.0;
        foreach ((array)$statement['per_director'] as $position) {
            if ((int)($position['party_id'] ?? $position['director_id'] ?? 0) <= 0) {
                $legacyUnresolvedNetAmount = round(
                    $legacyUnresolvedNetAmount + (float)($position['posted_reclassification'] ?? 0),
                    2
                );
            }
        }

        foreach ($partyFacts as $fact) {
            $desired = round((float)($fact['desired_reclassification'] ?? 0), 2);
            $unsupported = round((float)($fact['unsupported_posted_set_off_amount'] ?? 0), 2);
            $canonicalPartyFacts[] = [
                'party_id' => (int)($fact['party_id'] ?? 0),
                'party_name' => (string)($fact['party_name'] ?? ''),
                'linked_director_id' => (int)($fact['linked_director_id'] ?? 0),
                'is_director' => !empty($fact['is_director']),
                'party_identity_key' => implode('|', [
                    (int)($fact['party_id'] ?? 0),
                    (string)($fact['party_name'] ?? ''),
                    (int)($fact['linked_director_id'] ?? 0),
                ]),
                'has_period_movement' => !empty($fact['has_period_movement']),
                'has_closing_position' => !empty($fact['has_closing_position']),
                'terms_saved' => !empty($fact['terms_saved']),
                'terms_revision' => max(0, (int)($fact['terms_revision'] ?? 0)),
                'resolved_terms' => (array)($fact['terms'] ?? []),
                'liability_nominal_account_id' => (int)($fact['liability_nominal_account_id'] ?? 0),
                // Live terms and the immutable lock copy are the same signed
                // evidence. Record the snapshot payload, not its transient
                // storage location, so creating that copy cannot stale the
                // approval which authorised the lock.
                'terms_snapshot_basis' => [
                    'revision' => max(0, (int)($fact['terms_revision'] ?? 0)),
                    'resolved_terms' => (array)($fact['terms'] ?? []),
                    'liability_nominal_account_id' => (int)($fact['liability_nominal_account_id'] ?? 0),
                ],
                'maturity_classification' => (string)($fact['maturity_classification'] ?? ''),
                'set_off_permitted' => !empty($fact['set_off_permitted']),
                'set_off_conclusion' => (string)($fact['set_off_conclusion'] ?? ''),
                'gross_asset_amount' => number_format((float)($fact['gross_asset'] ?? 0), 2, '.', ''),
                'gross_liability_amount' => number_format((float)($fact['gross_liability'] ?? 0), 2, '.', ''),
                'reportable_asset_amount' => number_format((float)($fact['reportable_asset'] ?? 0), 2, '.', ''),
                'reportable_liability_amount' => number_format((float)($fact['reportable_liability'] ?? 0), 2, '.', ''),
                'desired_reclassification_amount' => number_format($desired, 2, '.', ''),
                'net_closing_balance' => number_format((float)($fact['net_closing_position'] ?? 0), 2, '.', ''),
                'potential_s455_exposure_amount' => number_format((float)($fact['potential_s455_exposure'] ?? 0), 2, '.', ''),
                'tax_review_state' => (string)($fact['tax_status_code'] ?? $fact['tax_status'] ?? ''),
                // This records the required accounting conclusion without
                // making the approval stale when its own journal is posted.
                'journal_adjustment_state' => $unsupported >= 0.005
                    ? 'set_off_reversal_required'
                    : ($desired >= 0.005 ? 'set_off_required' : 'no_set_off_required'),
            ];
        }
        usort($canonicalPartyFacts, static fn(array $left, array $right): int =>
            (int)$left['party_id'] <=> (int)$right['party_id']
        );

        $facts = [
            'accounting_period_id' => (int)$statement['accounting_period']['id'],
            'entry_count' => count($entryFacts),
            'entry_facts' => $entryFacts,
            'party_facts' => $canonicalPartyFacts,
            // Retained as an alias for older acknowledgement consumers.
            'director_facts' => $canonicalPartyFacts,
            'unattributed_count' => (int)$statement['unattributed_count'],
            'invalid_director_count' => (int)$statement['invalid_director_count'],
            'missing_terms_count' => (int)($taxReview['missing_terms_count'] ?? 0),
            'legacy_unresolved_reclassification_net_amount' => number_format($legacyUnresolvedNetAmount, 2, '.', ''),
            'potential_s455_exposure_amount' => number_format((float)($taxReview['exposure_amount'] ?? 0), 2, '.', ''),
            'tax_review_state' => (string)($taxReview['status_code'] ?? $taxReview['status'] ?? ''),
            's455_ready' => $s455Ready,
            'ct600a_review_current' => !empty($ct600aReview['current']) && !empty($ct600aReview['complete']),
            'ct600a_ready' => $ct600aReady,
            'ct600a_review_basis_hash' => (string)($ct600aReview['basis_hash'] ?? ''),
            'desired_reclassification_amount' => number_format((float)$statement['desired_reclassification'], 2, '.', ''),
            'unsupported_posted_set_off_amount' => number_format(
                (float)($taxReview['unsupported_posted_set_off_amount'] ?? 0),
                2,
                '.',
                ''
            ),
        ];
        // These facts are already deliberately curated. Avoid the generic
        // accounting-fact compactor, which would discard legal-term booleans
        // and party names that are essential to this approval.
        return [
            'check_code' => self::YEAR_END_ACKNOWLEDGEMENT_CODE,
            'facts' => $facts,
        ];
    }

    /** @return array<string, mixed> */
    private function sectionApprovalAnswers(?array $acknowledgement): array
    {
        if (!is_array($acknowledgement)
            || (string)($acknowledgement['basis_version'] ?? '') !== YearEndSectionApprovalService::CONTRACT_VERSION) {
            return [];
        }
        $basis = json_decode((string)($acknowledgement['basis_json'] ?? ''), true);
        return is_array($basis) && is_array($basis['answers'] ?? null) ? (array)$basis['answers'] : [];
    }

    /** @return array{state:string,current:bool,acknowledgement:?array} */
    private function evaluateSectionApproval(YearEndAcknowledgementService $service, ?array $acknowledgement, array $legacyBasis): array
    {
        $stored = is_array($acknowledgement) ? json_decode((string)($acknowledgement['basis_json'] ?? ''), true) : null;
        if (!is_array($stored) || !is_array($stored['facts'] ?? null)) {
            return ['state' => 'stale', 'current' => false, 'acknowledgement' => $acknowledgement];
        }

        $storedFacts = (array)$stored['facts'];
        $currentFacts = (array)($legacyBasis['facts'] ?? []);
        // These values are evidenced by the answers inside this same signed
        // approval. Exclude them symmetrically to avoid a circular stale state.
        foreach (['ct600a_review_current', 'ct600a_review_basis_hash', 'ct600a_ready'] as $key) {
            unset($storedFacts[$key], $currentFacts[$key]);
        }
        $current = hash_equals(
            $service->hashBasis(['facts' => $storedFacts]),
            $service->hashBasis(['facts' => $currentFacts])
        );
        return ['state' => $current ? 'current' : 'stale', 'current' => $current, 'acknowledgement' => $acknowledgement];
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
        float $pending,
        bool $requiresSetOffIncrease,
        bool $requiresSetOffReversal,
        bool $onlyUnsupportedReversals,
        int $missingTermsCount,
        bool $isLocked
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
        if ($isLocked) {
            return $requiresSetOffReversal
                ? 'Unlock the accounting period before reversing the unsupported Director Loan set-off, then review and re-lock the corrected year end.'
                : 'Unlock the accounting period before changing the Director Loan control reclassification.';
        }
        if ($onlyUnsupportedReversals) {
            return '';
        }
        if ($missingTermsCount > 0) {
            return 'Save Participator Loan terms for every party with a period movement or closing balance before applying the reclassification.';
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
