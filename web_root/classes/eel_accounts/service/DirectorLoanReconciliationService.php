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
            $accountingPeriodId,
            (int)$assetNominal['id'],
            (int)$liabilityNominal['id']
        );

        $summary = $this->buildSummary($assetNominal, $liabilityNominal, $balances, is_array($existingOffset) ? $existingOffset : null);
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

        return $summary;
    }

    public function postOffset(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array
    {
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'post a director loan offset in this period');
        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return [
                'success' => false,
                'errors' => (array)($context['errors'] ?? ['Director loan offset is not available.']),
            ];
        }

        if ((string)($context['offset_status'] ?? '') === 'current') {
            return [
                'success' => true,
                'journal' => $context['existing_offset_journal'] ?? null,
                'already_current' => true,
                'context' => $context,
            ];
        }

        if ((string)($context['offset_status'] ?? '') === 'stale') {
            return [
                'success' => false,
                'errors' => ['A stale director loan offset journal already exists. Review or reverse the existing offset before posting another one.'],
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
            'Offsets the overlapping Director Loan Asset and Director Loan Liability balances at year end.',
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

    private function fetchGrossBalances(int $companyId, int $accountingPeriodId, int $assetNominalId, int $liabilityNominalId): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT jl.nominal_account_id,
                    COALESCE(SUM(jl.debit), 0) AS debit,
                    COALESCE(SUM(jl.credit), 0) AS credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND jl.nominal_account_id IN (:asset_nominal_id, :liability_nominal_id)
               AND COALESCE(j.source_ref, \'\') <> :offset_source_ref
             GROUP BY jl.nominal_account_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'asset_nominal_id' => $assetNominalId,
                'liability_nominal_id' => $liabilityNominalId,
                'offset_source_ref' => 'meta:' . self::OFFSET_JOURNAL_TAG . ':' . self::OFFSET_JOURNAL_KEY,
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

    private function buildSummary(array $assetNominal, array $liabilityNominal, array $balances, ?array $existingOffset): array
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
        $offsetAmount = $warnings === []
            ? round(min($normalAssetReceivable, $normalLiabilityPayable), 2)
            : 0.0;
        $postedOffsetAmount = $this->postedOffsetAmount($existingOffset, $assetNominalId, $liabilityNominalId);
        $offsetStatus = $this->offsetStatus($offsetAmount, $postedOffsetAmount, $existingOffset);
        $netPosition = round($liabilityPayable - $assetReceivable, 2);
        $canPost = $offsetAmount > 0 && $warnings === [] && $offsetStatus !== 'current';

        return [
            'asset_nominal' => $assetNominal,
            'liability_nominal' => $liabilityNominal,
            'asset_receivable' => $assetReceivable,
            'liability_payable' => $liabilityPayable,
            'asset_debit' => round((float)($assetBalance['debit'] ?? 0), 2),
            'asset_credit' => round((float)($assetBalance['credit'] ?? 0), 2),
            'liability_debit' => round((float)($liabilityBalance['debit'] ?? 0), 2),
            'liability_credit' => round((float)($liabilityBalance['credit'] ?? 0), 2),
            'offset_amount' => $offsetAmount,
            'net_position' => $netPosition,
            'net_position_label' => $this->netPositionLabel($netPosition),
            'existing_offset_journal' => $existingOffset,
            'posted_offset_amount' => $postedOffsetAmount,
            'offset_status' => $offsetStatus,
            'offset_status_label' => \HelperFramework::labelFromKey($offsetStatus, '_'),
            'warnings' => $warnings,
            'can_post' => $canPost,
            'post_blocked_reason' => $this->postBlockedReason($offsetAmount, $warnings, $offsetStatus),
            'proposed_lines' => $offsetAmount > 0 ? $this->proposedLines($assetNominalId, $liabilityNominalId, $offsetAmount) : [],
        ];
    }

    private function postedOffsetAmount(?array $existingOffset, int $assetNominalId, int $liabilityNominalId): float
    {
        if ($existingOffset === null) {
            return 0.0;
        }

        $liabilityDebit = 0.0;
        $assetCredit = 0.0;
        foreach ((array)($existingOffset['lines'] ?? []) as $line) {
            $nominalId = (int)($line['nominal_account_id'] ?? 0);
            if ($nominalId === $liabilityNominalId) {
                $liabilityDebit += (float)($line['debit'] ?? 0);
            }
            if ($nominalId === $assetNominalId) {
                $assetCredit += (float)($line['credit'] ?? 0);
            }
        }

        if (abs(round($liabilityDebit - $assetCredit, 2)) >= 0.005) {
            return 0.0;
        }

        return round($liabilityDebit, 2);
    }

    private function offsetStatus(float $offsetAmount, float $postedOffsetAmount, ?array $existingOffset): string
    {
        if ($existingOffset === null) {
            return $offsetAmount > 0 ? 'missing' : 'not_required';
        }

        if (abs(round($offsetAmount - $postedOffsetAmount, 2)) < 0.005) {
            return $offsetAmount > 0 ? 'current' : 'not_required';
        }

        return 'stale';
    }

    private function proposedLines(int $assetNominalId, int $liabilityNominalId, float $offsetAmount): array
    {
        $amount = number_format($offsetAmount, 2, '.', '');

        return [
            [
                'nominal_account_id' => $liabilityNominalId,
                'debit' => $amount,
                'credit' => '0.00',
                'line_description' => self::OFFSET_JOURNAL_DESCRIPTION,
            ],
            [
                'nominal_account_id' => $assetNominalId,
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

    private function postBlockedReason(float $offsetAmount, array $warnings, string $offsetStatus): string
    {
        if ($warnings !== []) {
            return 'Review abnormal director loan balances before posting an offset journal.';
        }

        if ($offsetAmount <= 0) {
            return 'No director loan offset journal is required.';
        }

        if ($offsetStatus === 'current') {
            return 'The director loan offset journal is already current.';
        }

        return '';
    }
}
