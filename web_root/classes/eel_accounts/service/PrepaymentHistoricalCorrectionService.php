<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class PrepaymentHistoricalCorrectionService
{
    public const ACKNOWLEDGEMENT_CODE = 'historical_filing_correction';

    public function __construct(
        private readonly ?PrepaymentScheduleService $scheduleService = null,
        private readonly ?YearEndAcknowledgementService $acknowledgementService = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function fetchContext(int $companyId, int $accountingPeriodId, ?array $knownScheduleContext = null): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['available' => false, 'errors' => ['Select a company and accounting period first.']];
        }
        $period = \InterfaceDB::fetchOne(
            'SELECT id, company_id, period_start, period_end
             FROM accounting_periods
             WHERE id = :id AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period)) {
            return ['available' => false, 'errors' => ['The selected accounting period could not be found.']];
        }

        $schedules = $this->scheduleService ?? new PrepaymentScheduleService();
        $repair = $schedules->fetchRepairContext($companyId, $accountingPeriodId);
        $scheduleContext = $knownScheduleContext ?? ($schedules->hasSchema()
            ? $schedules->fetchPeriodContext($companyId, $accountingPeriodId)
            : ['available' => false, 'errors' => ['Run the automated prepayment schedule repair migration first.'], 'schedules' => []]);
        $documents = $this->companiesHouseDocuments($companyId, (string)$period['period_end']);
        $hmrc = $this->hmrcFilingEvidence($companyId, $accountingPeriodId);
        $basis = $this->buildBasis($period, $documents, $hmrc, $scheduleContext);
        $acknowledgements = $this->acknowledgementService ?? new YearEndAcknowledgementService();
        $acknowledgement = $acknowledgements->fetch($companyId, $accountingPeriodId, self::ACKNOWLEDGEMENT_CODE);
        $approval = $acknowledgements->evaluate($acknowledgement, $basis);
        $profitChange = $this->expectedProfitChange((array)($scheduleContext['schedules'] ?? []));
        $hasPrepaymentWork = (int)($repair['missing_count'] ?? 0) > 0
            || (array)($scheduleContext['schedules'] ?? []) !== [];
        $filed = $documents !== [];

        return [
            'available' => true,
            'errors' => array_values(array_unique(array_merge(
                (array)($repair['errors'] ?? []),
                (array)($scheduleContext['errors'] ?? [])
            ))),
            'accounting_period' => $period,
            'companies_house_filed' => $filed,
            'companies_house_documents' => $documents,
            'hmrc_filing' => $hmrc,
            'repair' => $repair,
            'schedule_context' => $scheduleContext,
            'has_prepayment_work' => $hasPrepaymentWork,
            'requires_acknowledgement' => $filed && $hasPrepaymentWork,
            'expected_profit_change_pence' => $profitChange,
            'basis' => $basis,
            'acknowledgement' => $approval,
            'posting_permitted' => !$filed || !$hasPrepaymentWork || (
                (int)($repair['missing_count'] ?? 0) === 0
                && (string)($hmrc['state'] ?? 'unknown') !== 'unknown'
                && !empty($approval['current'])
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function confirmHmrcFilingStatus(
        int $companyId,
        int $accountingPeriodId,
        string $status,
        string $reference,
        string $notes,
        string $changedBy = 'web_app'
    ): array {
        $status = strtolower(trim($status));
        $reference = trim($reference);
        if (!in_array($status, ['filed', 'not_filed'], true)) {
            return ['success' => false, 'errors' => ['Select whether the Corporation Tax return was filed or not filed.']];
        }
        if ($reference === '') {
            return ['success' => false, 'errors' => ['Record the HMRC submission reference or the evidence used to confirm that the return was not filed.']];
        }
        $obligation = \InterfaceDB::fetchOne(
            'SELECT id
             FROM hmrc_obligations
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND obligation_type = :obligation_type
             ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'obligation_type' => 'ct600_filing']
        );
        if (!is_array($obligation)) {
            return ['success' => false, 'errors' => ['Synchronise the HMRC Corporation Tax filing obligation before recording its evidence.']];
        }

        (new YearEndLockService())->assertUnlockedForUpdate($companyId, $accountingPeriodId, 'record historical HMRC filing evidence');
        \InterfaceDB::execute(
            'UPDATE hmrc_obligations
             SET status = :status,
                 source = :source,
                 source_reference = :source_reference,
                 notes = CASE WHEN :notes = \'\' THEN notes ELSE :notes END,
                 checked_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'status' => $status === 'filed' ? 'filed' : 'not_started',
                'source' => 'manual',
                'source_reference' => $reference,
                'notes' => trim($notes),
                'id' => (int)$obligation['id'],
            ]
        );
        ($this->acknowledgementService ?? new YearEndAcknowledgementService())->revoke(
            $companyId,
            $accountingPeriodId,
            self::ACKNOWLEDGEMENT_CODE,
            true
        );
        (new YearEndLockService())->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            'prepayment_historical_hmrc_status_confirmed',
            $changedBy,
            null,
            ['filing_status' => $status, 'source_reference' => $reference]
        );

        return ['success' => true, 'errors' => [], 'hmrc_filing' => $this->hmrcFilingEvidence($companyId, $accountingPeriodId)];
    }

    /** @return array<string, mixed> */
    public function acknowledge(
        int $companyId,
        int $accountingPeriodId,
        string $changedBy,
        string $note = ''
    ): array {
        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available']) || empty($context['companies_house_filed'])) {
            return ['success' => false, 'errors' => ['A matching filed Companies House accounts document is required for a historical-correction acknowledgement.']];
        }
        if ((int)($context['repair']['missing_count'] ?? 0) > 0) {
            return ['success' => false, 'errors' => ['Recalculate every missing prepayment schedule before approving the historical correction.']];
        }
        if ((string)($context['hmrc_filing']['state'] ?? 'unknown') === 'unknown') {
            return ['success' => false, 'errors' => ['Record evidence confirming whether the HMRC Corporation Tax return was filed before approving the historical correction.']];
        }
        if (empty($context['has_prepayment_work'])) {
            return ['success' => false, 'errors' => ['There is no prepayment correction to approve for this period.']];
        }
        (new YearEndLockService())->assertUnlockedForUpdate($companyId, $accountingPeriodId, 'approve a historical prepayment correction');
        $saved = ($this->acknowledgementService ?? new YearEndAcknowledgementService())->save(
            $companyId,
            $accountingPeriodId,
            self::ACKNOWLEDGEMENT_CODE,
            (array)$context['basis'],
            $changedBy,
            $note,
            true
        );
        if (!empty($saved['success'])) {
            (new YearEndLockService())->writeAuditLog(
                $companyId,
                $accountingPeriodId,
                'prepayment_historical_correction_acknowledged',
                $changedBy,
                null,
                ['basis_hash' => (string)($saved['acknowledgement']['basis_hash'] ?? '')]
            );
        }
        return $saved;
    }

    public function assertPostingPermitted(int $companyId, int $accountingPeriodId): void
    {
        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            throw new \RuntimeException((string)(($context['errors'] ?? [])[0] ?? 'The historical prepayment correction state is unavailable.'));
        }
        if (empty($context['requires_acknowledgement'])) {
            return;
        }
        if ((int)($context['repair']['missing_count'] ?? 0) > 0) {
            throw new \RuntimeException('Recalculate the missing legacy prepayment schedules explicitly before posting into filed accounts.');
        }
        if ((string)($context['hmrc_filing']['state'] ?? 'unknown') === 'unknown') {
            throw new \RuntimeException('Confirm with evidence whether the HMRC Corporation Tax return was filed before posting into this filed accounting period.');
        }
        if (empty($context['acknowledgement']['current'])) {
            throw new \RuntimeException('Approve the current historical prepayment correction after reviewing the filed Companies House accounts and HMRC filing evidence.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function companiesHouseDocuments(int $companyId, string $periodEnd): array
    {
        if (!\InterfaceDB::tableExists('companies_house_documents')) {
            return [];
        }
        return \InterfaceDB::fetchAll(
            'SELECT id, transaction_id, filing_date, filing_type, filing_description,
                    document_id, significant_date, raw_content_hash, parse_status
             FROM companies_house_documents
             WHERE company_id = :company_id
               AND filing_category = :category
               AND significant_date = :period_end
             ORDER BY filing_date, id',
            ['company_id' => $companyId, 'category' => 'accounts', 'period_end' => $periodEnd]
        );
    }

    /** @return array<string, mixed> */
    private function hmrcFilingEvidence(int $companyId, int $accountingPeriodId): array
    {
        if (\InterfaceDB::tableExists('hmrc_ct600_submissions')) {
            $submission = \InterfaceDB::fetchOne(
                'SELECT id, mode, status, submission_type, hmrc_submission_reference, submitted_at
                 FROM hmrc_ct600_submissions
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND mode = :mode
                   AND status = :status
                 ORDER BY id DESC LIMIT 1',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'mode' => 'LIVE', 'status' => 'accepted']
            );
            if (is_array($submission)) {
                return ['state' => 'filed', 'source' => 'hmrc_submission', 'submission' => $submission];
            }
        }
        if (!\InterfaceDB::tableExists('hmrc_obligations')) {
            return ['state' => 'unknown', 'source' => 'unavailable'];
        }
        $obligation = \InterfaceDB::fetchOne(
            'SELECT id, status, source, source_reference, checked_at, notes
             FROM hmrc_obligations
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND obligation_type = :obligation_type
             ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'obligation_type' => 'ct600_filing']
        );
        if (!is_array($obligation)) {
            return ['state' => 'unknown', 'source' => 'missing_obligation'];
        }
        $explicit = (string)$obligation['source'] === 'manual'
            && trim((string)($obligation['source_reference'] ?? '')) !== ''
            && trim((string)($obligation['checked_at'] ?? '')) !== '';
        $state = $explicit && (string)$obligation['status'] === 'filed'
            ? 'filed'
            : ($explicit && (string)$obligation['status'] === 'not_started' ? 'not_filed' : 'unknown');
        return ['state' => $state, 'source' => 'obligation', 'obligation' => $obligation];
    }

    /** @return array<string, mixed> */
    private function buildBasis(array $period, array $documents, array $hmrc, array $scheduleContext): array
    {
        $documentEvidence = array_map(static fn(array $document): array => [
            'document_id' => (string)($document['document_id'] ?? ''),
            'transaction_id' => (string)($document['transaction_id'] ?? ''),
            'filing_date' => (string)($document['filing_date'] ?? ''),
            'filing_type' => (string)($document['filing_type'] ?? ''),
            'significant_date' => (string)($document['significant_date'] ?? ''),
            // The generic acknowledgement compactor deliberately retains
            // source facts; use an explicit source key so the document hash
            // participates in the approval basis.
            'source_document_hash' => (string)($document['raw_content_hash'] ?? ''),
            'parse_status' => (string)($document['parse_status'] ?? ''),
        ], $documents);
        $schedules = [];
        foreach ((array)($scheduleContext['schedules'] ?? []) as $schedule) {
            $allocation = (array)($schedule['selected_allocation'] ?? []);
            $schedules[] = [
                'review_id' => (int)($schedule['review_id'] ?? 0),
                'schedule_id' => (int)($schedule['id'] ?? 0),
                'source_calculation_hash' => (string)($schedule['calculation_hash'] ?? ''),
                'posting_role' => (string)($allocation['posting_role'] ?? ''),
                'posting_target_pence' => (int)($allocation['posting_target_pence'] ?? 0),
                'expense_pence' => (int)($allocation['expense_pence'] ?? 0),
                'closing_deferred_pence' => (int)($allocation['closing_deferred_pence'] ?? 0),
            ];
        }
        return (new YearEndAcknowledgementService())->buildBasis(self::ACKNOWLEDGEMENT_CODE, [
            'accounting_period_id' => (int)$period['id'],
            'period_start' => (string)$period['period_start'],
            'period_end' => (string)$period['period_end'],
            'companies_house_documents' => $documentEvidence,
            'hmrc_filing' => $hmrc,
            'prepayment_schedules' => $schedules,
        ]);
    }

    private function expectedProfitChange(array $schedules): int
    {
        $change = 0;
        foreach ($schedules as $schedule) {
            $allocation = (array)($schedule['selected_allocation'] ?? []);
            $target = (int)($allocation['posting_target_pence'] ?? 0);
            $change += (string)($allocation['posting_role'] ?? '') === 'deferral' ? $target : -$target;
        }
        return $change;
    }
}
