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
 * Owns the statutory approval-date invariant for Companies House revised accounts.
 *
 * This service deliberately does not call IxbrlAccountsFilingApprovalService:
 * filing-approval readiness calls this policy before an approval is frozen.
 */
final class CompaniesHouseRevisedAccountsReadinessService
{
    public function assess(int $companyId, int $accountingPeriodId): array
    {
        $result = [
            'applicable' => false,
            'ready' => true,
            'filing_kind' => '',
            'original_document_id' => 0,
            'original_approval_date' => '',
            'revision_approval_date' => '',
            'original_approval_evidence' => [],
            'checks' => [],
            'errors' => [],
        ];

        try {
            $review = (new YearEndSectionApprovalService())->fetchCompaniesHouseReview(
                $companyId,
                $accountingPeriodId
            );
            $display = (array)($review['display'] ?? []);
            $comparison = (array)($display['comparison'] ?? []);
            $filingKind = strtolower(trim((string)($comparison['filing_kind'] ?? '')));
            $correctionRequired = (int)($display['mismatch_count'] ?? 0) > 0;
            $result['filing_kind'] = $filingKind;
            if ($filingKind !== 'revised' || !$correctionRequired) {
                return $result;
            }

            $result['applicable'] = true;
            $documentId = (int)(($comparison['filing_evidence'] ?? [])['document_row_id'] ?? 0);
            $result['original_document_id'] = $documentId;
            $periodEnd = trim((string)\InterfaceDB::fetchColumn(
                'SELECT period_end
                 FROM accounting_periods
                 WHERE id = :id AND company_id = :company_id
                 LIMIT 1',
                ['id' => $accountingPeriodId, 'company_id' => $companyId]
            ));
            $evidence = $this->originalApprovalEvidence($companyId, $documentId, $periodEnd);
            $originalDate = (string)$evidence['approval_date'];
            $result['original_approval_evidence'] = $evidence;
            $result['original_approval_date'] = $originalDate;
            $result['checks'][] = $this->check(
                'revised_original_approval_evidence',
                true,
                'The exact original Companies House approval date is available.'
            );

            $disclosures = (new IxbrlAccountsDisclosureService())->fetch(
                $companyId,
                $accountingPeriodId
            );
            $revisionDate = trim((string)(
                ((array)($disclosures['disclosures'] ?? []))['accounts_approval_date'] ?? ''
            ));
            $result['revision_approval_date'] = $revisionDate;
            $dateError = $this->revisionApprovalDateError($originalDate, $revisionDate);
            $result['checks'][] = $this->check(
                'revised_accounts_approval_date',
                $dateError === null,
                $dateError ?? 'The revised accounts approval date is later than the original approval date.'
            );
            if ($dateError !== null) {
                $result['errors'][] = $dateError
                    . ' Set the Accounts approval date to the actual later revision approval date, '
                    . 'then approve the current Accounting iXBRL filing basis.';
            }
        } catch (\Throwable $exception) {
            $result['checks'][] = $this->check(
                'revised_original_approval_evidence',
                false,
                $exception->getMessage()
            );
            $result['errors'][] = $exception->getMessage();
        }

        $result['errors'] = array_values(array_unique(array_filter(array_map(
            static fn(mixed $error): string => trim((string)$error),
            $result['errors']
        ))));
        $result['ready'] = $result['errors'] === [];
        return $result;
    }

    /**
     * Resolve the immutable approval-date declarations used to prepare a
     * revised artifact. Supplied dates may confirm, but never override, the
     * frozen Accounting iXBRL approval basis.
     */
    public function resolveApprovedDeclarations(
        int $companyId,
        int $accountingPeriodId,
        array $approval,
        array $input = []
    ): array {
        $assessment = $this->assess($companyId, $accountingPeriodId);
        if (empty($assessment['applicable'])) {
            throw new \RuntimeException('The approved Companies House filing classification is not Revised.');
        }
        if (empty($assessment['ready'])) {
            throw new \RuntimeException((string)(($assessment['errors'] ?? [])[0]
                ?? 'The revised accounts approval date is not ready.'));
        }

        $approvalDate = $this->resolveApprovalDate(
            $approval,
            $input,
            (string)$assessment['revision_approval_date'],
            (string)$assessment['original_approval_date']
        );

        return [
            'original_document_id' => (int)$assessment['original_document_id'],
            'original_approval_date' => (string)$assessment['original_approval_date'],
            'original_approval_evidence' => (array)$assessment['original_approval_evidence'],
            'revision_approval_date' => $approvalDate,
        ];
    }

