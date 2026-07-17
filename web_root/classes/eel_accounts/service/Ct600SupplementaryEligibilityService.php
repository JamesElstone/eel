<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Fail-closed phase-one supplementary-page and exceptional-scope guard. */
final class Ct600SupplementaryEligibilityService
{
    private Ct600SupplementaryAssessmentRepository $repository;
    private ?\Closure $directorLoanResolver;

    public function __construct(
        ?Ct600SupplementaryAssessmentRepository $repository = null,
        ?callable $directorLoanResolver = null
    ) {
        $this->repository = $repository ?? new Ct600SupplementaryAssessmentRepository();
        $this->directorLoanResolver = $directorLoanResolver !== null
            ? \Closure::fromCallable($directorLoanResolver)
            : null;
    }

    /**
     * Returns either the current immutable assessment or a fresh fail-closed draft.
     *
     * @return array<string, mixed>
     */
    public function assessmentMatrix(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $binding = $this->repository->currentBinding($companyId, $accountingPeriodId, $ctPeriodId);
        $assessment = $this->repository->fetchCurrent(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            (int)$binding['computation_run_id'],
            (string)$binding['year_end_locked_at']
        );
        $objectiveA = $this->directorLoanRow($companyId, $accountingPeriodId);

        return [
            'binding' => $binding,
            'assessment' => $assessment,
            'assessment_id' => is_array($assessment) ? (int)$assessment['id'] : null,
            'assessment_hash' => is_array($assessment) ? (string)$assessment['assessment_hash'] : null,
            'hash_valid' => is_array($assessment) ? !empty($assessment['hash_valid']) : null,
            'rows' => is_array($assessment)
                ? (array)$assessment['rows']
                : $this->replaceRow(Ct600SupplementaryAssessmentContract::unknownRows(), $objectiveA),
            'objective_ct600a' => $objectiveA,
        ];
    }

    /**
     * Persists a full admin assessment. CT600A is always bound to the objective
     * DirectorLoanService result and cannot be manually downgraded.
     *
     * @param array<int|string, mixed> $answers
     * @return array<string, mixed>
     */
    public function recordAssessment(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        array $answers,
        string $approvedBy,
        ?\DateTimeImmutable $approvedAt = null
    ): array {
        $binding = $this->repository->currentBinding($companyId, $accountingPeriodId, $ctPeriodId);
        $provided = $this->indexAnswers($answers);
        $knownKeys = array_column(Ct600SupplementaryAssessmentContract::definitions(), 'contract_key');
        $unexpectedKeys = array_values(array_diff(array_keys($provided), $knownKeys));
        if ($unexpectedKeys !== []) {
            throw new \InvalidArgumentException(
                'Unknown supplementary assessment answer: ' . implode(', ', $unexpectedKeys) . '.'
            );
        }
        foreach (Ct600SupplementaryAssessmentContract::definitions() as $definition) {
            $key = (string)$definition['contract_key'];
            if ($key !== 'ct600a' && !array_key_exists($key, $provided)) {
                throw new \InvalidArgumentException(
                    $definition['label'] . ' must be explicitly assessed before the matrix can be recorded.'
                );
            }
        }

        $objectiveA = $this->directorLoanRow($companyId, $accountingPeriodId);
        if (
            isset($provided['ct600a']['status'])
            && strtolower(trim((string)$provided['ct600a']['status'])) !== (string)$objectiveA['status']
        ) {
            throw new \DomainException(
                'The CT600A answer does not match the current DirectorLoanService evidence and cannot be recorded.'
            );
        }

        $rows = [];
        foreach (Ct600SupplementaryAssessmentContract::unknownRows() as $row) {
            $key = (string)$row['contract_key'];
            if ($key === 'ct600a') {
                $rows[] = $objectiveA;
                continue;
            }
            $answer = (array)$provided[$key];
            $rows[] = array_merge($row, [
                'status' => $answer['status'] ?? Ct600SupplementaryAssessmentContract::UNKNOWN,
                'evidence_source' => $answer['evidence_source'] ?? '',
                'evidence_ref' => $answer['evidence_ref'] ?? '',
                'detail' => $answer['detail'] ?? '',
            ]);
        }
        $rows = Ct600SupplementaryAssessmentContract::normaliseRows($rows);

        return $this->repository->create(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            (int)$binding['computation_run_id'],
            (string)$binding['year_end_locked_at'],
            $rows,
            $approvedBy,
            $approvedAt
        );
    }

