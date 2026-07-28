<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class LoanReviewService
{
    public const FUTURE_ATTRIBUTION_WARNING_CODE = 'loan_future_repayment_attribution_warning';

    public function fetch(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['available' => false, 'errors' => ['Select a company and accounting period first.'], 'items' => []];
        }
        $items = [];
        $futureMovementsByTransaction = [];
        $statement = (new DirectorLoanService())->fetchStatement($companyId, $accountingPeriodId);
        if (empty($statement['success'])) {
            return [
                'available' => false,
                'errors' => (array)($statement['errors'] ?? ['Participator loan review is unavailable.']),
                'items' => [],
            ];
        }
        $relevantParties = $this->relevantParties($statement);
        $missingTermsCount = 0;
        $termsService = new ParticipatorLoanPartyTermsService();
        foreach ($relevantParties as $partyId => $party) {
            $partyName = (string)$party['party_name'];
            $termsSaved = array_key_exists('terms_saved', $party)
                ? !empty($party['terms_saved'])
                : !empty($termsService->fetchTerms($companyId, $partyId, $accountingPeriodId)['explicit']);
            if ($termsSaved) {
                continue;
            }
            $missingTermsCount++;
            $this->appendItem($items, [
                'kind' => 'party_terms',
                'state' => 'requires_action',
                'title' => 'Participator loan terms are required',
                'detail' => $partyName . ' has a movement or balance in this accounting period but has no saved entity terms.',
                'source_label' => 'Entity terms',
                'source_url' => '?page=loans&show_card=director_loan_terms',
                'action_label' => 'Record terms',
                'action_url' => '?page=loans&show_card=director_loan_terms',
                'party_id' => $partyId,
                'party_name' => $partyName,
            ]);
        }
        $disclosure = (new DirectorLoanService())->fetchDisclosureSummary($companyId, $accountingPeriodId);
        foreach ((array)($disclosure['disclosures'] ?? []) as $row) {
            if (empty($row['section_413_required']) || !empty($row['advance_terms_explicit'])) {
                continue;
            }
            $partyName = trim((string)($row['director_name'] ?? '')) ?: 'Participator';
            $this->appendItem($items, [
                'kind' => 'advance_terms',
                'state' => 'requires_action',
                'title' => 'Company-to-participator advance terms are required',
                'detail' => $partyName . ' has a reportable company advance, but its repayment condition has not been confirmed independently of creditor terms.',
                'source_label' => 'Advance terms',
                'source_url' => '?page=loans&show_card=director_loan_terms',
                'action_label' => 'Record advance terms',
                'action_url' => '?page=loans&show_card=director_loan_terms',
                'party_id' => (int)($row['party_id'] ?? 0),
                'party_name' => $partyName,
            ]);
        }
        foreach ((array)($statement['attribution_entries'] ?? []) as $entry) {
            if (!is_array($entry) || (int)($entry['director_id'] ?? 0) > 0) {
                continue;
            }
            $this->appendItem($items, [
                'kind' => 'party_attribution',
                'state' => 'requires_action',
                'title' => 'Participator loan movement needs a party',
                'detail' => trim((string)($entry['description'] ?? 'Loan movement')) . ' on ' . (string)($entry['journal_date'] ?? ''),
                'source_label' => (string)($entry['source_label'] ?? ('Journal #' . (int)($entry['journal_id'] ?? 0))),
                'source_url' => (string)($entry['source_url'] ?? ''),
                'action_label' => 'Assign participant',
                'action_url' => '?page=loans&show_card=director_loan_attribution&director_loan_attribution_filter=requires_assignment',
            ]);
        }

        $statementTaxRelevant = (float)($statement['potential_s455_exposure'] ?? 0) >= 0.005;
        $s455 = (new S455ReviewService())->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        if ($statementTaxRelevant
            && (empty($s455['available']) || (array)($s455['periods'] ?? []) === [])) {
            $this->appendItem($items, [
                'kind' => 's455_evidence',
                'state' => 'requires_action',
                'title' => 's455 evidence requires review',
                'detail' => (string)(($s455['errors'] ?? [])[0]
                    ?? 'No Corporation Tax period is available for the participator-loan exposure.'),
                'source_label' => 'Loans tax evidence',
                'source_url' => '?page=loans&show_card=director_loan_s455',
                'action_label' => 'Review loan tax evidence',
                'action_url' => '?page=loans&show_card=director_loan_s455',
            ]);
        }
        foreach ((array)($s455['periods'] ?? []) as $period) {
            $periodTaxRelevant = $statementTaxRelevant
                || (float)($period['gross_principal'] ?? 0) >= 0.005
                || (float)($period['gross_tax'] ?? 0) >= 0.005
                || (float)($period['net_tax'] ?? 0) >= 0.005;
            if ($periodTaxRelevant
                && (empty($period['close_status_calculated'])
                    || (string)($period['close_company_status'] ?? '') === 'unconfirmed')) {
                $this->appendItem($items, [
                    'kind' => 'close_company_status',
                    'state' => 'requires_action',
                    'title' => 'Close-company status requires review',
                    'detail' => 'The close-company conclusion has not been calculated for CT period '
                        . (int)($period['sequence_no'] ?? 0) . '.',
                    'source_label' => 's455 review',
                    'source_url' => '?page=loans&show_card=director_loan_s455',
                    'action_label' => 'Review s455 evidence',
                    'action_url' => '?page=loans&show_card=director_loan_s455',
                ]);
            }
            foreach ((array)($period['unattributed_movements'] ?? []) as $movement) {
                if (!is_array($movement)) {
                    continue;
                }
                $this->appendItem($items, [
                    'kind' => 'party_attribution',
                    'state' => 'requires_action',
                    'title' => 'Participator loan transaction needs a party',
                    'detail' => 'Transaction #' . (int)($movement['transaction_id'] ?? 0) . ' on ' . (string)($movement['txn_date'] ?? '') . ' is part of the s455 evidence window but has no confirmed ownership party.',
                    'source_label' => (string)($movement['source_label'] ?? ''),
                    'source_url' => (string)($movement['source_url'] ?? ''),
                    'action_label' => 'Assign participant',
                    'action_url' => (string)($movement['action_url'] ?? $movement['source_url'] ?? ''),
                ]);
            }
            foreach ((array)($period['future_unattributed_movements'] ?? []) as $movement) {
                if (is_array($movement) && (int)($movement['transaction_id'] ?? 0) > 0) {
                    $futureMovementsByTransaction[(int)$movement['transaction_id']] = $movement;
                }
            }
            foreach ((array)($period['unsupported_movements'] ?? []) as $movement) {
                if (!is_array($movement)) {
                    continue;
                }
                $this->appendItem($items, [
                    'kind' => 'unsupported_movement',
                    'state' => 'requires_action',
                    'title' => 'Unsupported participator-loan journal movement',
                    'detail' => trim((string)($movement['description'] ?? 'Manual loan-control movement')) . ' on ' . (string)($movement['journal_date'] ?? '') . ' is not transaction-backed cash evidence.',
                    'source_label' => (string)($movement['source_label'] ?? ''),
                    'source_url' => (string)($movement['source_url'] ?? ''),
                    'action_label' => 'Open source journal',
                    'action_url' => (string)($movement['source_url'] ?? ''),
                ]);
            }
            foreach ((array)($period['errors'] ?? []) as $error) {
                $message = (string)$error;
                if (str_contains($message, 'not linked to a confirmed ownership party')) {
                    continue;
                }
                if (str_contains($message, 'non-cash or unsupported loan movement')
                    && !empty($period['unsupported_movements'])) {
                    continue;
                }
                $this->appendItem($items, [
                    'kind' => str_contains($message, 'non-cash or unsupported loan movement')
                        ? 'unsupported_movement'
                        : 's455_evidence',
                    'state' => 'requires_action',
                    'title' => str_contains($message, 'non-cash or unsupported loan movement')
                        ? 'Unsupported participator-loan journal movement'
                        : 's455 evidence requires review',
                    'detail' => $message,
                    'source_label' => 'Loans tax evidence',
                    'source_url' => '?page=loans&show_card=director_loan_s455',
                    'action_label' => 'Review loan tax evidence',
                    'action_url' => '?page=loans&show_card=director_loan_s455',
                ]);
            }
        }

        $futureMovements = array_values($futureMovementsByTransaction);
        usort($futureMovements, static fn(array $left, array $right): int => [
            (string)($left['txn_date'] ?? ''), (int)($left['transaction_id'] ?? 0),
        ] <=> [
            (string)($right['txn_date'] ?? ''), (int)($right['transaction_id'] ?? 0),
        ]);
        $futureBasis = $this->futureAttributionBasis($futureMovements);
        $ct600a = (new Ct600aService())->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        $ct600aTaxRelevant = false;
        foreach ((array)($ct600a['periods'] ?? []) as $ctPeriod) {
            if ((float)($ctPeriod['part1']['total_loans'] ?? 0) > 0
                || (float)($ctPeriod['tax_payable'] ?? 0) > 0) {
                $ct600aTaxRelevant = true;
            }
            foreach ((array)($ctPeriod['blocking_errors'] ?? []) as $error) {
                $message = trim((string)$error);
                if ($message === ''
                    || str_contains($message, 'Complete and approve the section 464A review')
                    || str_contains($message, 'require party attribution')
                    || str_contains($message, 'non-cash or unsupported loan movement')) {
                    continue;
                }
                $this->appendItem($items, [
                    'kind' => 'ct600a_evidence',
                    'state' => 'requires_action',
                    'title' => 'CT600A evidence requires review',
                    'detail' => $message,
                    'source_label' => 'CT600A review',
                    'source_url' => '?page=loans&show_card=director_loan_ct600a',
                    'action_label' => 'Review CT600A evidence',
                    'action_url' => '?page=loans&show_card=director_loan_ct600a',
                ]);
            }
        }
        $ct600aTaxRelevant = $ct600aTaxRelevant || $statementTaxRelevant;
        $ct600aReview = (array)($ct600a['review'] ?? []);
        if ($ct600aTaxRelevant
            && (empty($ct600a['available'])
                || empty($ct600aReview['current'])
                || empty($ct600aReview['complete']))) {
            $reviewErrors = array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge(
                    (array)($ct600a['errors'] ?? []),
                    (array)($ct600aReview['errors'] ?? [])
                )
            ))));
            $this->appendItem($items, [
                'kind' => 'section_464a_review',
                'state' => !empty($ct600aReview['stored']) && empty($ct600aReview['current'])
                    ? 'stale'
                    : 'requires_action',
                'title' => 'Section 464A and 464C review is incomplete',
                'detail' => $reviewErrors === []
                    ? 'Complete the Section 464A and 464C declaration for the current participator-loan evidence.'
                    : implode(' ', $reviewErrors),
                'source_label' => 'CT600A declaration',
                'source_url' => '?page=loans&show_card=director_loan_ct600a',
                'action_label' => 'Complete tax review',
                'action_url' => '?page=loans&show_card=director_loan_ct600a',
            ]);
        }
        $hasUnresolvedReview = $items !== [];
        $taxStatus = $missingTermsCount > 0
            ? 'terms_required'
            : ($ct600aTaxRelevant
                ? ($hasUnresolvedReview ? 'review_required' : 'reviewed_exposure')
                : 'no_exposure');
        $taxStatusLabel = match ($taxStatus) {
            'terms_required' => 'Terms required',
            'review_required' => 'Review required',
            'reviewed_exposure' => 'Reviewed — exposure recorded',
            default => 'No exposure flagged',
        };
        $s455Ready = !$ct600aTaxRelevant || $this->s455Ready($s455);
        $ct600aReady = !$ct600aTaxRelevant
            || (!empty($ct600a['available'])
                && !empty($ct600aReview['current'])
                && !empty($ct600aReview['complete']));
        $acknowledgements = new YearEndAcknowledgementService();
        $warningStatus = $futureMovements === []
            ? ['state' => 'not_applicable', 'current' => false, 'acknowledgement' => null]
            : $acknowledgements->evaluate(
                $acknowledgements->fetch($companyId, $accountingPeriodId, self::FUTURE_ATTRIBUTION_WARNING_CODE),
                $futureBasis
            );

        return [
            'available' => true,
            'errors' => [],
            'items' => $items,
            'unresolved_count' => count($items),
            'missing_terms_count' => $missingTermsCount,
            'tax_status' => $taxStatus,
            'tax_status_code' => $taxStatus,
            'tax_status_label' => $taxStatusLabel,
            'tax_review' => [
                'status' => $taxStatus,
                'status_code' => $taxStatus,
                'status_label' => $taxStatusLabel,
                'review_required' => in_array($taxStatus, ['terms_required', 'review_required'], true),
                'potential_s455_exposure' => round((float)($statement['potential_s455_exposure'] ?? 0), 2),
                's455_ready' => $s455Ready,
                'ct600a_ready' => $ct600aReady,
            ],
            'future_attribution_warning' => [
                'movements' => $futureMovements,
                'count' => count($futureMovements),
                'basis' => $futureBasis,
                'acknowledgement_state' => (string)($warningStatus['state'] ?? 'absent'),
                'acknowledged' => !empty($warningStatus['current']),
                'tax_relevant' => $ct600aTaxRelevant,
            ],
        ];
    }

    private function s455Ready(array $s455): bool
    {
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

    /** @return array<int,array{party_name:string,terms_saved?:bool}> */
    private function relevantParties(array $statement): array
    {
        $parties = [];
        foreach ((array)($statement['party_facts'] ?? []) as $position) {
            $partyId = (int)($position['party_id'] ?? $position['director_id'] ?? 0);
            if ($partyId <= 0
                || (empty($position['has_period_movement'])
                    && empty($position['has_closing_position']))) {
                continue;
            }
            $parties[$partyId] = [
                'party_name' => trim((string)($position['party_name'] ?? $position['director_name'] ?? ''))
                    ?: 'Participator #' . $partyId,
                'terms_saved' => !empty($position['terms_saved']),
            ];
        }
        foreach ((array)($statement['statement_rows'] ?? []) as $row) {
            $partyId = (int)($row['party_id'] ?? $row['director_id'] ?? 0);
            if ($partyId > 0 && !array_key_exists($partyId, $parties)) {
                $parties[$partyId] = [
                    'party_name' => trim((string)($row['party_name'] ?? $row['director_name'] ?? ''))
                        ?: 'Participator #' . $partyId,
                ];
            }
        }
        foreach ((array)($statement['per_director'] ?? []) as $position) {
            $partyId = (int)($position['party_id'] ?? $position['director_id'] ?? 0);
            if ($partyId <= 0
                || (abs((float)($position['gross_asset'] ?? 0)) < 0.005
                    && abs((float)($position['gross_liability'] ?? 0)) < 0.005
                    && !array_key_exists($partyId, $parties))) {
                continue;
            }
            $parties[$partyId] = [
                'party_name' => trim((string)($position['party_name'] ?? $position['director_name'] ?? ''))
                    ?: 'Participator #' . $partyId,
            ] + ($parties[$partyId] ?? []);
        }
        ksort($parties);
        return $parties;
    }

    /** @param list<array<string,mixed>> $items */
    private function appendItem(array &$items, array $item): void
    {
        $key = (string)($item['kind'] ?? '') . '|' . (string)($item['detail'] ?? '');
        foreach ($items as $existing) {
            if ((string)($existing['kind'] ?? '') . '|' . (string)($existing['detail'] ?? '') === $key) {
                return;
            }
        }
        $items[] = $item;
    }

    public function acknowledgeFutureAttributionWarning(
        int $companyId,
        int $accountingPeriodId,
        string $actor
    ): array {
        $review = $this->fetch($companyId, $accountingPeriodId);
        $warning = (array)($review['future_attribution_warning'] ?? []);
        if ((int)($warning['count'] ?? 0) <= 0) {
            return ['success' => false, 'errors' => ['There are no future repayment-attribution warnings to acknowledge.']];
        }

        return (new YearEndAcknowledgementService())->save(
            $companyId,
            $accountingPeriodId,
            self::FUTURE_ATTRIBUTION_WARNING_CODE,
            (array)($warning['basis'] ?? []),
            $actor,
            'Future repayment transactions are not being relied on to reduce the current s455 position.'
        );
    }

    /** @param list<array<string,mixed>> $movements */
    private function futureAttributionBasis(array $movements): array
    {
        return [
            'version' => 'loan-future-repayment-attribution-v1',
            'movements' => array_map(static fn(array $movement): array => [
                'transaction_id' => (int)($movement['transaction_id'] ?? 0),
                'accounting_period_id' => (int)($movement['accounting_period_id'] ?? 0),
                'txn_date' => (string)($movement['txn_date'] ?? ''),
                'amount' => round((float)($movement['amount'] ?? 0), 2),
                'cash_direction' => (string)($movement['cash_direction'] ?? ''),
            ], $movements),
        ];
    }
}
