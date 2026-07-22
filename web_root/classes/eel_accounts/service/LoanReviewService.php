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
        foreach ((array)($statement['attribution_entries'] ?? []) as $entry) {
            if (!is_array($entry) || (int)($entry['director_id'] ?? 0) > 0) {
                continue;
            }
            $items[] = [
                'kind' => 'party_attribution',
                'state' => 'requires_action',
                'title' => 'Participator loan movement needs a party',
                'detail' => trim((string)($entry['description'] ?? 'Loan movement')) . ' on ' . (string)($entry['journal_date'] ?? ''),
                'source_label' => (string)($entry['source_label'] ?? ('Journal #' . (int)($entry['journal_id'] ?? 0))),
                'source_url' => (string)($entry['source_url'] ?? ''),
                'action_label' => 'Assign participant',
                'action_url' => '?page=loans&show_card=director_loan_attribution&director_loan_attribution_filter=requires_assignment',
            ];
        }

        $s455 = (new S455ReviewService())->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        foreach ((array)($s455['periods'] ?? []) as $period) {
            foreach ((array)($period['unattributed_movements'] ?? []) as $movement) {
                if (!is_array($movement)) {
                    continue;
                }
                $items[] = [
                    'kind' => 'party_attribution',
                    'state' => 'requires_action',
                    'title' => 'Participator loan transaction needs a party',
                    'detail' => 'Transaction #' . (int)($movement['transaction_id'] ?? 0) . ' on ' . (string)($movement['txn_date'] ?? '') . ' is part of the s455 evidence window but has no confirmed ownership party.',
                    'source_label' => (string)($movement['source_label'] ?? ''),
                    'source_url' => (string)($movement['source_url'] ?? ''),
                    'action_label' => 'Assign participant',
                    'action_url' => (string)($movement['action_url'] ?? $movement['source_url'] ?? ''),
                ];
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
                $items[] = [
                    'kind' => 'unsupported_movement',
                    'state' => 'requires_action',
                    'title' => 'Unsupported participator-loan journal movement',
                    'detail' => trim((string)($movement['description'] ?? 'Manual loan-control movement')) . ' on ' . (string)($movement['journal_date'] ?? '') . ' is not transaction-backed cash evidence.',
                    'source_label' => (string)($movement['source_label'] ?? ''),
                    'source_url' => (string)($movement['source_url'] ?? ''),
                    'action_label' => 'Open source journal',
                    'action_url' => (string)($movement['source_url'] ?? ''),
                ];
            }
            foreach ((array)($period['errors'] ?? []) as $error) {
                $message = (string)$error;
                if (str_contains($message, 'not linked to a confirmed ownership party')) {
                    continue;
                }
                if (!str_contains($message, 'non-cash or unsupported loan movement')
                    || !empty($period['unsupported_movements'])) {
                    continue;
                }
                $items[] = [
                    'kind' => 'unsupported_movement',
                    'state' => 'requires_action',
                    'title' => 'Unsupported participator-loan journal movement',
                    'detail' => $message,
                    'source_label' => 'Loans tax evidence',
                    'source_url' => '?page=loans&show_card=director_loan_s455',
                    'action_label' => 'Review loan tax evidence',
                    'action_url' => '?page=loans&show_card=director_loan_s455',
                ];
            }
        }

        $ct600a = (new Ct600aService())->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        $review = (array)($ct600a['review'] ?? []);
        if (empty($review['current']) || empty($review['complete'])) {
            $items[] = [
                'kind' => 'section_464a_review',
                'state' => !empty($review['stored']) ? 'stale' : 'requires_action',
                'title' => !empty($review['stored'])
                    ? 'Section 464A and 464C declaration needs reviewing again'
                    : 'Section 464A and 464C declaration is required',
                'detail' => !empty($review['stored'])
                    ? 'The six-question declaration on Loans → Year End Confirmation was saved previously, but relied-on loan evidence has since changed. Check its answers and approve the refreshed declaration.'
                    : 'Complete the six-question declaration on Loans → Year End Confirmation before confirming the year-end loan position.',
                'source_label' => 'HMRC Section 464A review',
                'source_url' => 'https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm61570',
                'action_label' => 'Review the declaration',
                'action_url' => '?page=loans&show_card=year_end_loan_confirmation',
            ];
        }

        $futureMovements = array_values($futureMovementsByTransaction);
        usort($futureMovements, static fn(array $left, array $right): int => [
            (string)($left['txn_date'] ?? ''), (int)($left['transaction_id'] ?? 0),
        ] <=> [
            (string)($right['txn_date'] ?? ''), (int)($right['transaction_id'] ?? 0),
        ]);
        $futureBasis = $this->futureAttributionBasis($futureMovements);
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
            'future_attribution_warning' => [
                'movements' => $futureMovements,
                'count' => count($futureMovements),
                'basis' => $futureBasis,
                'acknowledgement_state' => (string)($warningStatus['state'] ?? 'absent'),
                'acknowledged' => !empty($warningStatus['current']),
            ],
        ];
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
