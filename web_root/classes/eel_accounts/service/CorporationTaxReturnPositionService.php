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
 * Canonical Corporation Tax liability read model.
 *
 * Ordinary Corporation Tax (CT600 box 475) and CT600A A80 (CT600 box 480)
 * are kept separate and are added exactly once. Net s455 remains diagnostic
 * evidence within A80 and is never an additional amount payable.
 */
final class CorporationTaxReturnPositionService
{
    public const MODEL_VERSION = 'corporation-tax-return-position-v1';

    public function __construct(
        private readonly ?CorporationTaxComputationService $computationService = null,
        private readonly ?Ct600aService $ct600aService = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function fetchForCtPeriod(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        ?array $precomputedSummary = null,
        ?array $precomputedCt600a = null,
        ?string $asOf = null
    ): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId === 0) {
            return $this->unavailable('Select a valid CT period.');
        }

        $summary = $precomputedSummary;
        $ct600a = $precomputedCt600a;
        $filingScope = null;
        $source = 'live';
        $filingBasisIdentifiers = [
            'filing_basis_id' => null,
            'filing_approval_id' => null,
            'computation_run_id' => null,
            'basis_version' => null,
            'basis_hash' => null,
        ];
        $period = $ctPeriodId > 0
            ? (new CorporationTaxPeriodService())->fetch($companyId, $ctPeriodId)
            : null;
        if ($period !== null && (int)($period['accounting_period_id'] ?? 0) !== $accountingPeriodId) {
            return $this->unavailable('The selected CT period does not belong to this accounting period.');
        }

        $immutable = $period !== null
            && in_array((string)($period['status'] ?? ''), ['submitted', 'accepted'], true);
        if ($immutable) {
            $frozen = (new CtPeriodFilingModelService())->build($companyId, $accountingPeriodId, $ctPeriodId);
            if (empty($frozen['available'])) {
                return $this->unavailable((string)(($frozen['errors'] ?? [])[0]
                    ?? 'The submitted Corporation Tax return has no valid immutable filing basis.'));
            }
            $model = (array)($frozen['model'] ?? []);
            $summary = (array)(($model['computation'] ?? [])['summary'] ?? []);
            $ct600a = (array)($model['ct600a'] ?? []);
            $filingScope = (array)($model['corporation_tax_filing_scope'] ?? []);
            $run = (array)($frozen['run'] ?? []);
            $filingBasisIdentifiers = [
                'filing_basis_id' => (int)($run['id'] ?? 0) ?: null,
                'filing_approval_id' => (int)($run['filing_approval_id'] ?? 0) ?: null,
                'computation_run_id' => (int)($run['computation_run_id'] ?? $run['run_id'] ?? 0) ?: null,
                'basis_version' => (string)($frozen['basis_version'] ?? '') ?: null,
                'basis_hash' => (string)($frozen['basis_hash'] ?? '') ?: null,
            ];
            $source = 'immutable_filing_basis';
        }

        $computation = $this->computationService ?? new CorporationTaxComputationService();
        $summary ??= $computation->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
        if (empty($summary['available']) && $source !== 'immutable_filing_basis') {
            return $this->unavailable((string)(($summary['errors'] ?? [])[0] ?? 'The ordinary Corporation Tax calculation is unavailable.'));
        }

        if ($ct600a === null) {
            $ct600aService = $this->ct600aService ?? new Ct600aService();
            $ct600a = $ct600aService->build($companyId, $accountingPeriodId, $ctPeriodId, $asOf);
        }
        if (empty($ct600a['available']) && $source !== 'immutable_filing_basis') {
            return $this->unavailable((string)(($ct600a['errors'] ?? [])[0] ?? 'The CT600A calculation is unavailable.'));
        }

