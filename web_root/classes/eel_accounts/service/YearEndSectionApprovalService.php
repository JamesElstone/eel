<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * The sole approval contract for Year End sections.  A section provider owns
 * its facts and questions; cards only render the returned review model.
 */
final class YearEndSectionApprovalService
{
    public const CONTRACT_VERSION = 'year_end_section_v2';

    private const SECTION_CODES = [
        'director_loan_year_end_review',
        'tax_readiness_acknowledgement',
        'expense_position_acknowledgement',
        'retained_earnings_close_confirmation',
        'transaction_tail_review',
        'prepayment_approvals',
        'cut_off_journals_review',
        'fixed_asset_review_placeholder',
        'companies_house_mismatch_acknowledgement',
        'companies_house_no_filing_acknowledgement',
    ];

    public static function supports(string $checkCode): bool
    {
        return in_array(trim($checkCode), self::SECTION_CODES, true);
    }

    public function fetchReview(int $companyId, int $accountingPeriodId, string $checkCode): array
    {
        $checkCode = $this->checkCode($checkCode);
        $bundle = $this->fetchBundle($companyId, $accountingPeriodId, $checkCode);
        $acknowledgements = new YearEndAcknowledgementService();
        $acknowledgement = $acknowledgements->fetch($companyId, $accountingPeriodId, $checkCode);
        $answers = $this->storedAnswers($acknowledgement);
        $basis = $this->approvalBasis($bundle, $answers);
        $evaluation = $acknowledgements->evaluate(
            $acknowledgement,
            $basis,
            false,
            self::CONTRACT_VERSION
        );
        if (empty($evaluation['current'])
            && $checkCode === 'tax_readiness_acknowledgement'
            && $this->storedTaxBasisMatches($acknowledgement, $basis)) {
            $evaluation['state'] = 'current';
            $evaluation['current'] = true;
            $evaluation['representation_normalised'] = true;
        }

        return [
            'available' => !empty($bundle['available']),
            'errors' => (array)($bundle['errors'] ?? []),
            'check_code' => $checkCode,
            'bundle' => $bundle,
            'display' => (array)($bundle['display'] ?? []),
            'questions' => (array)($bundle['questions'] ?? []),
            'acknowledgement' => $acknowledgement,
            'acknowledgement_state' => (string)($evaluation['state'] ?? 'absent'),
            'acknowledgement_current' => !empty($evaluation['current']),
            'acknowledged_at' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledged_by' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'answers' => $answers,
            'can_approve' => !array_key_exists('can_approve', $bundle) || !empty($bundle['can_approve']),
            'approval_errors' => (array)($bundle['approval_errors'] ?? []),
            'scope_gate' => !empty($bundle['scope_gate']),
            'scope_ready' => !empty($bundle['scope_ready']),
        ];
    }

    /** Resolve the existing Companies House check code without exposing two UI approvals. */
    public function fetchCompaniesHouseReview(int $companyId, int $accountingPeriodId): array
    {
        foreach (['companies_house_mismatch_acknowledgement', 'companies_house_no_filing_acknowledgement'] as $checkCode) {
            $cached = $this->cachedBundle($companyId, $accountingPeriodId, $checkCode);
            if (is_array($cached) && !empty($cached['is_current'])) {
                return $this->fetchReview($companyId, $accountingPeriodId, $checkCode);
            }
        }

        $comparison = (new YearEndCompaniesHouseComparisonService())->fetchComparison($companyId, $accountingPeriodId);
        return $this->fetchReview(
            $companyId,
            $accountingPeriodId,
            !empty($comparison['has_exact_filing'])
                ? 'companies_house_mismatch_acknowledgement'
                : 'companies_house_no_filing_acknowledgement'
        );
    }

    public function approve(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        array $answers,
        string $actor,
        string $note = '',
        ?\Closure $progress = null
    ): array {
        $checkCode = $this->checkCode($checkCode);
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'approve this Year End section');

        $progress?->__invoke('Rebuilding the current Corporation Tax approval basis…', 15);
        $cached = $this->cachedBundle($companyId, $accountingPeriodId, $checkCode);
        if (!$this->tableAvailable()) {
            // Compatibility for installations that are mid-migration. Normal
            // operation always uses the persisted bundle branch below.
            $bundle = $this->buildBundle($companyId, $accountingPeriodId, $checkCode);
        } elseif (!is_array($cached)) {
            // A first approval can safely use a newly generated bundle: the
            // card itself obtains this bundle before displaying its form.
            $bundle = $this->refreshBundle($companyId, $accountingPeriodId, $checkCode);
        } elseif (empty($cached['is_current'])
            || !$this->sourceTokenMatches($cached, $companyId, $accountingPeriodId, $checkCode)
            || (!$this->usesJournalCutOffCacheValidation($checkCode)
                && !$this->bundleHashMatchesLive($cached, $companyId, $accountingPeriodId, $checkCode))) {
            // The rebuilt bundle is deliberately returned without approval. The
            // user must see any changed question or fact before signing it off.
            $previousBundle = $this->decodeBundle($cached);
            $bundle = $this->refreshBundle($companyId, $accountingPeriodId, $checkCode);
            if ($checkCode === 'tax_readiness_acknowledgement'
                && $this->taxBundleChangedOnlyByScope($previousBundle, $bundle)) {
                // A scope-table edit is an intentional gate-control change.
                // Re-read and sign its persisted answers now; do not make the
                // user wait for a calculation-card refresh and submit twice.
            } else {
            return [
                'success' => false,
                'status' => 409,
                'requires_review' => true,
                'bundle' => $bundle,
                'errors' => ['The section changed while it was being reviewed. Review the refreshed facts and submit the approval again.'],
            ];
            }
        } else {
            $bundle = $this->decodeBundle($cached);
        }
        $progress?->__invoke('Validating the Corporation Tax approval basis…', 70);
        if (empty($bundle['available'])) {
            return ['success' => false, 'errors' => (array)($bundle['errors'] ?? ['The current section review is unavailable.'])];
        }
        if (array_key_exists('can_approve', $bundle) && empty($bundle['can_approve'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => (array)($bundle['approval_errors'] ?? ['Resolve the outstanding section checks before approving it.']),
            ];
        }

        $validation = $this->validateAnswers(
            (array)($bundle['questions'] ?? []),
            $this->approvalAnswers($bundle, $answers)
        );
        if (empty($validation['success'])) {
            return $validation;
        }

        if ($checkCode === 'retained_earnings_close_confirmation') {
            // Retained earnings approval owns the distributable-reserve
            // snapshot as well as the confirmation itself.  Capture it before
            // signing the canonical basis, then rebuild so that snapshot is
            // part of the exact facts being approved.
            $prepared = (new RetainedEarningsCloseService())
                ->prepareForAcknowledgement($companyId, $accountingPeriodId, $actor);
            if (empty($prepared['success'])) {
                return $prepared;
            }
            $this->invalidate(
                $companyId,
                $accountingPeriodId,
                $checkCode,
                'Retained earnings reserve review captured for approval'
            );
            $preparedContext = (array)($prepared['context'] ?? []);
            $bundle = $this->tableAvailable()
                ? $this->refreshBundle($companyId, $accountingPeriodId, $checkCode, $preparedContext)
                : $this->buildBundle($companyId, $accountingPeriodId, $checkCode, $preparedContext);
            if (empty($bundle['available'])) {
                return ['success' => false, 'errors' => (array)($bundle['errors'] ?? ['The current section review is unavailable.'])];
            }
            if (array_key_exists('can_approve', $bundle) && empty($bundle['can_approve'])) {
                return [
                    'success' => false,
                    'status' => 422,
                    'errors' => (array)($bundle['approval_errors'] ?? ['Resolve the outstanding section checks before approving it.']),
                ];
            }
            $validation = $this->validateAnswers(
                (array)($bundle['questions'] ?? []),
                $this->approvalAnswers($bundle, $answers)
            );
            if (empty($validation['success'])) {
                return $validation;
            }
        }

        $acknowledgements = new YearEndAcknowledgementService();
        $existing = $acknowledgements->fetch($companyId, $accountingPeriodId, $checkCode);
        $basis = $this->approvalBasis($bundle, (array)$validation['answers']);
        $progress?->__invoke('Recording the Corporation Tax Year End approval…', 85);
        $result = $acknowledgements->save(
            $companyId,
            $accountingPeriodId,
            $checkCode,
            $basis,
            $actor,
            $note,
            false,
            self::CONTRACT_VERSION
        );
        if (empty($result['success'])) {
            return $result;
        }
        if ($checkCode === 'director_loan_year_end_review') {
            $ct600aReview = (new Ct600aService())->saveReview(
                $companyId,
                $accountingPeriodId,
                $this->ct600aReviewAnswers((array)$validation['answers']),
                'director',
                $actor,
                $note
            );
            if (empty($ct600aReview['success'])) {
                $acknowledgements->revoke($companyId, $accountingPeriodId, $checkCode);
                return [
                    'success' => false,
                    'errors' => (array)($ct600aReview['errors'] ?? [
                        'The Section 464A and 464C evidence state could not be synchronized.',
                    ]),
                ];
            }
        }
        if (!$this->usesJournalCutOffCacheValidation($checkCode)) {
            $this->invalidate(
                $companyId,
                $accountingPeriodId,
                $checkCode,
                'Section approval revoked; rebuild the review before reapproval'
            );
        }

        (new YearEndLockService())->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            'review_check_acknowledged',
            $actor,
            $existing,
            (array)($result['acknowledgement'] ?? []),
            trim($note) !== '' ? trim($note) : null
        );
        $progress?->__invoke('Finalising the Corporation Tax Year End approval…', 95);