    public function resolveApprovalDate(
        array $approval,
        array $input,
        string $currentDate,
        string $originalDate
    ): string {
        $basisJson = trim((string)($approval['basis_json'] ?? ''));
        if ($basisJson === '') {
            throw new \RuntimeException(
                'The frozen filing-approval basis does not contain an accounts approval date.'
            );
        }
        try {
            $basis = json_decode($basisJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'The frozen filing-approval basis cannot be read: ' . $exception->getMessage()
            );
        }
        $approvalDate = is_array($basis)
            ? trim((string)($basis['disclosures']['values']['accounts_approval_date'] ?? ''))
            : '';
        if (!$this->validDate($approvalDate)) {
            throw new \RuntimeException(
                'The frozen filing-approval basis does not contain a valid accounts approval date.'
            );
        }

        $currentDate = trim($currentDate);
        if (!hash_equals($currentDate, $approvalDate)) {
            throw new \RuntimeException(
                'The current accounts approval disclosure conflicts with the frozen filing-approval basis. '
                . 'Approve the current disclosure basis again before preparing revised accounts.'
            );
        }
        foreach ([
            'revision_approval_date' => 'supplied revision approval date',
            'accounts_approval_date' => 'supplied accounts approval date',
            'board_approval_date' => 'supplied board approval date',
            'date_signed' => 'supplied signing date',
        ] as $key => $label) {
            $candidate = trim((string)($input[$key] ?? ''));
            if ($candidate === '') {
                continue;
            }
            if (!$this->validDate($candidate)) {
                throw new \RuntimeException('The ' . $label . ' is not a valid date.');
            }
            if (!hash_equals($approvalDate, $candidate)) {
                throw new \RuntimeException(
                    'The ' . $label . ' conflicts with the frozen accounts approval date. '
                    . 'Set the current Accounts approval date to the actual revision approval date and '
                    . 'approve that disclosure basis before preparing revised accounts.'
                );
            }
        }

        $dateError = $this->revisionApprovalDateError($originalDate, $approvalDate);
        if ($dateError !== null) {
            throw new \RuntimeException($dateError);
        }

        return $approvalDate;
    }

    public function validateStoredDeclarations(array $declarations): array
    {
        $error = $this->revisionApprovalDateError(
            (string)($declarations['original_approval_date'] ?? ''),
            (string)($declarations['revision_approval_date'] ?? '')
        );
        return [
            'valid' => $error === null,
            'errors' => $error === null ? [] : [$error],
        ];
    }

    public function revisionApprovalDateError(
        string $originalApprovalDate,
        string $revisionApprovalDate
    ): ?string {
        $originalApprovalDate = trim($originalApprovalDate);
        $revisionApprovalDate = trim($revisionApprovalDate);
        if (!$this->validDate($originalApprovalDate)) {
            return 'The original accounts approval date is missing or invalid.';
        }
        if (!$this->validDate($revisionApprovalDate)) {
            return 'The revision approval date is missing or invalid.';
        }
        if ($revisionApprovalDate <= $originalApprovalDate) {
            return 'The revision approval date must be later than the original accounts approval date ('
                . $originalApprovalDate . ').';
        }

        return null;
    }

    /** @return array{approval_date:string,document_id:int,external_document_id:string,fact_id:int,raw_value:string,context_ref:string,source_hash:string} */
    private function originalApprovalEvidence(int $companyId, int $documentId, string $periodEnd): array
    {
        if ($documentId <= 0) {
            throw new \RuntimeException('Select the exact original Companies House filing.');
        }
        if (!$this->validDate($periodEnd)) {
            throw new \RuntimeException('The accounting period does not have a valid period end date.');
        }
        foreach ([
            'companies_house_documents',
            'companies_house_document_facts',
            'companies_house_document_contexts',
            'companies_house_taxonomy_concepts',
        ] as $table) {
            if (!\InterfaceDB::tableExists($table)) {
                throw new \RuntimeException(
                    'The parsed Companies House filing facts are unavailable. Refresh the original filing.'
                );
            }
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT d.id AS document_id, d.document_id AS external_document_id,
                    d.raw_content_hash AS source_hash, f.id AS fact_id,
                    f.raw_value, f.normalised_date, ctx.context_ref
             FROM companies_house_documents d
             INNER JOIN companies_house_document_facts f ON f.document_fk = d.id
             INNER JOIN companies_house_document_contexts ctx ON ctx.id = f.context_fk
             INNER JOIN companies_house_taxonomy_concepts concept ON concept.id = f.concept_fk
             WHERE d.id = :document_id
               AND (d.company_id = :company_id OR d.company_id IS NULL)
               AND concept.short_name = :concept
               AND f.is_latest_year_fact = 1
               AND (ctx.instant_date = :period_end_instant OR ctx.period_end = :period_end_duration)
             ORDER BY f.id',
            [
                'document_id' => $documentId,
                'company_id' => $companyId,
                'concept' => 'DateAuthorisationFinancialStatementsForIssue',
                'period_end_instant' => $periodEnd,
                'period_end_duration' => $periodEnd,
            ]
        );
        $dates = [];
        foreach ($rows as $row) {
            $date = trim((string)($row['normalised_date'] ?? ''));
            if (!$this->validDate($date)) {
                throw new \RuntimeException(
                    'The original filing contains an invalid original accounts approval date.'
                );
            }
            $dates[$date] = $row;
        }
        if ($dates === []) {
            throw new \RuntimeException(
                'The selected original filing has no approval date for this accounting period. '
                . 'Refresh the original iXBRL filing.'
            );
        }
        if (count($dates) !== 1) {
            throw new \RuntimeException('The selected original filing contains conflicting approval dates.');
        }
        $row = array_values($dates)[0];
        return [
            'approval_date' => (string)$row['normalised_date'],
            'document_id' => (int)$row['document_id'],
            'external_document_id' => (string)$row['external_document_id'],
            'fact_id' => (int)$row['fact_id'],
            'raw_value' => (string)($row['raw_value'] ?? ''),
            'context_ref' => (string)($row['context_ref'] ?? ''),
            'source_hash' => (string)($row['source_hash'] ?? ''),
        ];
    }

    private function check(string $key, bool $complete, string $message): array
    {
        return [
            'key' => $key,
            'complete' => $complete,
            'resolution_stage' => 'user_input',
            'message' => $message,
        ];
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === trim($date);
    }
}