        return $this->fromModels(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $summary,
            $ct600a,
            $source,
            $filingScope,
            $filingBasisIdentifiers
        );
    }

    /**
     * Pure composition boundary used by tests and by consumers that already
     * hold computation and CT600A evidence.
     *
     * @return array<string,mixed>
     */
    public function fromModels(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        array $summary,
        array $ct600a,
        string $source = 'precomputed',
        ?array $precomputedFilingScope = null,
        array $filingBasisIdentifiers = []
    ): array {
        $ordinaryTax = $this->money($summary['ordinary_corporation_tax'] ?? $summary['estimated_corporation_tax'] ?? 0);
        $ct600aS455 = (array)($ct600a['s455'] ?? []);
        $netS455 = $this->money(array_key_exists('net_tax', $ct600aS455)
            ? $ct600aS455['net_tax']
            : ($summary['s455_tax'] ?? 0));
        $a15 = $this->money(($ct600a['part1'] ?? [])['total_loans'] ?? 0);
        $a20 = $this->money(($ct600a['part1'] ?? [])['tax_chargeable'] ?? 0);
        $a45 = $this->money(($ct600a['part2'] ?? [])['relief_due'] ?? 0);
        $a70 = $this->money(($ct600a['part3'] ?? [])['relief_due'] ?? 0);
        $a75 = $this->money($ct600a['total_loans_outstanding'] ?? 0);
        $a80 = $this->money($ct600a['tax_payable'] ?? 0);
        $taxChargeable = $this->money($ordinaryTax + $a80);
        $blocking = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($ct600a['blocking_errors'] ?? [])
        ), static fn(string $message): bool => trim($message) !== '')));
        foreach ((array)($summary['hard_gate_diagnostics'] ?? []) as $diagnostic) {
            $message = is_array($diagnostic)
                ? (string)($diagnostic['message'] ?? $diagnostic['detail'] ?? $diagnostic['code'] ?? '')
                : (string)$diagnostic;
            if ($message !== '') {
                $blocking[] = $message;
            }
        }
        $scope = $precomputedFilingScope ?? (new CorporationTaxFilingScopeService())->fetch($companyId, $accountingPeriodId);
        if ($precomputedFilingScope !== null && !array_key_exists('complete', $scope)) {
            $answers = (array)($scope['answers'] ?? []);
            $scope['available'] = true;
            $scope['complete'] = $answers !== [] && !in_array(true, array_map(
                static fn(mixed $answer): bool => (string)$answer !== 'no',
                $answers
            ), true);
            $scope['errors'] = [];
        }
        if (empty($scope['available'])) {
            foreach ((array)($scope['errors'] ?? ['The Corporation Tax filing scope is unavailable.']) as $error) {
                $blocking[] = (string)$error;
            }
        } elseif (empty($scope['complete'])) {
            foreach ((array)($scope['errors'] ?? []) as $error) {
                $blocking[] = (string)$error;
            }
        }
        $blocking = array_values(array_unique($blocking));
        $payment = (new HmrcObligationService())->fetchCtPaymentPositionForAccountingPeriod(
            $companyId,
            $accountingPeriodId
        );
        $complete = !empty($ct600a['complete']) && !empty($scope['complete']) && $blocking === [];
        $provisional = !$complete
            || (string)($ct600a['s455']['window_status'] ?? $ct600a['s455']['basis']['window_status'] ?? '') === 'provisional_window_open'
            || !empty($summary['provisional']);

        $position = array_merge($summary, [
            'available' => true,
            'return_position_model_version' => self::MODEL_VERSION,
            'source' => $source,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'ordinary_corporation_tax' => $ordinaryTax,
            's455_tax' => $netS455,
            'ct600a_tax' => $a80,
            'tax_chargeable' => $taxChargeable,
            'tax_payable' => $taxChargeable,
            // Compatibility name retained for existing presentation consumers.
            'estimated_corporation_tax' => $taxChargeable,
            'provision_amount' => $taxChargeable,
            'ct600_boxes' => [
                '475' => $ordinaryTax,
                '480' => $a80,
                '510' => $taxChargeable,
                '525' => $taxChargeable,
            ],
            'ct600a_amounts' => [
                'A15' => $a15,
                'A20' => $a20,
                'A45' => $a45,
                'A70' => $a70,
                'A75' => $a75,
                'A80' => $a80,
            ],
            'ct600a' => $ct600a,
            'hmrc_payment' => $payment,
            'amount_paid' => $this->money($payment['amount_paid'] ?? 0),
            // A payment is recorded for the accounting period, not allocated
            // to an individual CT return. The accounting-period aggregate
            // calculates the amount still payable.
            'payment_outstanding' => null,
            'scope_complete' => !empty($scope['complete']),
            'complete' => $complete,
            'provisional' => $provisional,
            'position_status' => $complete ? 'complete' : 'provisional',
            'blocking_errors' => $blocking,
            'filing_basis_identifiers' => array_replace([
                'filing_basis_id' => null,
                'filing_approval_id' => null,
                'computation_run_id' => null,
                'basis_version' => null,
                'basis_hash' => null,
            ], $filingBasisIdentifiers),
            'evidence_hashes' => [
                'computation' => (string)($summary['computation_hash'] ?? $summary['basis_hash'] ?? ''),
                'ct600a' => (string)($ct600a['basis_hash'] ?? ''),
                'ct600a_review' => (string)(($ct600a['review'] ?? [])['basis_hash'] ?? ''),
                'filing_scope' => (string)($scope['basis_hash'] ?? ''),
            ],
        ]);
        $hashBasis = $position;
        unset($hashBasis['hmrc_payment'], $hashBasis['amount_paid'], $hashBasis['payment_outstanding']);
        $position['basis_hash'] = hash('sha256', $this->canonicalJson($hashBasis));

        return $position;
    }

    /** @return array<string,mixed> */
    public function fetchForAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
        ?array $precomputedPeriodSummaries = null,
        ?string $asOf = null
    ): array {
        $computation = $this->computationService ?? new CorporationTaxComputationService();
        $active = $computation->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
        $periods = (array)($active['periods'] ?? []);
        if ($periods === []) {
            return $this->unavailable((string)(($active['errors'] ?? [])[0] ?? 'No CT periods are available.')) + ['periods' => []];
        }

        $precomputed = [];
        foreach ($precomputedPeriodSummaries ?? [] as $summary) {
            if (is_array($summary) && (int)($summary['ct_period_id'] ?? 0) !== 0) {
                $precomputed[(int)$summary['ct_period_id']] = $summary;
            }
        }
        $positions = [];
        $errors = [];
        foreach ($periods as $period) {
            $ctPeriodId = (int)($period['id'] ?? 0);
            if ($ctPeriodId === 0) {
                continue;
            }
            $known = $precomputed[$ctPeriodId] ?? null;
            if (is_array($known) && (string)($known['return_position_model_version'] ?? '') === self::MODEL_VERSION) {
                $position = $known;
            } else {
                $knownCt600a = is_array($known) && is_array($known['ct600a'] ?? null)
                    ? (array)$known['ct600a']
                    : null;
                $position = $this->fetchForCtPeriod(
                    $companyId,
                    $accountingPeriodId,
                    $ctPeriodId,
                    $known,
                    $knownCt600a,
                    $asOf
                );
            }
            if (empty($position['available'])) {
                foreach ((array)($position['errors'] ?? []) as $error) {
                    $errors[] = (string)$error;
                }
                continue;
            }
            $positions[] = array_merge($position, [
                'period_start' => (string)($period['period_start'] ?? $position['period_start'] ?? ''),
                'period_end' => (string)($period['period_end'] ?? $position['period_end'] ?? ''),
                'sequence_no' => (int)($period['sequence_no'] ?? 0),
            ]);
        }
        if ($positions === []) {
            return $this->unavailable($errors[0] ?? 'No Corporation Tax return positions could be calculated.') + ['periods' => []];
        }

        $payment = (new HmrcObligationService())->fetchCtPaymentPositionForAccountingPeriod($companyId, $accountingPeriodId);
        $l2pRelief = ($this->ct600aService ?? new Ct600aService())
            ->fetchL2pReliefForAccountingPeriod($companyId, $accountingPeriodId, $asOf);

        return $this->aggregatePositions(
            $companyId,
            $accountingPeriodId,
            $positions,
            $payment,
            $l2pRelief,
            $errors
        );
    }

    /**
     * Build a current, explicitly provisional accounting-period position for
     * management reporting before CT-period facts have been confirmed. The
     * ordinary estimate remains accounting-period based, while CT600A is
     * still derived independently for every statutory CT period and A80 is
     * added exactly once.
     *
     * This method is not a filing or freeze basis.
     *
     * @return array<string,mixed>
     */
    public function fetchCurrentAccountingPeriodEstimate(
        int $companyId,
        int $accountingPeriodId,
        ?array $accountingPeriod = null,
        ?array $profitAndLoss = null,
        ?string $asOf = null
    ): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->unavailable('Select a company and accounting period.');
        }

        $computation = $this->computationService ?? new CorporationTaxComputationService();
        $ordinary = $computation->fetchCurrentPeriodEstimate(
            $companyId,
            $accountingPeriodId,
            $accountingPeriod,
            $profitAndLoss
        );
        if (empty($ordinary['available'])) {
            return $this->unavailable((string)(($ordinary['errors'] ?? [])[0]
                ?? 'The ordinary Corporation Tax estimate is unavailable.'));
        }

        $active = $computation->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
        $periods = (array)($active['periods'] ?? []);
        if ($periods === []) {
            return $this->unavailable((string)(($active['errors'] ?? [])[0]
                ?? 'No CT periods are available.'));
        }

        $ct600aService = $this->ct600aService ?? new Ct600aService();
        $models = [];
        $blocking = [];
        $warnings = [];
        foreach ($periods as $period) {
            $ctPeriodId = (int)($period['id'] ?? 0);
            if ($ctPeriodId === 0) {
                return $this->unavailable('A projected CT period has no stable reference.');
            }
            $model = $ct600aService->build(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $asOf
            );
            if (empty($model['available'])) {
                return $this->unavailable((string)(($model['errors'] ?? [])[0]
                    ?? 'The CT600A estimate is unavailable.'));
            }
            $models[] = $model;
            $blocking = array_merge($blocking, (array)($model['blocking_errors'] ?? []));
            $warnings = array_merge($warnings, (array)($model['evidence_warnings'] ?? []));
        }

        $last = (array)end($models);
        $syntheticCt600a = [
            'available' => true,
            'model_version' => Ct600aService::MODEL_VERSION,
            'required' => in_array(true, array_map(
                static fn(array $model): bool => !empty($model['required']),
                $models
            ), true),
            'part1' => [
                'rows' => [],
                'total_loans' => $this->sumNested($models, ['part1', 'total_loans']),
                'tax_chargeable' => $this->sumNested($models, ['part1', 'tax_chargeable']),
            ],
            'part2' => [
                'rows' => [],
                'relief_due' => $this->sumNested($models, ['part2', 'relief_due']),
            ],
            'part3' => [
                'rows' => [],
                'relief_due' => $this->sumNested($models, ['part3', 'relief_due']),
            ],
            // A75 is a balance at a point in time, so use the final CT period
            // rather than summing carried balances across returns.
            'total_loans_outstanding' => $this->money($last['total_loans_outstanding'] ?? 0),
            'tax_payable' => $this->money(array_sum(array_map(
                static fn(array $model): float => (float)($model['tax_payable'] ?? 0),
                $models
            ))),
            's455' => [
                'net_tax' => $this->money(array_sum(array_map(
                    static fn(array $model): float => (float)(($model['s455'] ?? [])['net_tax'] ?? 0),
                    $models
                ))),
                'window_status' => in_array('provisional_window_open', array_map(
                    static fn(array $model): string => (string)(($model['s455'] ?? [])['window_status'] ?? ''),
                    $models
                ), true) ? 'provisional_window_open' : 'window_complete',
            ],
            'blocking_errors' => array_values(array_unique(array_map('strval', $blocking))),
            'evidence_warnings' => array_values(array_unique(array_map('strval', $warnings))),
            'complete' => !in_array(false, array_map(
                static fn(array $model): bool => !empty($model['complete']),
                $models
            ), true),
            'basis_hash' => hash('sha256', $this->canonicalJson(array_map(
                static fn(array $model): string => (string)($model['basis_hash'] ?? ''),
                $models
            ))),
        ];

        $position = $this->fromModels(
            $companyId,
            $accountingPeriodId,
            0,
            $ordinary,
            $syntheticCt600a,
            'live_accounting_period_estimate'
        );
        $payment = (array)($position['hmrc_payment'] ?? []);
        $amountPaid = $this->money($payment['amount_paid'] ?? 0);
        $l2pRelief = $ct600aService->fetchL2pReliefForAccountingPeriod(
            $companyId,
            $accountingPeriodId,
            $asOf
        );
        $reliefReceivable = !empty($l2pRelief['available'])
            ? $this->money($l2pRelief['relief_receivable'] ?? 0)
            : 0.0;
        $position['periods'] = $models;
        $position['amount_paid'] = $amountPaid;
        $position['payment_outstanding'] = !empty($payment['available'])
            ? $this->money(max(0.0, (float)$position['tax_payable'] - $amountPaid))
            : null;
        $position['l2p_relief'] = $l2pRelief;
        $position['l2p_relief_receivable'] = $reliefReceivable;
        $position['estimated_tax_charge'] = $this->money(
            (float)$position['tax_payable'] - $reliefReceivable
        );
        $position['complete'] = false;
        $position['provisional'] = true;
        $position['position_status'] = 'provisional';

        return $position;
    }

    /**
     * Pure accounting-period aggregation boundary.
     *
     * @return array<string,mixed>
     */
    public function aggregatePositions(
        int $companyId,
        int $accountingPeriodId,
        array $positions,
        array $payment = [],
        array $l2pRelief = [],
        array $errors = []
    ): array {
        $ordinary = $this->sum($positions, 'ordinary_corporation_tax');
        $s455 = $this->sum($positions, 's455_tax');
        $ct600a = $this->sum($positions, 'ct600a_tax');
        $payable = $this->money($ordinary + $ct600a);
        $amountPaid = $this->money($payment['amount_paid'] ?? 0);
        $paymentOutstanding = !empty($payment['available'])
            ? $this->money(max(0.0, $payable - $amountPaid))
            : null;
        $reliefReceivable = !empty($l2pRelief['available'])
            ? $this->money($l2pRelief['relief_receivable'] ?? 0)
            : 0.0;
        $complete = $positions !== [] && $errors === [] && !in_array(false, array_map(
            static fn(array $position): bool => !empty($position['complete']),
            $positions
        ), true);
        $provisional = !$complete || in_array(true, array_map(
            static fn(array $position): bool => !empty($position['provisional']),
            $positions
        ), true);

        return [
            'available' => $positions !== [] && $errors === [],
            'errors' => array_values(array_unique(array_map('strval', $errors))),
            'return_position_model_version' => self::MODEL_VERSION,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'periods' => $positions,
            'ordinary_corporation_tax' => $ordinary,
            's455_tax' => $s455,
            'ct600a_tax' => $ct600a,
            'tax_chargeable' => $payable,
            'tax_payable' => $payable,
            'estimated_corporation_tax' => $payable,
            'provision_amount' => $payable,
            'l2p_relief' => $l2pRelief,
            'l2p_relief_receivable' => $reliefReceivable,
            'estimated_tax_charge' => $this->money($payable - $reliefReceivable),
            'hmrc_payment' => $payment,
            'amount_paid' => $amountPaid,
            'payment_outstanding' => $paymentOutstanding,
            'complete' => $complete,
            'provisional' => $provisional,
            'position_status' => $complete ? 'complete' : 'provisional',
            'filing_basis_identifiers' => array_values(array_map(
                static fn(array $position): array => (array)($position['filing_basis_identifiers'] ?? []),
                $positions
            )),
        ];
    }

    /** @return array<string,mixed> */
    private function unavailable(string $message): array
    {
        return [
            'available' => false,
            'complete' => false,
            'provisional' => true,
            'position_status' => 'unavailable',
            'errors' => [$message],
            'return_position_model_version' => self::MODEL_VERSION,
        ];
    }

    private function sum(array $rows, string $key): float
    {
        return $this->money(array_sum(array_map(
            static fn(array $row): float => (float)($row[$key] ?? 0),
            $rows
        )));
    }

    /** @param list<string> $path */
    private function sumNested(array $rows, array $path): float
    {
        return $this->money(array_sum(array_map(static function (array $row) use ($path): float {
            $value = $row;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return 0.0;
                }
                $value = $value[$key];
            }
            return (float)$value;
        }, $rows)));
    }

    private function money(mixed $value): float
    {
        return round((float)$value, 2);
    }

    private function canonicalJson(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };

        return (string)json_encode(
            $sort($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }
}