    /** @return array<string, mixed> */
    public function assess(int $companyId, int $accountingPeriodId, int $ctPeriodId, array $computation = []): array
    {
        $company = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId);
        $blockers = [];
        $warnings = [];

        if (!is_array($company)) {
            $blockers[] = 'The company record could not be loaded.';
        } else {
            if ((string)($company['company_status'] ?? '') !== 'active') {
                $blockers[] = 'Phase one supports active companies only.';
            }
            if ((string)($company['companies_house_type'] ?? '') !== 'ltd') {
                $blockers[] = 'Phase one supports an ordinary private limited company only.';
            }
            if (!empty($company['has_insolvency_history']) || !empty($company['has_been_liquidated'])) {
                $blockers[] = 'Insolvency or liquidation cases require a separately reviewed CT600 implementation.';
            }
        }

        if ($computation === []) {
            $computation = (new CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
        }
        if (empty($computation['available'])) {
            $blockers[] = 'The locked Corporation Tax computation is unavailable for supplementary-page assessment.';
        } else {
            if ((int)($computation['unknown_treatment_count'] ?? 0) > 0) {
                $blockers[] = 'Unknown Corporation Tax treatments must be resolved before CT600 preparation.';
            }
            if ((int)($computation['other_treatment_count'] ?? 0) > 0) {
                $blockers[] = 'Non-standard Corporation Tax treatments require a manual CT600/supplementary-page review outside phase one.';
            }
        }

        $matrix = null;
        try {
            $matrix = $this->assessmentMatrix($companyId, $accountingPeriodId, $ctPeriodId);
        } catch (\Throwable $exception) {
            $blockers[] = 'The supplementary-scope assessment is unavailable: ' . $exception->getMessage();
        }

        $rows = is_array($matrix)
            ? (array)($matrix['rows'] ?? [])
            : $this->replaceRow(
                Ct600SupplementaryAssessmentContract::unknownRows(),
                $this->directorLoanRow($companyId, $accountingPeriodId)
            );
        $assessment = is_array($matrix) && is_array($matrix['assessment'] ?? null)
            ? $matrix['assessment']
            : null;
        $binding = is_array($matrix) ? (array)($matrix['binding'] ?? []) : [];
        $computationRunId = (int)($computation['computation_run_id'] ?? 0);
        if (
            $computationRunId > 0
            && isset($binding['computation_run_id'])
            && $computationRunId !== (int)$binding['computation_run_id']
        ) {
            $blockers[] = 'The supplementary assessment is not bound to the current locked computation run.';
        }
        if (is_array($assessment) && empty($assessment['hash_valid'])) {
            $blockers[] = 'The persisted supplementary assessment failed its immutable SHA-256 hash check.';
        }
        if (is_array($assessment) && is_array($matrix)) {
            $persistedA = $this->rowByKey($rows, 'ct600a');
            $objectiveA = (array)($matrix['objective_ct600a'] ?? []);
            $objectiveMatches = is_array($persistedA);
            foreach (['status', 'evidence_source', 'evidence_ref', 'detail'] as $field) {
                $objectiveMatches = $objectiveMatches
                    && (string)($objectiveA[$field] ?? '') === (string)($persistedA[$field] ?? '');
            }
            if (!$objectiveMatches) {
                $blockers[] = 'The persisted CT600A assessment no longer matches the current DirectorLoanService evidence.';
            }
        }

        $requiredPages = [];
        $requiredAttachments = [];
        $requiredScopeItems = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string)($row['status'] ?? Ct600SupplementaryAssessmentContract::UNKNOWN);
            $page = trim((string)($row['page'] ?? ''));
            $label = trim((string)($row['label'] ?? $row['contract_key'] ?? 'Assessment item'));
            $detail = trim((string)($row['detail'] ?? ''));
            if ($status === Ct600SupplementaryAssessmentContract::REQUIRED) {
                $identifier = $page !== '' ? $page : $label;
                $blockers[] = $identifier . ' is required and unsupported in phase one'
                    . ($detail !== '' ? ': ' . $detail : '.')
                    . ($detail !== '' && !str_ends_with($detail, '.') ? '.' : '');
                $requiredScopeItems[] = (string)($row['contract_key'] ?? '');
                if ($page !== '') {
                    $requiredPages[] = $page;
                }
                if ((string)($row['contract_key'] ?? '') === 'additional_attachments') {
                    $requiredAttachments[] = $detail !== '' ? $detail : $label;
                }
            } elseif ($status === Ct600SupplementaryAssessmentContract::UNKNOWN) {
                $identifier = $page !== '' ? $page : $label;
                $blockers[] = $identifier . ' has not been assessed for this locked computation.';
            }
        }

        if (is_array($assessment)) {
            $warnings[] = 'Supplementary assessment #' . (int)$assessment['id']
                . ' is bound to computation run #' . (int)$assessment['computation_run_id']
                . ' and Year End lock ' . (string)$assessment['year_end_locked_at'] . '.';
        } else {
            $warnings[] = 'An authenticated admin must record the complete supplementary-scope matrix for this locked computation.';
        }

        $blockers = array_values(array_unique($blockers));
        $requiredPages = array_values(array_unique($requiredPages));
        $requiredAttachments = array_values(array_unique($requiredAttachments));

        return [
            'ok' => $blockers === [],
            'supported' => $blockers === [],
            'assessment_id' => is_array($assessment) ? (int)$assessment['id'] : null,
            'assessment_hash' => is_array($assessment) ? (string)$assessment['assessment_hash'] : null,
            'assessment_hash_valid' => is_array($assessment) ? !empty($assessment['hash_valid']) : null,
            'assessment_binding' => $binding,
            'matrix' => $rows,
            'required_pages' => $requiredPages,
            'required_additional_attachments' => $requiredAttachments,
            'required_scope_items' => array_values(array_unique($requiredScopeItems)),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /** @param array<int|string, mixed> $answers @return array<string, array<string, mixed>> */
    private function indexAnswers(array $answers): array
    {
        $indexed = [];
        foreach ($answers as $key => $answer) {
            if (!is_array($answer)) {
                throw new \InvalidArgumentException('Every supplementary assessment answer must be an array.');
            }
            $contractKey = strtolower(trim((string)($answer['contract_key'] ?? (is_string($key) ? $key : ''))));
            if ($contractKey === '' || isset($indexed[$contractKey])) {
                throw new \InvalidArgumentException('Supplementary assessment answers need unique contract keys.');
            }
            $indexed[$contractKey] = $answer;
        }
        return $indexed;
    }

    /** @return array<string, mixed> */
    private function directorLoanRow(int $companyId, int $accountingPeriodId): array
    {
        $row = Ct600SupplementaryAssessmentContract::unknownRows()[0];
        try {
            $review = $this->directorLoanResolver !== null
                ? ($this->directorLoanResolver)($companyId, $accountingPeriodId)
                : (new DirectorLoanService())->fetchTaxReview($companyId, $accountingPeriodId);
            if (!is_array($review) || empty($review['success']) || empty($review['available'])) {
                $errors = is_array($review) ? (array)($review['errors'] ?? []) : [];
                return array_merge($row, [
                    'evidence_source' => 'director_loan_service',
                    'evidence_ref' => 'company:' . $companyId . '/accounting-period:' . $accountingPeriodId,
                    'detail' => (string)($errors[0] ?? 'Director-loan evidence is unavailable.'),
                ]);
            }
            $exposure = round((float)($review['exposure_amount'] ?? 0), 2);
            $required = !empty($review['review_required'])
                || !empty($review['director_owes_company'])
                || $exposure >= 0.005;

            return array_merge($row, [
                'status' => $required
                    ? Ct600SupplementaryAssessmentContract::REQUIRED
                    : Ct600SupplementaryAssessmentContract::NOT_REQUIRED,
                'evidence_source' => 'director_loan_service',
                'evidence_ref' => 'company:' . $companyId . '/accounting-period:' . $accountingPeriodId,
                'detail' => $required
                    ? 'The locked director-loan review reports a participator-loan exposure of £'
                        . number_format($exposure, 2, '.', ',') . '.'
                    : 'The locked director-loan review reports no participator-loan exposure.',
            ]);
        } catch (\Throwable $exception) {
            return array_merge($row, [
                'evidence_source' => 'director_loan_service',
                'evidence_ref' => 'company:' . $companyId . '/accounting-period:' . $accountingPeriodId,
                'detail' => 'Director-loan evidence could not be resolved: ' . $exception->getMessage(),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $rows @param array<string, mixed> $replacement @return list<array<string, mixed>> */
    private function replaceRow(array $rows, array $replacement): array
    {
        foreach ($rows as $index => $row) {
            if ((string)($row['contract_key'] ?? '') === (string)($replacement['contract_key'] ?? '')) {
                $rows[$index] = $replacement;
                break;
            }
        }
        return $rows;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed>|null */
    private function rowByKey(array $rows, string $key): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (string)($row['contract_key'] ?? '') === $key) {
                return $row;
            }
        }
        return null;
    }
}