        return $result;
    }

    public function revoke(int $companyId, int $accountingPeriodId, string $checkCode, string $actor, string $note = ''): array
    {
        $checkCode = $this->checkCode($checkCode);
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'revoke this Year End section approval');
        $acknowledgements = new YearEndAcknowledgementService();
        $existing = $acknowledgements->fetch($companyId, $accountingPeriodId, $checkCode);
        $result = $acknowledgements->revoke($companyId, $accountingPeriodId, $checkCode);
        if (empty($result['success'])) {
            return $result;
        }
        if ($checkCode === 'director_loan_year_end_review') {
            $ct600aReview = (new Ct600aService())->revokeReview($companyId, $accountingPeriodId);
            if (empty($ct600aReview['success'])) {
                return [
                    'success' => false,
                    'errors' => (array)($ct600aReview['errors'] ?? [
                        'The Section 464A and 464C evidence state could not be reopened.',
                    ]),
                ];
            }
        }
        $this->invalidateSectionFromAccountingPeriod(
            $companyId,
            $accountingPeriodId,
            $checkCode,
            'Section approval revoked; rebuild this and dependent later-period reviews before reapproval'
        );

        (new YearEndLockService())->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            'review_check_reopened',
            $actor,
            $existing,
            ['check_code' => $checkCode, 'acknowledged' => false],
            trim($note) !== '' ? trim($note) : null
        );

        return $result;
    }

    /** Mark a cached section dirty from any accounting mutation. */
    public function invalidate(int $companyId, int $accountingPeriodId, string $checkCode, string $reason = ''): void
    {
        if (!$this->tableAvailable() || $companyId <= 0 || $accountingPeriodId <= 0) {
            return;
        }

        $checkCode = $this->checkCode($checkCode);
        \InterfaceDB::execute(
            'UPDATE year_end_section_review_bundles
             SET is_current = 0, invalidated_at = :invalidated_at, invalidated_reason = :reason
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND check_code = :check_code',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'check_code' => $checkCode,
                'invalidated_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'reason' => trim($reason) !== '' ? trim($reason) : null,
            ]
        );
    }

    /**
     * Reopening a signed section can change facts carried into later periods.
     * Keep the acknowledgement rows intact, but force every affected review
     * bundle to rebuild before it can be presented or approved again.
     */
    public function invalidateSectionFromAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        string $reason = ''
    ): void {
        $this->invalidateFromAccountingPeriod(
            $companyId,
            $accountingPeriodId,
            $this->checkCode($checkCode),
            $reason
        );
    }

    /**
     * Unlocking reopens several kinds of close evidence at once. Invalidate
     * every cached section for this period and dependent later periods.
     */
    public function invalidateAllFromAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $reason = ''
    ): void {
        $this->invalidateFromAccountingPeriod($companyId, $accountingPeriodId, null, $reason);
    }

    /**
     * Warm every existing invalidated bundle from this period onward. Failed
     * rows remain invalidated so a later card read can safely retry them.
     *
     * @return array{success: bool, refreshed_count: int, failed_count: int, errors: list<string>}
     */
    public function refreshInvalidatedFromAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
        ?\Closure $progress = null
    ): array {
        if (!$this->tableAvailable() || $companyId <= 0 || $accountingPeriodId <= 0) {
            return ['success' => true, 'refreshed_count' => 0, 'failed_count' => 0, 'errors' => []];
        }

        $period = \InterfaceDB::fetchOne(
            'SELECT period_start
             FROM accounting_periods
             WHERE id = :accounting_period_id AND company_id = :company_id
             LIMIT 1',
            ['accounting_period_id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period) || trim((string)($period['period_start'] ?? '')) === '') {
            return [
                'success' => false,
                'refreshed_count' => 0,
                'failed_count' => 0,
                'errors' => ['The accounting period could not be resolved while rebuilding Year End review caches.'],
            ];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT b.accounting_period_id, b.check_code, ap.label AS accounting_period_label
             FROM year_end_section_review_bundles b
             INNER JOIN accounting_periods ap ON ap.id = b.accounting_period_id
             WHERE b.company_id = :bundle_company_id
               AND ap.company_id = :period_company_id
               AND b.is_current = 0
               AND (
                   ap.period_start > :period_start
                   OR (ap.period_start = :current_period_start AND ap.id >= :current_period_id)
               )
             ORDER BY ap.period_start, ap.id, b.check_code',
            [
                'bundle_company_id' => $companyId,
                'period_company_id' => $companyId,
                'period_start' => (string)$period['period_start'],
                'current_period_start' => (string)$period['period_start'],
                'current_period_id' => $accountingPeriodId,
            ]
        );

        $refreshed = 0;
        $errors = [];
        $total = count($rows);
        $preparedChecklists = [];
        $preparedCompaniesHouseContexts = [];
        foreach ($rows as $index => $row) {
            $targetPeriodId = (int)($row['accounting_period_id'] ?? 0);
            $checkCode = trim((string)($row['check_code'] ?? ''));
            if ($targetPeriodId <= 0 || $checkCode === '') {
                continue;
            }
            $sectionLabel = $this->isCompaniesHouseCheck($checkCode)
                ? 'Companies House comparison'
                : $this->sectionLabel($checkCode);
            $progress?->__invoke(
                'Rebuilding Year End review cache: ' . $sectionLabel
                    . ' — ' . $this->accountingPeriodLabel($row) . '…',
                $total > 0 ? 70 + (int)floor((($index + 1) / $total) * 14) : 84
            );
            try {
                $preparedCompaniesHouseContext = null;
                if ($this->isCompaniesHouseCheck($checkCode)) {
                    if (!array_key_exists($targetPeriodId, $preparedCompaniesHouseContexts)) {
                        $preparedCompaniesHouseContexts[$targetPeriodId] = $this->companiesHouseContext(
                            $companyId,
                            $targetPeriodId
                        );
                    }
                    $preparedCompaniesHouseContext = $preparedCompaniesHouseContexts[$targetPeriodId];
                    if ($checkCode !== $this->companiesHouseCheckCode($preparedCompaniesHouseContext)) {
                        $this->deleteCachedBundle($companyId, $targetPeriodId, $checkCode);
                        continue;
                    }
                }
                $preparedChecklist = null;
                if ($this->usesChecklistContext($checkCode)) {
                    if (!array_key_exists($targetPeriodId, $preparedChecklists)) {
                        $preparedChecklists[$targetPeriodId] = (new YearEndChecklistService())->fetchChecklist(
                            $companyId,
                            $targetPeriodId,
                            false
                        ) ?? [];
                    }
                    $preparedChecklist = $preparedChecklists[$targetPeriodId];
                }
                $bundle = $this->refreshBundle(
                    $companyId,
                    $targetPeriodId,
                    $checkCode,
                    null,
                    $preparedChecklist,
                    $preparedCompaniesHouseContext
                );
                if (empty($bundle['available'])) {
                    $errors[] = 'AP ' . $targetPeriodId . ' / ' . $checkCode . ': '
                        . (string)(($bundle['errors'] ?? [])[0] ?? 'The review bundle is unavailable.');
                    continue;
                }
                $refreshed++;
            } catch (\Throwable $exception) {
                $errors[] = 'AP ' . $targetPeriodId . ' / ' . $checkCode . ': ' . $exception->getMessage();
            }
        }

        return [
            'success' => $errors === [],
            'refreshed_count' => $refreshed,
            'failed_count' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Cached review bundles are identified by stable internal check codes. Use
     * the corresponding checklist title in long-running progress updates.
     */
    private function sectionLabel(string $checkCode): string
    {
        return match ($checkCode) {
            'director_loan_year_end_review' => 'Director Loan Year End Review',
            'tax_readiness_acknowledgement' => 'Tax readiness acknowledgement',
            'expense_position_acknowledgement' => 'Expense position acknowledgement',
            'retained_earnings_close_confirmation' => 'Profit & Loss confirmation',
            'transaction_tail_review' => 'Bank transaction cut-off review',
            'prepayment_approvals' => 'Prepayment approvals',
            'cut_off_journals_review' => 'Cut-off journals review',
            'fixed_asset_review_placeholder' => 'Fixed asset review',
            'companies_house_mismatch_acknowledgement' => 'Accounts comparison metrics',
            'companies_house_no_filing_acknowledgement' => 'No exact accounts filing',
            default => \HelperFramework::labelFromKey($checkCode, '_'),
        };
    }

    /** @param array<string,mixed> $row */
    private function accountingPeriodLabel(array $row): string
    {
        $label = trim((string)($row['accounting_period_label'] ?? ''));
        if ($label !== '') {
            preg_match_all('/(?<!\d)(?:19|20)\d{2}(?!\d)/', $label, $years);
            $years = $years[0] ?? [];
            if (count($years) >= 2) {
                return 'AP:' . substr((string)$years[0], -2) . '/' . substr((string)$years[count($years) - 1], -2);
            }

            return $label;
        }

        return 'Accounting period ' . (int)($row['accounting_period_id'] ?? 0);
    }

    private function isCompaniesHouseCheck(string $checkCode): bool
    {
        return in_array($checkCode, [
            'companies_house_mismatch_acknowledgement',
            'companies_house_no_filing_acknowledgement',
        ], true);
    }

    private function companiesHouseCheckCode(array $context): string
    {
        return !empty((($context['comparison'] ?? [])['has_exact_filing'] ?? false))
            ? 'companies_house_mismatch_acknowledgement'
            : 'companies_house_no_filing_acknowledgement';
    }

    /** Removes a stale cache-only variant that cannot apply to this period. */
    private function deleteCachedBundle(int $companyId, int $accountingPeriodId, string $checkCode): void
    {
        \InterfaceDB::execute(
            'DELETE FROM year_end_section_review_bundles
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND check_code = :check_code',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'check_code' => $checkCode,
            ]
        );
    }

    /**
     * Most review bundles derive their facts from the common Year End
     * checklist. During a bulk warm, calculating it once per period avoids
     * re-running the full metrics, tax, asset, and prepayment read model for
     * every individual section.
     */
    private function usesChecklistContext(string $checkCode): bool
    {
        return !in_array($checkCode, [
            'director_loan_year_end_review',
            'retained_earnings_close_confirmation',
            'companies_house_mismatch_acknowledgement',
            'companies_house_no_filing_acknowledgement',
        ], true);
    }

    private function invalidateFromAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
        ?string $checkCode,
        string $reason
    ): void {
        if (!$this->tableAvailable() || $companyId <= 0 || $accountingPeriodId <= 0) {
            return;
        }

        $period = \InterfaceDB::fetchOne(
            'SELECT period_start
             FROM accounting_periods
             WHERE id = :accounting_period_id AND company_id = :company_id
             LIMIT 1',
            ['accounting_period_id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period) || trim((string)($period['period_start'] ?? '')) === '') {
            if ($checkCode !== null) {
                $this->invalidate($companyId, $accountingPeriodId, $checkCode, $reason);
            }
            return;
        }

        $params = [
            'invalidated_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'reason' => trim($reason) !== '' ? trim($reason) : null,
            'bundle_company_id' => $companyId,
            'period_company_id' => $companyId,
            'period_start' => (string)$period['period_start'],
            'current_period_start' => (string)$period['period_start'],
            'current_period_id' => $accountingPeriodId,
        ];
        $sql = 'UPDATE year_end_section_review_bundles
                SET is_current = 0, invalidated_at = :invalidated_at, invalidated_reason = :reason
                WHERE company_id = :bundle_company_id
                  AND accounting_period_id IN (
                      SELECT id
                      FROM accounting_periods
                      WHERE company_id = :period_company_id
                        AND (
                            period_start > :period_start
                            OR (period_start = :current_period_start AND id >= :current_period_id)
                        )
                  )';
        if ($checkCode !== null) {
            $sql .= ' AND check_code = :check_code';
            $params['check_code'] = $checkCode;
        }
        \InterfaceDB::execute($sql, $params);
    }

    public function refreshBundle(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        ?array $preparedRetainedEarningsContext = null,
        ?array $preparedChecklist = null,
        ?array $preparedCompaniesHouseContext = null
    ): array
    {
        $checkCode = $this->checkCode($checkCode);
        $bundle = $this->buildBundle(
            $companyId,
            $accountingPeriodId,
            $checkCode,
            $preparedRetainedEarningsContext,
            $preparedChecklist,
            $preparedCompaniesHouseContext
        );
        $bundle = $this->validateBundleForPersistence($bundle, $checkCode);
        $bundle['source_token'] = $this->sourceToken(
            $companyId,
            $accountingPeriodId,
            $checkCode,
            $preparedChecklist,
            $preparedCompaniesHouseContext
        );
        $bundle['definition_token'] = $this->definitionToken($checkCode);
        if (!$this->tableAvailable()) {
            return $bundle;
        }

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $json = $this->canonicalJson($bundle);
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'check_code' => $checkCode,
            'contract_version' => self::CONTRACT_VERSION,
            'source_hash' => $this->bundleSourceHash($bundle),
            'bundle_json' => $json,
            'is_current' => !empty($bundle['available']) ? 1 : 0,
            'generated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $sql = 'INSERT INTO year_end_section_review_bundles (
                    company_id, accounting_period_id, check_code, contract_version,
                    source_hash, bundle_json, is_current, generated_at, created_at, updated_at
                ) VALUES (
                    :company_id, :accounting_period_id, :check_code, :contract_version,
                    :source_hash, :bundle_json, :is_current, :generated_at, :created_at, :updated_at
                )';
        $sql .= \InterfaceDB::driverName() === 'sqlite'
            ? ' ON CONFLICT(company_id, accounting_period_id, check_code) DO UPDATE SET
                    contract_version = excluded.contract_version, source_hash = excluded.source_hash,
                    bundle_json = excluded.bundle_json, is_current = excluded.is_current,
                    generated_at = excluded.generated_at, invalidated_at = NULL,
                    invalidated_reason = NULL, updated_at = excluded.updated_at'
            : ' ON DUPLICATE KEY UPDATE
                    contract_version = VALUES(contract_version), source_hash = VALUES(source_hash),
                    bundle_json = VALUES(bundle_json), is_current = VALUES(is_current),
                    generated_at = VALUES(generated_at), invalidated_at = NULL,
                    invalidated_reason = NULL, updated_at = VALUES(updated_at)';
        \InterfaceDB::execute($sql, $params);
        return $bundle;
    }

    private function fetchBundle(int $companyId, int $accountingPeriodId, string $checkCode): array
    {
        $cached = $this->cachedBundle($companyId, $accountingPeriodId, $checkCode);
        if (is_array($cached)
            && !empty($cached['is_current'])
            && $this->sourceTokenMatches($cached, $companyId, $accountingPeriodId, $checkCode)) {
            return $this->decodeBundle($cached);
        }
        return $this->refreshBundle($companyId, $accountingPeriodId, $checkCode);
    }

    private function buildBundle(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        ?array $preparedRetainedEarningsContext = null,
        ?array $preparedChecklist = null,
        ?array $preparedCompaniesHouseContext = null
    ): array
    {
        if ($checkCode === 'director_loan_year_end_review') {
            return $this->directorLoanBundle($companyId, $accountingPeriodId);
        }
        if ($checkCode === 'tax_readiness_acknowledgement') {
            return $this->taxReadinessBundle(
                $companyId,
                $accountingPeriodId,
                $preparedChecklist === null ? null : (array)($preparedChecklist['tax_readiness'] ?? [])
            );
        }
        if ($checkCode === 'retained_earnings_close_confirmation') {
            return $this->retainedEarningsBundle($companyId, $accountingPeriodId, $preparedRetainedEarningsContext);
        }
        if ($this->isCompaniesHouseCheck($checkCode)) {
            return $this->companiesHouseBundle(
                $companyId,
                $accountingPeriodId,
                $checkCode,
                $preparedCompaniesHouseContext
            );
        }

        $checklist = $preparedChecklist ?? (new YearEndChecklistService())->fetchChecklist(
            $companyId,
            $accountingPeriodId,
            false
        ) ?? [];
        foreach ((array)($checklist['checks_flat'] ?? []) as $check) {
            if ((string)($check['check_code'] ?? '') !== $checkCode) {
                continue;
            }
            $facts = (array)($check['basis_data'] ?? $check);
            $bundle = $this->bundle($checkCode, $facts, [], $facts);
            if ((string)($check['status'] ?? '') === 'fail'
                && $checkCode !== 'retained_earnings_close_confirmation') {
                $bundle['can_approve'] = false;
                $bundle['approval_errors'] = ['Resolve the blocking Year End check before approving this section.'];
            }
            if ($checkCode === 'prepayment_approvals') {
                $prepayment = (new PrepaymentReviewService())->fetchContext($companyId, $accountingPeriodId);
                if (empty($prepayment['available']) || (int)($prepayment['pending_count'] ?? 0) > 0) {
                    $bundle['can_approve'] = false;
                    $bundle['approval_errors'] = empty($prepayment['available'])
                        ? (array)($prepayment['errors'] ?? ['The prepayment review is not available.'])
                        : ['Record an explicit decision for every prepayment candidate and complete all pre-paid service dates before approving this section.'];
                }
            }
            return $bundle;
        }

        return ['available' => false, 'errors' => ['The requested Year End section is not available.'], 'check_code' => $checkCode];
    }

    private function directorLoanBundle(int $companyId, int $accountingPeriodId): array
    {
        $display = (new DirectorLoanReconciliationService())->fetchYearEndConfirmationContext($companyId, $accountingPeriodId);
        if (empty($display['available'])) {
            return ['available' => false, 'errors' => (array)($display['errors'] ?? ['Director Loan review is unavailable.']), 'check_code' => 'director_loan_year_end_review'];
        }
        $ct600a = (array)($display['ct600a'] ?? []);
        $questions = [];
        foreach ((array)($ct600a['questions'] ?? []) as $id => $prompt) {
            $questions[] = [
                'id' => 'ct600a.' . (string)$id,
                'prompt' => (string)$prompt,
                'version' => hash('sha256', (string)$prompt),
                'type' => 'choice',
                'options' => ['no' => 'No', 'yes' => 'Yes'],
                'required' => true,
                'required_value' => 'no',
            ];
        }
        $facts = (array)(($display['confirmation_basis'] ?? [])['facts'] ?? []);
        // These values are derived from the answers signed by this same
        // section. Keeping them inside its basis makes any bundle refresh
        // (including the automatic offset journal and lock snapshot) appear
        // to invalidate its own approval.
        unset(
            $facts['ct600a_review_current'],
            $facts['ct600a_review_basis_hash'],
            $facts['ct600a_ready']
        );
        $facts['accounting_period_id'] = $accountingPeriodId;
        $facts['party_facts'] = (array)($facts['party_facts'] ?? $display['party_facts'] ?? []);
        // Keep the historical alias available to cached checklist consumers.
        $facts['director_facts'] = (array)($facts['director_facts'] ?? $facts['party_facts']);
        return $this->bundle('director_loan_year_end_review', $facts, $questions, $display);
    }

    /**
     * The supplementary-page table is edited and persisted directly on the
     * card.  Its answers are nevertheless part of this V2 approval: the
     * canonical bundle reads those saved answers again at submit time rather
     * than trusting hidden browser fields.
     */
    private function taxReadinessBundle(
        int $companyId,
        int $accountingPeriodId,
        ?array $preparedTaxReadiness = null,
        ?array $preparedScope = null
    ): array
    {
        // Read the underlying tax calculation directly from the checklist
        // context, but never use the checklist's acknowledgement check as an
        // input. That check describes this bundle's approval state and would
        // create a circular dependency when an earlier approval becomes stale.
        if ($preparedTaxReadiness === null) {
            $taxReadiness = $this->taxReadinessContext($companyId, $accountingPeriodId);
        } else {
            $taxReadiness = $preparedTaxReadiness;
        }
        if (empty($taxReadiness['available'])) {
            return ['available' => false, 'errors' => ['The Corporation Tax Year End review is unavailable.'], 'check_code' => 'tax_readiness_acknowledgement'];
        }

        $scope = $preparedScope
            ?? (new CorporationTaxFilingScopeService())->fetch($companyId, $accountingPeriodId);
        if (empty($scope['available'])) {
            return ['available' => false, 'errors' => (array)($scope['errors'] ?? ['The Corporation Tax filing-scope review is unavailable.']), 'check_code' => 'tax_readiness_acknowledgement'];
        }

        $questions = [];
        $currentAnswers = [];
        foreach ((array)($scope['definitions'] ?? []) as $key => $definition) {
            $questionId = 'filing_scope.' . (string)$key;
            $prompt = (string)($definition['question'] ?? $key);
            $questions[] = [
                'id' => $questionId,
                'prompt' => $prompt,
                'version' => hash('sha256', $prompt),
                'type' => 'choice',
                'options' => ['no' => 'No', 'yes' => 'Yes'],
                'required' => true,
                'required_value' => 'no',
            ];
            $currentAnswers[$questionId] = (string)(($scope['answers'] ?? [])[$key] ?? '');
        }

        $freezeService = new YearEndTaxFreezeService();
        $freezeBasis = $freezeService->approvalBasis($taxReadiness);
        $facts = is_array($freezeBasis)
            ? $freezeBasis
            : [
                'check_code' => 'tax_readiness_acknowledgement',
                'freeze_status' => (string)($taxReadiness['freeze_status'] ?? 'blocked'),
                'blocking_diagnostic_codes' => array_values(array_map(
                    static fn(array $diagnostic): string => (string)($diagnostic['code'] ?? ''),
                    (array)($taxReadiness['blocking_diagnostics'] ?? [])
                )),
            ];
        $facts['filing_scope_revision'] = (int)($scope['revision'] ?? 0);
        $facts['filing_scope_basis_hash'] = (string)($scope['basis_hash'] ?? '');
        $display = [
            'freeze_status' => (string)($taxReadiness['freeze_status'] ?? ''),
            'freeze_manifest_hash' => (string)($taxReadiness['freeze_manifest_hash'] ?? ''),
            'blocking_diagnostics' => (array)($taxReadiness['blocking_diagnostics'] ?? []),
            'blocking_diagnostic_count' => (int)($taxReadiness['blocking_diagnostic_count'] ?? 0),
            'estimated_corporation_tax' => $taxReadiness['estimated_corporation_tax'] ?? 0,
            'filing_scope_revision' => (int)($scope['revision'] ?? 0),
            'filing_scope_basis_hash' => (string)($scope['basis_hash'] ?? ''),
        ];
        $bundle = $this->bundle('tax_readiness_acknowledgement', $facts, $questions, $display);
        $bundle['answer_source'] = 'persisted_filing_scope';
        $bundle['current_answers'] = $currentAnswers;
        $bundle['scope_gate'] = true;
        $bundle['scope_ready'] = !empty($scope['complete']);

        if ((array)($scope['unanswered_fields'] ?? []) !== []) {
            $bundle['can_approve'] = false;
            $bundle['approval_errors'] = ['Answer every Corporation Tax filing-scope question before approving Year End.'];
        } elseif (!empty($scope['errors'])) {
            $bundle['can_approve'] = false;
            $bundle['approval_errors'] = (array)$scope['errors'];
        } elseif (!is_array($freezeBasis)) {
            $bundle['can_approve'] = false;
            $bundle['approval_errors'] = [
                (string)(($taxReadiness['blocking_diagnostics'][0] ?? [])['message']
                    ?? 'Resolve the blocking Year End tax checks before approving this section.'),
            ];
        }

        return $bundle;
    }

    /**
     * Profit & Loss confirmation is intentionally independent of the full
     * checklist.  Constructing the checklist recalculates unrelated tax and
     * s455 evidence, which makes this otherwise small approval needlessly slow.
     */
    private function retainedEarningsBundle(
        int $companyId,
        int $accountingPeriodId,
        ?array $preparedContext = null
    ): array {
        $service = new RetainedEarningsCloseService();
        $context = $preparedContext ?? $service->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return [
                'available' => false,
                'errors' => (array)($context['errors'] ?? ['Profit & Loss confirmation is unavailable.']),
                'check_code' => 'retained_earnings_close_confirmation',
            ];
        }

        $bundle = $this->bundle(
            'retained_earnings_close_confirmation',
            $service->acknowledgementBasisForContext($context),
            [],
            $this->retainedEarningsDisplay($context)
        );
        if (empty($context['can_acknowledge'])) {
            $bundle['can_approve'] = false;
            $bundle['approval_errors'] = [
                (string)(($context['prior_period_dependency'] ?? [])['detail']
                    ?? 'The current Profit & Loss close cannot yet be approved.'),
            ];
        }
        return $bundle;
    }

    /** @return array<string,mixed> */
    private function retainedEarningsDisplay(array $context): array
    {
        return array_intersect_key($context, array_flip([
            'available',
            'errors',
            'accounting_period',
            'summary',
            'depreciation_preview',
            'journal_lines',
            'existing_journal',
            'reserve_review',
            'prior_period_dependency',
            'warnings',
            'can_acknowledge',
        ]));
    }

    private function companiesHouseBundle(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        ?array $preparedContext = null
    ): array
    {
        $display = $preparedContext ?? $this->companiesHouseContext($companyId, $accountingPeriodId);
        $comparison = (array)($display['comparison'] ?? []);
        if (empty($comparison['available'])) {
            return ['available' => false, 'errors' => (array)($comparison['errors'] ?? ['Companies House comparison is unavailable.']), 'check_code' => $checkCode];
        }

        $hasExactFiling = !empty($comparison['has_exact_filing']);
        $actualCode = $hasExactFiling ? 'companies_house_mismatch_acknowledgement' : 'companies_house_no_filing_acknowledgement';
        if ($actualCode !== $checkCode) {
            return ['available' => false, 'errors' => ['The Companies House filing position changed. Refresh this section before approving it.'], 'check_code' => $checkCode];
        }

        $questions = $hasExactFiling && (int)($display['mismatch_count'] ?? 0) > 0
            ? $this->companiesHouseQuestions(true)
            : [];
        $bundle = $this->bundle($checkCode, $comparison, $questions, $display);
        if (empty($comparison['reliable_closing_balance'])) {
            $bundle['can_approve'] = false;
            $bundle['approval_errors'] = [(string)(($comparison['warnings'] ?? [])[0] ?? 'The Companies House comparison is not reliable enough to approve.')];
        }
        return $bundle;
    }

    private function bundle(string $checkCode, array $facts, array $questions, array $display): array
    {
        return [
            'available' => true,
            'errors' => [],
            'contract_version' => self::CONTRACT_VERSION,
            'check_code' => $checkCode,
            'facts' => $facts,
            'questions' => $questions,
            'display' => $display,
            'can_approve' => true,
            'approval_errors' => [],
        ];
    }

    /**
     * A ready tax review must always carry the canonical freeze manifest it
     * will sign. Persisting a current bundle without that basis would leave
     * the approval UI blocked by its own malformed cache.
     */
    private function validateBundleForPersistence(array $bundle, string $checkCode): array
    {
        if ($checkCode !== 'tax_readiness_acknowledgement'
            || empty($bundle['available'])
            || (string)(($bundle['display'] ?? [])['freeze_status'] ?? '') !== 'ready_for_approval'
            || is_array(($bundle['facts'] ?? [])['freeze_manifest'] ?? null)) {
            return $bundle;
        }

        $message = 'The Corporation Tax basis is ready but its canonical freeze manifest is unavailable. The review cache was not marked current.';
        $bundle['available'] = false;
        $bundle['errors'] = [$message];
        $bundle['can_approve'] = false;
        $bundle['approval_errors'] = [$message];
        return $bundle;
    }

    /** @return list<array<string, mixed>> */
    private function companiesHouseQuestions(bool $includeVarianceExplanation): array
    {
        $eligibilityPrompt = 'Is this Company eligible to submit revised accounts using the Companies House XML Gateway Service?';
        $questions = [[
            'id' => 'companies_house.xml_eligibility',
            'prompt' => $eligibilityPrompt,
            'version' => hash('sha256', $eligibilityPrompt),
            'type' => 'choice',
            'options' => ['eligible' => 'Yes', 'ineligible' => 'No'],
            'required' => true,
            'required_value' => 'eligible',
        ]];
        if ($includeVarianceExplanation) {
            $variancePrompt = 'Why do the Companies House figures need revising?';
            $questions[] = [
                'id' => 'companies_house.variance_explanation',
                'prompt' => $variancePrompt,
                'version' => hash('sha256', $variancePrompt),
                'type' => 'text',
                'required' => true,
            ];
        }
        return $questions;
    }

    private function approvalBasis(array $bundle, array $answers): array
    {
        $basis = [
            'contract_version' => self::CONTRACT_VERSION,
            'check_code' => (string)($bundle['check_code'] ?? ''),
            'facts' => (array)($bundle['facts'] ?? []),
            'questions' => (array)($bundle['questions'] ?? []),
            'answers' => $answers,
        ];
        if ((string)($basis['check_code'] ?? '') === 'tax_readiness_acknowledgement') {
            $basis['facts'] = $this->normaliseTaxApprovalValue((array)$basis['facts']);
        }
        return $basis;
    }

    /**
     * Older tax approvals can contain the same calculation with database-shaped
     * decimals and pool rows in a different order. Preserve those signatures
     * when their canonical calculation and filing-scope basis is unchanged.
     */
    private function storedTaxBasisMatches(?array $acknowledgement, array $currentBasis): bool
    {
        if (!is_array($acknowledgement)) {
            return false;
        }
        $stored = json_decode((string)($acknowledgement['basis_json'] ?? ''), true);
        if (!is_array($stored)
            || (string)($stored['check_code'] ?? '') !== 'tax_readiness_acknowledgement') {
            return false;
        }
        $storedHash = trim((string)($acknowledgement['basis_hash'] ?? ''));
        if ($storedHash === ''
            || !hash_equals($storedHash, (new YearEndAcknowledgementService())->hashBasis($stored))) {
            return false;
        }
        $stored['facts'] = $this->normaliseTaxApprovalValue((array)($stored['facts'] ?? []));
        $currentBasis['facts'] = $this->normaliseTaxApprovalValue((array)($currentBasis['facts'] ?? []));

        return hash_equals($this->canonicalJson($stored), $this->canonicalJson($currentBasis));
    }

    private function normaliseTaxApprovalValue(mixed $value): mixed
    {
        return (new YearEndTaxFreezeService())->canonicalApprovalValue($value);
    }

    /** @param array<string,mixed> $bundle @param array<string,mixed> $submitted */
    private function approvalAnswers(array $bundle, array $submitted): array
    {
        return (string)($bundle['answer_source'] ?? '') === 'persisted_filing_scope'
            ? (array)($bundle['current_answers'] ?? [])
            : $submitted;
    }

    private function taxBundleChangedOnlyByScope(array $previous, array $current): bool
    {
        $normalise = static function (array $bundle): array {
            foreach (['facts', 'display'] as $key) {
                $value = (array)($bundle[$key] ?? []);
                unset($value['filing_scope_revision'], $value['filing_scope_basis_hash']);
                $bundle[$key] = $value;
            }
            unset(
                $bundle['source_token'],
                $bundle['current_answers'],
                $bundle['scope_ready'],
                $bundle['can_approve'],
                $bundle['approval_errors']
            );
            return $bundle;
        };

        return hash_equals($this->canonicalJson($normalise($previous)), $this->canonicalJson($normalise($current)));
    }

    private function validateAnswers(array $questions, array $submitted): array
    {
        $answers = [];
        $errors = [];
        foreach ($questions as $question) {
            $question = (array)$question;
            $id = trim((string)($question['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $value = $submitted[$id] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if (!empty($question['required']) && ($value === null || $value === '')) {
                $errors[] = 'Answer: ' . (string)($question['prompt'] ?? $id);
                continue;
            }
            $options = (array)($question['options'] ?? []);
            if ($value !== null && $options !== [] && !array_key_exists((string)$value, $options)) {
                $errors[] = 'Select a valid answer for: ' . (string)($question['prompt'] ?? $id);
                continue;
            }
            if (array_key_exists('required_value', $question) && (string)$value !== (string)$question['required_value']) {
                $errors[] = 'Resolve the Yes answer before approving: ' . (string)($question['prompt'] ?? $id);
                continue;
            }
            $answers[$id] = $value;
        }
        return $errors === [] ? ['success' => true, 'answers' => $answers] : ['success' => false, 'errors' => $errors];
    }

    private function storedAnswers(?array $acknowledgement): array
    {
        if (!is_array($acknowledgement)) {
            return [];
        }
        $basis = json_decode((string)($acknowledgement['basis_json'] ?? ''), true);
        return is_array($basis) && is_array($basis['answers'] ?? null) ? $basis['answers'] : [];
    }

    private function cachedBundle(int $companyId, int $accountingPeriodId, string $checkCode): ?array
    {
        if (!$this->tableAvailable()) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT contract_version, source_hash, bundle_json, is_current
             FROM year_end_section_review_bundles
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND check_code = :check_code
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'check_code' => $checkCode]
        );
        return is_array($row) && (string)($row['contract_version'] ?? '') === self::CONTRACT_VERSION ? $row : null;
    }

    private function sourceTokenMatches(array $cached, int $companyId, int $accountingPeriodId, string $checkCode): bool
    {
        $bundle = $this->decodeBundle($cached);
        if (!hash_equals((string)($bundle['definition_token'] ?? ''), $this->definitionToken($checkCode))) {
            return false;
        }
        $stored = (string)($bundle['source_token'] ?? '');
        if ($stored === '') {
            return false;
        }
        $current = $this->sourceToken($companyId, $accountingPeriodId, $checkCode);
        return $current === '' || hash_equals($stored, $current);
    }

    /**
     * A cut-off approval is concerned only with its journal and prepayment
     * inputs, which are covered by its section source token. Keeping this
     * bundle current after its live token validation avoids rebuilding the
     * whole Year End checklist immediately after the acknowledgement is saved.
     * Other sections retain the full live-bundle comparison that protects their
     * wider, potentially indirect dependencies.
     */
    private function usesJournalCutOffCacheValidation(string $checkCode): bool
    {
        return $checkCode === 'cut_off_journals_review';
    }

    private function bundleHashMatchesLive(
        array $cached,
        int $companyId,
        int $accountingPeriodId,
        string $checkCode
    ): bool {
        $liveBundle = $this->buildBundle($companyId, $accountingPeriodId, $checkCode);
        $liveBundle['source_token'] = $this->sourceToken($companyId, $accountingPeriodId, $checkCode);
        $liveBundle['definition_token'] = $this->definitionToken($checkCode);
        $liveHash = $this->bundleSourceHash($liveBundle);

        return trim((string)($cached['source_hash'] ?? '')) !== ''
            && hash_equals((string)$cached['source_hash'], $liveHash);
    }

    /**
     * S455's evidence cutoff records when the read model was evaluated, not a
     * change to the underlying evidence. Its own basis hash deliberately
     * excludes this value; do the same for the surrounding Year End bundle so
     * an approval cannot become stale merely by crossing a clock second.
     */
    private function bundleSourceHash(array $bundle): string
    {
        if ((string)($bundle['check_code'] ?? '') === 'director_loan_year_end_review') {
            $bundle = $this->withoutEvidenceCutoff($bundle);
        }

        return hash('sha256', $this->canonicalJson($bundle));
    }

    private function withoutEvidenceCutoff(array $value): array
    {
        unset($value['evidence_cutoff']);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->withoutEvidenceCutoff($item);
            }
        }

        return $value;
    }

    private function sourceToken(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        ?array $preparedChecklist = null,
        ?array $preparedCompaniesHouseContext = null
    ): string
    {
        if ($this->isCompaniesHouseCheck($checkCode)) {
            $context = $preparedCompaniesHouseContext ?? $this->companiesHouseContext($companyId, $accountingPeriodId);
            return hash('sha256', $this->canonicalJson([
                'comparison' => (array)($context['comparison'] ?? []),
                'eligibility' => (array)($context['eligibility'] ?? []),
            ]));
        }

        if ($checkCode !== 'director_loan_year_end_review') {
            return $this->sectionSourceToken($companyId, $accountingPeriodId, $checkCode, $preparedChecklist);
        }

        $tokens = [];
        if (\InterfaceDB::tableExists('journals') && \InterfaceDB::tableExists('journal_lines')) {
            $row = \InterfaceDB::fetchOne(
                'SELECT COUNT(jl.id) AS item_count,
                        COALESCE(MAX(jl.id), 0) AS last_id,
                        COALESCE(MAX(j.updated_at), \'\') AS last_change
                 FROM journals j
                 INNER JOIN journal_lines jl ON jl.journal_id = j.id
                 WHERE j.company_id = :company_id AND j.accounting_period_id = :accounting_period_id AND j.is_posted = 1',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
            $tokens['posted_journals'] = is_array($row) ? $row : [];
        }

        foreach (['company_directors', 'company_parties', 'company_party_roles', 'company_shareholdings'] as $table) {
            $token = $this->companyTableToken($table, $companyId);
            if ($token !== null) {
                $tokens[$table] = $token;
            }
        }
        foreach (['participator_loan_party_terms', 'participator_loan_party_terms_audit'] as $table) {
            $token = $this->companyTableToken($table, $companyId);
            if ($token !== null) {
                $tokens[$table] = $token;
            }
        }
        $snapshotToken = $this->companyPeriodTableToken(
            'participator_loan_party_term_snapshots',
            $companyId,
            $accountingPeriodId
        );
        if ($snapshotToken !== null) {
            $tokens['participator_loan_party_term_snapshots'] = $snapshotToken;
        }
        $ct600aToken = $this->companyPeriodTableToken('corporation_tax_ct600a_events', $companyId, $accountingPeriodId);
        if ($ct600aToken !== null) {
            $tokens['ct600a_events'] = $ct600aToken;
        }

        return $tokens === [] ? '' : hash('sha256', $this->canonicalJson($tokens));
    }

    /**
     * Cheap row-change fingerprints keep ordinary section bundles fast to read
     * while still forcing a refresh if an underlying Year End data set changes.
     * Individual actions also invalidate their relevant bundle immediately.
     */
    private function sectionSourceToken(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        ?array $preparedChecklist = null
    ): string
    {
        $tables = match ($checkCode) {
            'tax_readiness_acknowledgement' => [
                ['corporation_tax_periods', 'accounting_period_id'],
                ['corporation_tax_period_facts', 'accounting_period_id'],
                ['corporation_tax_s455_reviews', 'accounting_period_id'],
                ['corporation_tax_ct600a_events', 'accounting_period_id'],
                ['corporation_tax_ct600a_reviews', 'accounting_period_id'],
                ['corporation_tax_ct600a_accounting_reviews', 'accounting_period_id'],
                ['corporation_tax_scope_confirmations', 'accounting_period_id'],
                ['corporation_tax_computation_runs', 'accounting_period_id'],
            ],
            'expense_position_acknowledgement' => [
                ['expense_claims', 'accounting_period_id'],
                ['expense_claimants', null],
            ],
            'retained_earnings_close_confirmation' => [
                ['journals', 'accounting_period_id'],
                ['prepayment_reviews', 'accounting_period_id'],
                ['corporation_tax_periods', 'accounting_period_id'],
                ['dividend_reserve_review_snapshots', 'accounting_period_id'],
                ['dividend_reserve_classification_rules', null],
            ],
            'transaction_tail_review' => [
                ['transactions', 'accounting_period_id'],
                ['company_accounts', null],
            ],
            'prepayment_approvals' => [
                ['prepayment_reviews', 'accounting_period_id'],
                ['prepayment_schedules', 'source_accounting_period_id'],
            ],
            'cut_off_journals_review' => [
                ['journals', 'accounting_period_id'],
                ['prepayment_reviews', 'accounting_period_id'],
            ],
            'fixed_asset_review_placeholder' => [
                ['transactions', 'accounting_period_id'],
                ['expense_claims', 'accounting_period_id'],
                ['asset_register', null],
            ],
            default => [],
        };

        $tokens = [];
        foreach ($tables as [$table, $periodColumn]) {
            $token = $this->sectionTableToken($table, $companyId, $accountingPeriodId, $periodColumn);
            if ($token !== null) {
                $tokens[$table] = $token;
            }
        }
        if ($checkCode === 'retained_earnings_close_confirmation') {
            $tokens['prior_period_lock'] = $this->priorPeriodLockToken($companyId, $accountingPeriodId);
        }
        if ($checkCode === 'tax_readiness_acknowledgement') {
            $taxReadiness = $preparedChecklist === null
                ? $this->taxReadinessContext($companyId, $accountingPeriodId)
                : (array)($preparedChecklist['tax_readiness'] ?? []);
            $tokens['tax_freeze'] = [
                'available' => !empty($taxReadiness['available']),
                'freeze_status' => (string)($taxReadiness['freeze_status'] ?? ''),
                'freeze_manifest_hash' => (string)($taxReadiness['freeze_manifest_hash'] ?? ''),
                'blocking_diagnostic_codes' => array_values(array_map(
                    static fn(array $diagnostic): string => (string)($diagnostic['code'] ?? ''),
                    (array)($taxReadiness['blocking_diagnostics'] ?? [])
                )),
            ];
            $scope = (new CorporationTaxFilingScopeService())->fetch($companyId, $accountingPeriodId);
            $tokens['corporation_tax_filing_scope'] = [
                'available' => !empty($scope['available']),
                'revision' => (int)($scope['revision'] ?? 0),
                'basis_hash' => (string)($scope['basis_hash'] ?? ''),
            ];
        }
        return $tokens === [] ? '' : hash('sha256', $this->canonicalJson($tokens));
    }

    /** @return array<string,mixed> */
    private function taxReadinessContext(int $companyId, int $accountingPeriodId): array
    {
        $context = \eel_accounts\Support\RequestCache::remember(
            'year-end-section.tax-readiness',
            $companyId . ':' . $accountingPeriodId,
            static function () use ($companyId, $accountingPeriodId): array {
                $checklist = (new YearEndChecklistService())->fetchChecklist(
                    $companyId,
                    $accountingPeriodId,
                    false
                ) ?? [];
                return (array)($checklist['tax_readiness'] ?? []);
            }
        );

        return is_array($context) ? $context : [];
    }

    /** @return array<string,mixed> */
    private function companiesHouseContext(int $companyId, int $accountingPeriodId): array
    {
        $context = \eel_accounts\Support\RequestCache::remember(
            'year-end-section.companies-house',
            $companyId . ':' . $accountingPeriodId,
            static fn(): array => (new CompaniesHouseComparisonReviewService())->fetchContext(
                $companyId,
                $accountingPeriodId
            )
        );

        return is_array($context) ? $context : [];
    }

    /**
     * The P&L close depends on the immediately preceding accounting period
     * being locked. Carry that cross-period state in the source token so a
     * cached review cannot remain current after the prior period is locked,
     * unlocked, or locked again.
     *
     * @return array<string,mixed>
     */
    private function priorPeriodLockToken(int $companyId, int $accountingPeriodId): array
    {
        $currentPeriod = \InterfaceDB::fetchOne(
            'SELECT id, period_start
             FROM accounting_periods
             WHERE id = :accounting_period_id
               AND company_id = :company_id
             LIMIT 1',
            ['accounting_period_id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($currentPeriod) || trim((string)($currentPeriod['period_start'] ?? '')) === '') {
            return [
                'status' => 'current_period_unavailable',
                'current_accounting_period_id' => $accountingPeriodId,
            ];
        }

        $periodStart = (string)$currentPeriod['period_start'];
        $priorPeriod = \InterfaceDB::fetchOne(
            'SELECT id, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_end < :period_start
             ORDER BY period_end DESC, id DESC
             LIMIT 1',
            ['company_id' => $companyId, 'period_start' => $periodStart]
        );
        if (!is_array($priorPeriod)) {
            return [
                'status' => 'first_period',
                'current_accounting_period_id' => (int)$currentPeriod['id'],
                'current_period_start' => $periodStart,
                'prior_accounting_period' => null,
            ];
        }

        $review = (new YearEndLockService())->fetchReview($companyId, (int)$priorPeriod['id']);
        $isLocked = !empty($review['is_locked']);

        return [
            'status' => $isLocked ? 'prior_period_locked' : 'prior_period_unlocked',
            'current_accounting_period_id' => (int)$currentPeriod['id'],
            'current_period_start' => $periodStart,
            'prior_accounting_period' => [
                'id' => (int)$priorPeriod['id'],
                'period_start' => (string)$priorPeriod['period_start'],
                'period_end' => (string)$priorPeriod['period_end'],
            ],
            'is_locked' => $isLocked,
            'locked_at' => $isLocked ? (string)($review['locked_at'] ?? '') : '',
        ];
    }

    private function sectionTableToken(string $table, int $companyId, int $accountingPeriodId, ?string $periodColumn): ?array
    {
        if (!\InterfaceDB::tableExists($table) || !\InterfaceDB::columnExists($table, 'company_id')) {
            return null;
        }
        $conditions = ['company_id = :company_id'];
        $params = ['company_id' => $companyId];
        if ($periodColumn !== null && \InterfaceDB::columnExists($table, $periodColumn)) {
            $conditions[] = $periodColumn . ' = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        }
        $updatedAt = \InterfaceDB::columnExists($table, 'updated_at') ? 'COALESCE(MAX(updated_at), \'\')' : "''";
        $row = \InterfaceDB::fetchOne(
            'SELECT COUNT(*) AS item_count, COALESCE(MAX(id), 0) AS last_id, ' . $updatedAt . ' AS last_change
             FROM ' . $table . ' WHERE ' . implode(' AND ', $conditions),
            $params
        );
        return is_array($row) ? $row : null;
    }

    private function companyTableToken(string $table, int $companyId): ?array
    {
        if (!\InterfaceDB::tableExists($table)) {
            return null;
        }
        $updatedAt = \InterfaceDB::columnExists($table, 'updated_at') ? 'COALESCE(MAX(updated_at), \'\')' : "''";
        $row = \InterfaceDB::fetchOne(
            'SELECT COUNT(*) AS item_count, COALESCE(MAX(id), 0) AS last_id, ' . $updatedAt . ' AS last_change
             FROM ' . $table . ' WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        return is_array($row) ? $row : null;
    }

    private function companyPeriodTableToken(string $table, int $companyId, int $accountingPeriodId): ?array
    {
        if (!\InterfaceDB::tableExists($table)) {
            return null;
        }
        $updatedAt = \InterfaceDB::columnExists($table, 'updated_at') ? 'COALESCE(MAX(updated_at), \'\')' : "''";
        $row = \InterfaceDB::fetchOne(
            'SELECT COUNT(*) AS item_count, COALESCE(MAX(id), 0) AS last_id, ' . $updatedAt . ' AS last_change
             FROM ' . $table . ' WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        return is_array($row) ? $row : null;
    }

    private function definitionToken(string $checkCode): string
    {
        $questions = match ($checkCode) {
            'director_loan_year_end_review' => (new Ct600aService())->reviewQuestions(),
            // Scope answers are persisted by the filing-scope table and signed
            // by the tax V2 provider, rather than submitted in the approval form.
            // Version the canonical freeze-manifest representation as well as
            // the filing-scope questions. Cached pre-canonical bundles must be
            // refreshed before their approval form is shown.
            'tax_readiness_acknowledgement' => ['provider' => 'tax_filing_scope_v4_direct_freeze'],
            'companies_house_mismatch_acknowledgement' => $this->companiesHouseQuestions(true),
            // Version the direct display provider so checklist-era cached
            // bundles are rebuilt with the P&L card's required display model.
            'retained_earnings_close_confirmation' => ['provider' => 'retained_earnings_direct_v2'],
            default => [],
        };
        return hash('sha256', $this->canonicalJson([
            'contract_version' => self::CONTRACT_VERSION,
            'check_code' => $checkCode,
            'questions' => $questions,
        ]));
    }

    /** @return array<string,string> */
    private function ct600aReviewAnswers(array $approvalAnswers): array
    {
        $answers = [];
        foreach ((new Ct600aService())->reviewQuestions() as $key => $_prompt) {
            $answers[(string)$key] = (string)($approvalAnswers['ct600a.' . (string)$key] ?? 'yes');
        }

        return $answers;
    }

    private function decodeBundle(array $row): array
    {
        $bundle = json_decode((string)($row['bundle_json'] ?? ''), true);
        return is_array($bundle) ? $bundle : ['available' => false, 'errors' => ['The cached section review could not be read.']];
    }

    private function checkCode(string $checkCode): string
    {
        $checkCode = trim($checkCode);
        if (!self::supports($checkCode)) {
            throw new \InvalidArgumentException('Unknown Year End approval section.');
        }
        return $checkCode;
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
        return \eel_accounts\Support\PersistentJson::encode(
            $normalise($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private function tableAvailable(): bool
    {
        return \InterfaceDB::tableExists('year_end_section_review_bundles');
    }
}
