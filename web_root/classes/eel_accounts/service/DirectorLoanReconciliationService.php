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
    public const OFFSET_JOURNAL_DESCRIPTION = 'Director loan asset/liability offset';
    public const SET_OFF_ACKNOWLEDGEMENT_CODE = 'director_loan_set_off_criteria';

    public function __construct(
        private readonly ?\eel_accounts\Service\ManualJournalService $journalService = null,
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\DirectorLoanService $directorLoanService = null,
        private readonly ?\eel_accounts\Service\YearEndAcknowledgementService $acknowledgementService = null,
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
    ) {
    }

    public function fetchYearEndConfirmationContext(int $companyId, int $accountingPeriodId): array
    {
        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (!empty($context['available'])) {
            $service = $this->directorLoanService ?? new \eel_accounts\Service\DirectorLoanService();
            $taxReview = $service->fetchTaxReviewSummary($companyId, $accountingPeriodId);
            if (!empty($taxReview['available'])) {
                $acknowledgements = $this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService();
                $acknowledgement = $acknowledgements->fetch($companyId, $accountingPeriodId, 'director_loan_tax_review');
                $evaluation = $acknowledgements->evaluate(
                    $acknowledgement,
                    $acknowledgements->buildBasis('director_loan_tax_review', $taxReview),
                    ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId)
                );
                $taxReview['acknowledgement'] = $acknowledgement;
                $taxReview['acknowledgement_state'] = (string)($evaluation['state'] ?? 'absent');
                $taxReview['acknowledgement_current'] = !empty($evaluation['current']);
            }
            $context['tax_review'] = $taxReview;
        }

        return $context;
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        $assetNominal = $this->directorLoanNominal($companyId, 'director_loan_asset_nominal_id', 'director_loan_asset', '1200', 'asset');
        $liabilityNominal = $this->directorLoanNominal($companyId, 'director_loan_liability_nominal_id', 'director_loan_liability', '2100', 'liability');
        $errors = [];
        if ($assetNominal === null) {
            $errors[] = 'Director Loan Asset nominal 1200 is not available.';
        }
        if ($liabilityNominal === null) {
            $errors[] = 'Director Loan Liability nominal 2100 is not available.';
        }

        $existingOffset = ($this->journalService ?? new \eel_accounts\Service\ManualJournalService())
            ->fetchJournalByTag($companyId, $accountingPeriodId, self::OFFSET_JOURNAL_TAG, self::OFFSET_JOURNAL_KEY);

        if ($errors !== []) {
            return [
                'available' => false,
                'errors' => $errors,
                'accounting_period' => $accountingPeriod,
                'asset_nominal' => $assetNominal,
                'liability_nominal' => $liabilityNominal,
                'existing_offset_journal' => $existingOffset,
            ];
        }

        $assetNominal = (array)$assetNominal;
        $liabilityNominal = (array)$liabilityNominal;
        $balances = $this->fetchGrossBalances(
            $companyId,
            (string)($accountingPeriod['period_end'] ?? ''),
            (int)$assetNominal['id'],
            (int)$liabilityNominal['id']
        );
        $postedOffsets = $this->postedOffsetSummary(
            $this->fetchPostedOffsetJournals(
                $companyId,
                (string)($accountingPeriod['period_end'] ?? '')
            ),
            (int)$assetNominal['id'],
            (int)$liabilityNominal['id']
        );

        $summary = $this->buildSummary(
            $assetNominal,
            $liabilityNominal,
            $balances,
            is_array($existingOffset) ? $existingOffset : null,
            $postedOffsets
        );
        $summary['available'] = true;
        $summary['errors'] = [];
        $summary['accounting_period'] = $accountingPeriod;
        $service = $this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService();
        $acknowledgement = $service->fetch($companyId, $accountingPeriodId, 'director_loan_closing_balance');
        $directorLoanSummary = $metrics->directorLoanSummary($companyId, $accountingPeriodId);
        $basis = $service->buildBasis('director_loan_closing_balance', [
            'closing_balance' => number_format((float)($directorLoanSummary['closing_balance'] ?? 0), 2, '.', ''),
        ]);
        $evaluation = $service->evaluate(
            $acknowledgement,
            $basis,
            ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId)
        );
        $summary['closing_balance_acknowledged'] = !empty($evaluation['current']);
        $summary['closing_balance_acknowledgement_state'] = (string)($evaluation['state'] ?? 'absent');
        $summary['closing_balance_acknowledged_at'] = (string)($acknowledgement['acknowledged_at'] ?? '');
        $summary['closing_balance_acknowledged_by'] = (string)($acknowledgement['acknowledged_by'] ?? '');
        $summary['director_loan_closing_approval_note'] = (string)($acknowledgement['note'] ?? '');
        $summary['offset_candidate_available'] =
            (float)($summary['required_offset_amount'] ?? 0) > 0.004
            && (array)($summary['warnings'] ?? []) === [];

        $setOffAcknowledgement = $service->fetch(
            $companyId,
            $accountingPeriodId,
            self::SET_OFF_ACKNOWLEDGEMENT_CODE
        );
        $setOffEvaluation = $service->evaluate(
            $setOffAcknowledgement,
            $this->setOffEvidenceBasis($service, $summary),
            ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId)
        );
        $setOffNote = trim((string)($setOffAcknowledgement['note'] ?? ''));
        $setOffEvidenceCurrent = !empty($setOffEvaluation['current']) && $setOffNote !== '';
        $summary['set_off_evidence_current'] = $setOffEvidenceCurrent;
        $summary['set_off_evidence_state'] = (string)($setOffEvaluation['state'] ?? 'absent');
        $summary['set_off_evidence_acknowledgement'] = $setOffAcknowledgement;
        $summary['set_off_evidence_note'] = $setOffNote;
        $summary['set_off_evidence_acknowledged_at'] = (string)($setOffAcknowledgement['acknowledged_at'] ?? '');
        $summary['set_off_evidence_acknowledged_by'] = (string)($setOffAcknowledgement['acknowledged_by'] ?? '');
        $summary['legally_enforceable_right_confirmed'] = $setOffEvidenceCurrent;
        $summary['net_or_simultaneous_settlement_intent_confirmed'] = $setOffEvidenceCurrent;
        $desiredOffsetAmount = !empty($summary['closing_balance_acknowledged']) && $setOffEvidenceCurrent
            ? (float)($summary['required_offset_amount'] ?? 0)
            : 0.0;
        $pendingAdjustmentAmount = round(
            $desiredOffsetAmount - (float)($summary['posted_offset_amount'] ?? 0),
            2
        );
        $summary['desired_offset_amount'] = $desiredOffsetAmount;
        $summary['pending_adjustment_amount'] = $pendingAdjustmentAmount;
        $summary['offset_amount'] = $pendingAdjustmentAmount;
        $summary['offset_status'] = $this->offsetStatus(
            (float)($summary['required_offset_amount'] ?? 0),
            $desiredOffsetAmount,
            (float)($summary['posted_offset_amount'] ?? 0),
            $pendingAdjustmentAmount,
            !empty($summary['posted_offset_reliable'])
        );
        $summary['offset_status_label'] = \HelperFramework::labelFromKey((string)$summary['offset_status'], '_');
        $summary['offset_journal_posted'] = $this->hasPostedOffset($summary);
        $summary['current_offset_journal_posted'] = !empty($summary['offset_journal_posted'])
            && $this->hasCurrentPostedOffset(
                (array)($summary['offset_journals'] ?? []),
                $accountingPeriodId
            );
        $summary['can_post'] = abs($pendingAdjustmentAmount) >= 0.005
            && (array)($summary['warnings'] ?? []) === []
            && !empty($summary['posted_offset_reliable']);
        $summary['proposed_lines'] = !empty($summary['can_post'])
            ? $this->proposedLines(
                (int)$assetNominal['id'],
                (int)$liabilityNominal['id'],
                $pendingAdjustmentAmount
            )
            : [];
        $summary['post_blocked_reason'] = $this->evidenceAwarePostBlockedReason($summary);

        return $summary;
    }

    public function saveSetOffEvidence(
        int $companyId,
        int $accountingPeriodId,
        bool $acknowledged,
        bool $legallyEnforceableRight,
        bool $netOrSimultaneousSettlementIntent,
        string $note,
        string $changedBy = 'web_app'
    ): array {
        (new VatSupportScopeService())
            ->assertTaxAndYearEndSupported($companyId, 'save director loan set-off evidence');
        ($this->lockService ?? new YearEndLockService())
            ->assertUnlocked($companyId, $accountingPeriodId, 'change director loan set-off evidence in this period');

        $service = $this->acknowledgementService ?? new YearEndAcknowledgementService();
        if (!$acknowledged) {
            $context = $this->fetchContext($companyId, $accountingPeriodId);
            if (!empty($context['available']) && $this->hasPostedOffset($context)) {
                return [
                    'success' => false,
                    'status' => 422,
                    'errors' => [
                        'A director loan offset journal remains posted. Reverse that journal before revoking its supporting set-off evidence.',
                    ],
                    'context' => $context,
                ];
            }

            return $service->revoke(
                $companyId,
                $accountingPeriodId,
                self::SET_OFF_ACKNOWLEDGEMENT_CODE,
                true
            );
        }

        $note = trim($note);
        $errors = [];
        if (!$legallyEnforceableRight) {
            $errors[] = 'Confirm that the company has a legally enforceable right to set off the balances.';
        }
        if (!$netOrSimultaneousSettlementIntent) {
            $errors[] = 'Confirm the intention to settle net or simultaneously.';
        }
        if ($note === '') {
            $errors[] = 'Enter the evidence supporting the director loan set-off.';
        }
        if ($errors !== []) {
            return ['success' => false, 'status' => 422, 'errors' => $errors];
        }

        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return $context + ['success' => false];
        }
        if (empty($context['offset_candidate_available'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => ['There are no overlapping normal director loan asset and liability balances to set off.'],
                'context' => $context,
            ];
        }

        return $service->save(
            $companyId,
            $accountingPeriodId,
            self::SET_OFF_ACKNOWLEDGEMENT_CODE,
            $this->setOffEvidenceBasis($service, $context),
            $changedBy,
            $note,
            true
        );
    }

    public function postOffset(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array
    {
        $scopeBlock = (new VatSupportScopeService())->mutationBlockResult($companyId, 'post a Year End director loan offset');
        if ($scopeBlock !== null) {
            return $scopeBlock;
        }

        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'post a director loan offset in this period');
        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return [
                'success' => false,
                'errors' => (array)($context['errors'] ?? ['Director loan offset is not available.']),
            ];
        }

        if (abs((float)($context['pending_adjustment_amount'] ?? 0)) < 0.005) {
            return [
                'success' => true,
                'journal' => $context['existing_offset_journal'] ?? null,
                'already_current' => true,
                'context' => $context,
            ];
        }

        if (empty($context['can_post'])) {
            return [
                'success' => false,
                'errors' => [(string)($context['post_blocked_reason'] ?? 'No director loan offset journal is required.')],
                'context' => $context,
            ];
        }

        $accountingPeriod = (array)($context['accounting_period'] ?? []);
        $journalDate = (string)($accountingPeriod['period_end'] ?? '');
        $lines = (array)($context['proposed_lines'] ?? []);

        $pendingAdjustmentAmount = round((float)($context['pending_adjustment_amount'] ?? 0), 2);
        $result = ($this->journalService ?? new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
            $companyId,
            $accountingPeriodId,
            self::OFFSET_JOURNAL_TAG,
            self::OFFSET_JOURNAL_KEY,
            $journalDate,
            self::OFFSET_JOURNAL_DESCRIPTION,
            $lines,
            'manual',
            null,
            null,
            ($pendingAdjustmentAmount >= 0 ? 'Applies' : 'Reverses')
                . ' the FRS 105 director loan set-off by '
                . number_format(abs($pendingAdjustmentAmount), 2, '.', '')
                . ' to reach the evidenced closing presentation.',
            $changedBy
        );

        if (empty($result['success'])) {
            return $result;
        }

        $result['context'] = $this->fetchContext($companyId, $accountingPeriodId);

        return $result;
    }

    private function directorLoanNominal(int $companyId, string $settingKey, string $subtypeCode, string $fallbackCode, string $accountType): ?array
    {
        $configuredNominalId = $this->configuredDirectorLoanNominalId($companyId, $settingKey);
        if ($configuredNominalId > 0) {
            $configuredNominal = $this->fetchConfiguredNominal($configuredNominalId, $accountType);
            if ($configuredNominal !== null) {
                return $configuredNominal;
            }
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT na.id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    COALESCE(na.account_type, \'\') AS account_type,
                    COALESCE(nas.code, \'\') AS subtype_code,
                    COALESCE(na.sort_order, 100) AS sort_order
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE COALESCE(na.is_active, 0) = 1
               AND (
                    nas.code = :subtype_code
                    OR (na.code = :code AND na.account_type = :account_type)
               )
             ORDER BY COALESCE(na.sort_order, 100), na.id
             LIMIT 10',
            [
                'subtype_code' => $subtypeCode,
                'code' => $fallbackCode,
                'account_type' => $accountType,
            ]
        );

        return $this->chooseDirectorLoanNominal($rows, $subtypeCode, $fallbackCode, $accountType);
    }

    private function configuredDirectorLoanNominalId(int $companyId, string $settingKey): int
    {
        if ($companyId <= 0) {
            return 0;
        }

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $nominalId = (int)($settings[$settingKey] ?? 0);
        if ($nominalId > 0) {
            return $nominalId;
        }

        if ($settingKey === 'director_loan_liability_nominal_id') {
            return (int)($settings['director_loan_nominal_id'] ?? 0);
        }

        return 0;
    }

    private function fetchConfiguredNominal(int $nominalId, string $accountType): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT na.id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    COALESCE(na.account_type, \'\') AS account_type,
                    COALESCE(nas.code, \'\') AS subtype_code,
                    COALESCE(na.sort_order, 100) AS sort_order
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :nominal_id
               AND COALESCE(na.is_active, 0) = 1
               AND na.account_type = :account_type
             LIMIT 1',
            [
                'nominal_id' => $nominalId,
                'account_type' => $accountType,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function chooseDirectorLoanNominal(array $nominals, string $subtypeCode, string $fallbackCode, string $accountType): ?array
    {
        foreach ($nominals as $nominal) {
            if ((int)($nominal['id'] ?? 0) > 0 && (string)($nominal['subtype_code'] ?? '') === $subtypeCode) {
                return $nominal;
            }
        }

        foreach ($nominals as $nominal) {
            if (
                (int)($nominal['id'] ?? 0) > 0
                && (string)($nominal['code'] ?? '') === $fallbackCode
                && (string)($nominal['account_type'] ?? '') === $accountType
            ) {
                return $nominal;
            }
        }

        return null;
    }

    private function fetchGrossBalances(int $companyId, string $periodEnd, int $assetNominalId, int $liabilityNominalId): array
    {
        $metadataOffsetPredicate = \InterfaceDB::tableExists('journal_entry_metadata')
            ? ' OR EXISTS (
                    SELECT 1
                    FROM journal_entry_metadata offset_metadata
                    WHERE offset_metadata.journal_id = j.id
                      AND offset_metadata.journal_tag = :offset_journal_tag
                )'
            : '';
        $rows = \InterfaceDB::fetchAll(
            'SELECT jl.nominal_account_id,
                    COALESCE(SUM(jl.debit), 0) AS debit,
                    COALESCE(SUM(jl.credit), 0) AS credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date <= :period_end
               AND jl.nominal_account_id IN (:asset_nominal_id, :liability_nominal_id)
               AND NOT (
                    COALESCE(j.source_ref, \'\') = :legacy_offset_source_ref
                    OR COALESCE(j.source_ref, \'\') LIKE :offset_source_ref_prefix
                    ' . $metadataOffsetPredicate . '
                )
              GROUP BY jl.nominal_account_id',
            [
                'company_id' => $companyId,
                'period_end' => $periodEnd,
                'asset_nominal_id' => $assetNominalId,
                'liability_nominal_id' => $liabilityNominalId,
                'legacy_offset_source_ref' => 'meta:' . self::OFFSET_JOURNAL_TAG . ':' . self::OFFSET_JOURNAL_KEY,
                'offset_source_ref_prefix' => 'meta:' . self::OFFSET_JOURNAL_TAG . ':%',
                ...(\InterfaceDB::tableExists('journal_entry_metadata')
                    ? ['offset_journal_tag' => self::OFFSET_JOURNAL_TAG]
                    : []),
            ]
        );

        $balances = [
            $assetNominalId => ['debit' => 0.0, 'credit' => 0.0],
            $liabilityNominalId => ['debit' => 0.0, 'credit' => 0.0],
        ];

        foreach ($rows as $row) {
            $nominalId = (int)($row['nominal_account_id'] ?? 0);
            if (!isset($balances[$nominalId])) {
                continue;
            }

            $balances[$nominalId] = [
                'debit' => round((float)($row['debit'] ?? 0), 2),
                'credit' => round((float)($row['credit'] ?? 0), 2),
            ];
        }

        return $balances;
    }

    private function fetchPostedOffsetJournals(int $companyId, string $periodEnd): array
    {
        if ($companyId <= 0 || trim($periodEnd) === '') {
            return [];
        }

        $metadataAvailable = \InterfaceDB::tableExists('journal_entry_metadata');
        $metadataJoin = $metadataAvailable
            ? 'LEFT JOIN journal_entry_metadata jem ON jem.journal_id = j.id'
            : '';
        $metadataColumns = $metadataAvailable
            ? ', COALESCE(jem.journal_tag, \'\') AS journal_tag,
                 COALESCE(jem.journal_key, \'\') AS journal_key'
            : ', \'\' AS journal_tag,
                 \'\' AS journal_key';
        $metadataPredicate = $metadataAvailable
            ? ' OR jem.journal_tag = :journal_tag'
            : '';
        $params = [
            'company_id' => $companyId,
            'period_end' => $periodEnd,
            'legacy_source_ref' => 'meta:' . self::OFFSET_JOURNAL_TAG . ':' . self::OFFSET_JOURNAL_KEY,
            'source_ref_prefix' => 'meta:' . self::OFFSET_JOURNAL_TAG . ':%',
        ];
        if ($metadataAvailable) {
            $params['journal_tag'] = self::OFFSET_JOURNAL_TAG;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT j.id,
                    j.company_id,
                    j.accounting_period_id,
                    j.source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    j.journal_date,
                    j.description,
                    j.is_posted
                    ' . $metadataColumns . '
             FROM journals j
             ' . $metadataJoin . '
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date <= :period_end
               AND (
                    COALESCE(j.source_ref, \'\') = :legacy_source_ref
                    OR COALESCE(j.source_ref, \'\') LIKE :source_ref_prefix
                    ' . $metadataPredicate . '
               )
             ORDER BY j.journal_date ASC, j.id ASC',
            $params
        );

        foreach ($rows as &$row) {
            $row['lines'] = \InterfaceDB::fetchAll(
                'SELECT nominal_account_id,
                        COALESCE(debit, 0) AS debit,
                        COALESCE(credit, 0) AS credit,
                        COALESCE(line_description, \'\') AS line_description
                 FROM journal_lines
                 WHERE journal_id = :journal_id
                 ORDER BY id',
                ['journal_id' => (int)($row['id'] ?? 0)]
            );
        }
        unset($row);

        return $rows;
    }

    private function postedOffsetSummary(array $journals, int $assetNominalId, int $liabilityNominalId): array
    {
        $amount = 0.0;
        $warnings = [];
        $journalAmounts = [];

        foreach ($journals as $journal) {
            $liabilitySigned = 0.0;
            $assetSigned = 0.0;
            $unexpectedAmount = 0.0;
            foreach ((array)($journal['lines'] ?? []) as $line) {
                $nominalId = (int)($line['nominal_account_id'] ?? 0);
                $debit = round((float)($line['debit'] ?? 0), 2);
                $credit = round((float)($line['credit'] ?? 0), 2);
                if ($nominalId === $liabilityNominalId) {
                    $liabilitySigned += $debit - $credit;
                } elseif ($nominalId === $assetNominalId) {
                    $assetSigned += $credit - $debit;
                } else {
                    $unexpectedAmount += $debit + $credit;
                }
            }

            $liabilitySigned = round($liabilitySigned, 2);
            $assetSigned = round($assetSigned, 2);
            if (abs($liabilitySigned - $assetSigned) >= 0.005 || $unexpectedAmount >= 0.005) {
                $warnings[] = 'Director loan offset journal '
                    . (int)($journal['id'] ?? 0)
                    . ' does not contain a balanced asset/liability set-off pair.';
                continue;
            }

            $journalAmount = round($liabilitySigned, 2);
            $journalAmounts[(int)($journal['id'] ?? 0)] = $journalAmount;
            $amount += $journalAmount;
        }

        return [
            'amount' => round($amount, 2),
            'reliable' => $warnings === [],
            'warnings' => $warnings,
            'journals' => $journals,
            'journal_amounts' => $journalAmounts,
        ];
    }

    private function buildSummary(
        array $assetNominal,
        array $liabilityNominal,
        array $balances,
        ?array $existingOffset,
        array $postedOffsets
    ): array
    {
        $assetNominalId = (int)($assetNominal['id'] ?? 0);
        $liabilityNominalId = (int)($liabilityNominal['id'] ?? 0);
        $assetBalance = (array)($balances[$assetNominalId] ?? ['debit' => 0.0, 'credit' => 0.0]);
        $liabilityBalance = (array)($balances[$liabilityNominalId] ?? ['debit' => 0.0, 'credit' => 0.0]);

        $assetReceivable = round((float)($assetBalance['debit'] ?? 0) - (float)($assetBalance['credit'] ?? 0), 2);
        $liabilityPayable = round((float)($liabilityBalance['credit'] ?? 0) - (float)($liabilityBalance['debit'] ?? 0), 2);
        $warnings = [];
        if ($assetReceivable < -0.004) {
            $warnings[] = 'Director Loan Asset has an abnormal credit balance. Review postings before offsetting.';
        }
        if ($liabilityPayable < -0.004) {
            $warnings[] = 'Director Loan Liability has an abnormal debit balance. Review postings before offsetting.';
        }

        $normalAssetReceivable = $assetReceivable > 0 ? $assetReceivable : 0.0;
        $normalLiabilityPayable = $liabilityPayable > 0 ? $liabilityPayable : 0.0;
        $requiredOffsetAmount = $warnings === []
            ? round(min($normalAssetReceivable, $normalLiabilityPayable), 2)
            : 0.0;
        $warnings = array_merge($warnings, (array)($postedOffsets['warnings'] ?? []));
        $postedOffsetAmount = round((float)($postedOffsets['amount'] ?? 0), 2);
        $netPosition = round($liabilityPayable - $assetReceivable, 2);

        return [
            'asset_nominal' => $assetNominal,
            'liability_nominal' => $liabilityNominal,
            'asset_receivable' => $assetReceivable,
            'liability_payable' => $liabilityPayable,
            'asset_debit' => round((float)($assetBalance['debit'] ?? 0), 2),
            'asset_credit' => round((float)($assetBalance['credit'] ?? 0), 2),
            'liability_debit' => round((float)($liabilityBalance['debit'] ?? 0), 2),
            'liability_credit' => round((float)($liabilityBalance['credit'] ?? 0), 2),
            'offset_amount' => 0.0,
            'required_offset_amount' => $requiredOffsetAmount,
            'desired_offset_amount' => 0.0,
            'pending_adjustment_amount' => 0.0,
            'net_position' => $netPosition,
            'net_position_label' => $this->netPositionLabel($netPosition),
            'existing_offset_journal' => $existingOffset,
            'posted_offset_amount' => $postedOffsetAmount,
            'cumulative_posted_offset_amount' => $postedOffsetAmount,
            'posted_offset_reliable' => !empty($postedOffsets['reliable']),
            'offset_journals' => (array)($postedOffsets['journals'] ?? []),
            'posted_offset_journal_count' => count((array)($postedOffsets['journals'] ?? [])),
            'offset_status' => 'not_required',
            'offset_status_label' => \HelperFramework::labelFromKey('not_required', '_'),
            'warnings' => $warnings,
            'can_post' => false,
            'post_blocked_reason' => '',
            'proposed_lines' => [],
        ];
    }

    private function offsetStatus(
        float $requiredOffsetAmount,
        float $desiredOffsetAmount,
        float $postedOffsetAmount,
        float $pendingAdjustmentAmount,
        bool $postedOffsetReliable
    ): string
    {
        if (!$postedOffsetReliable) {
            return 'invalid';
        }

        if (abs($pendingAdjustmentAmount) >= 0.005) {
            return abs($postedOffsetAmount) < 0.005 && $pendingAdjustmentAmount > 0
                ? 'missing'
                : 'stale';
        }

        if ($desiredOffsetAmount > 0.004) {
            return 'current';
        }

        if ($requiredOffsetAmount > 0.004) {
            return 'gross_presentation';
        }

        return 'not_required';
    }

    private function proposedLines(int $assetNominalId, int $liabilityNominalId, float $offsetAmount): array
    {
        $amount = number_format(abs($offsetAmount), 2, '.', '');
        $applyingSetOff = $offsetAmount > 0;

        return [
            [
                'nominal_account_id' => $applyingSetOff ? $liabilityNominalId : $assetNominalId,
                'debit' => $amount,
                'credit' => '0.00',
                'line_description' => self::OFFSET_JOURNAL_DESCRIPTION,
            ],
            [
                'nominal_account_id' => $applyingSetOff ? $assetNominalId : $liabilityNominalId,
                'debit' => '0.00',
                'credit' => $amount,
                'line_description' => self::OFFSET_JOURNAL_DESCRIPTION,
            ],
        ];
    }

    private function netPositionLabel(float $netPosition): string
    {
        if ($netPosition > 0.004) {
            return 'Company owes director';
        }

        if ($netPosition < -0.004) {
            return 'Director owes company';
        }

        return 'Settled';
    }

    private function setOffEvidenceBasis(YearEndAcknowledgementService $service, array $context): array
    {
        return $service->buildBasis(self::SET_OFF_ACKNOWLEDGEMENT_CODE, [
            'director_loan_asset_balance' => number_format((float)($context['asset_receivable'] ?? 0), 2, '.', ''),
            'director_loan_liability_balance' => number_format((float)($context['liability_payable'] ?? 0), 2, '.', ''),
            'offset_amount' => number_format(
                (float)($context['required_offset_amount'] ?? $context['offset_amount'] ?? 0),
                2,
                '.',
                ''
            ),
            'legally_enforceable_right_confirmed' => true,
            'net_or_simultaneous_settlement_intent_confirmed' => true,
        ]);
    }

    private function evidenceAwarePostBlockedReason(array $context): string
    {
        if ((array)($context['warnings'] ?? []) !== []) {
            return 'Review abnormal or malformed director loan balances and offset journals before posting an adjustment.';
        }
        if (empty($context['posted_offset_reliable'])) {
            return 'The cumulative director loan offset could not be verified, so the closing presentation cannot be adjusted automatically.';
        }
        if (abs((float)($context['pending_adjustment_amount'] ?? 0)) < 0.005) {
            if ((float)($context['desired_offset_amount'] ?? 0) > 0.004) {
                return 'The director loan offset journal is already current.';
            }
            if ((float)($context['required_offset_amount'] ?? 0) > 0.004) {
                return 'The director loan balances are presented gross until current closing-balance and FRS 105 set-off evidence is saved.';
            }
            return 'No director loan offset journal is required.';
        }
        if ((float)($context['pending_adjustment_amount'] ?? 0) > 0
            && empty($context['closing_balance_acknowledged'])) {
            return 'Agree the current director loan closing balance before applying a set-off.';
        }
        if ((float)($context['pending_adjustment_amount'] ?? 0) > 0
            && empty($context['set_off_evidence_current'])) {
            return 'Keep the balances gross unless both FRS 105 set-off criteria and supporting evidence are confirmed.';
        }
        return '';
    }

    private function hasCurrentPostedOffset(array $journals, int $accountingPeriodId): bool
    {
        foreach ($journals as $journal) {
            if ((int)($journal['accounting_period_id'] ?? 0) === $accountingPeriodId
                && (int)($journal['is_posted'] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }

    private function hasPostedOffset(array $context): bool
    {
        return abs((float)($context['posted_offset_amount'] ?? 0)) >= 0.005;
    }
}
