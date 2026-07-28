<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Versioned presentation and semantic-fact profile for HMRC CT computations.
 *
 * This profile deliberately operates on the immutable filing basis.  It never
 * changes the CT calculation: it only selects the correct whole-period
 * accounts facts and adds the prescribed Format 1.1 presentation facts.
 */
final class HmrcCtComputationReportProfile
{
    public const VERSION = 'hmrc-ct-computations-format-1.1/loss-and-allowance-tagging-v1';

    /**
     * Visible support rows intentionally left as text or display-only values.
     * The exact CT Computation 2024 concept is either absent or would duplicate
     * a separately tagged aggregate fact.  This controlled list is surfaced in
     * the report model for audit rather than silently omitting semantic detail.
     *
     * @var array<string,array{taxonomy_version:string,reason:string}>
     */
    private const UNTAGGED_ROW_ALLOWLIST = [
        'time_apportionment_figure' => [
            'taxonomy_version' => 'HMRC CT Computation 2024',
            'reason' => 'The prescribed time-apportionment working has no exact standalone taxonomy concept.',
        ],
        'pre_2017_trading_losses' => [
            'taxonomy_version' => 'HMRC CT Computation 2024',
            'reason' => 'This visible zero-value comparison row is supporting disclosure; no applicable AP79 pre-2017 loss fact exists.',
        ],
        'loss_restriction_result' => [
            'taxonomy_version' => 'HMRC CT Computation 2024',
            'reason' => 'The numeric calculated restriction is tagged; the rendered “None” result is explanatory text, not a separate fact.',
        ],
        'aia_asset_allocation' => [
            'taxonomy_version' => 'HMRC CT Computation 2024',
            'reason' => 'The taxonomy provides aggregate main-pool allowance facts but no exact per-asset allocation concept.',
        ],
        'main_pool_working_values' => [
            'taxonomy_version' => 'HMRC CT Computation 2024',
            'reason' => 'AIA limit, intermediate pool balances and the published WDA rate are calculation workings; tagged aggregate pool facts carry the reportable values.',
        ],
    ];

    /**
     * @param list<array<string,mixed>> $mappings
     * @return array{mappings:list<array<string,mixed>>,accounts_adjustment_rows:list<array<string,mixed>>,main_pool_rows:list<array<string,mixed>>,loss_schedule_rows:list<array<string,mixed>>,untagged_row_allowlist:array<string,array{taxonomy_version:string,reason:string}>,format_version:string}
     */
    public function apply(array $filing, array $mappings): array
    {
        $summary = (array)($filing['model']['computation']['summary'] ?? []);
        $allocation = (array)($summary['accounting_allocation_basis'] ?? []);
        if (empty($allocation['time_apportioned'])) {
            return [
                'mappings' => $mappings,
                'accounts_adjustment_rows' => [],
                'main_pool_rows' => [],
                'loss_schedule_rows' => [],
                'untagged_row_allowlist' => self::UNTAGGED_ROW_ALLOWLIST,
                'format_version' => self::VERSION,
            ];
        }

        $whole = (array)($allocation['whole_period_values'] ?? []);
        $requiredWholeValues = [
            'accounting_profit', 'disallowable_add_backs', 'capital_add_backs',
            'depreciation_add_back', 'adjusted_result_before_capital_allowances',
        ];
        foreach ($requiredWholeValues as $key) {
            if (!array_key_exists($key, $whole) || !is_numeric($whole[$key])) {
                throw new \RuntimeException('The frozen long-period filing basis is missing whole-period value ' . $key . '.');
            }
        }
        foreach (['accounting_period_days', 'ct_period_days'] as $key) {
            if ((int)($allocation[$key] ?? 0) <= 0) {
                throw new \RuntimeException('The frozen long-period filing basis is missing valid ' . $key . '.');
            }
        }

        $adjustedWholePeriod = $this->money($whole['adjusted_result_before_capital_allowances']);
        $allocated = (array)($allocation['allocated_values'] ?? []);
        $apportioned = $this->money($allocated['adjusted_result_before_capital_allowances'] ?? null);
        $capitalAllowances = $this->money($summary['capital_allowances'] ?? null);
        $finalResult = $this->money($summary['taxable_before_losses'] ?? null);
        if (abs($apportioned - ($finalResult + $capitalAllowances)) > 0.009) {
            throw new \RuntimeException('The frozen long-period adjusted result does not reconcile to capital allowances and the final adjusted result.');
        }

        $byKey = [];
        foreach ($mappings as $mapping) {
            $byKey[(string)($mapping['canonical_key'] ?? '')] = $mapping;
        }
        $accountsKeys = [
            'computation.summary.accounting_profit',
            'computation.summary.disallowable_add_backs',
            'computation.summary.capital_add_backs',
            'computation.summary.depreciation_add_back',
        ];
        foreach ($accountsKeys as $key) {
            if (!isset($byKey[$key])) {
                throw new \RuntimeException('The active computation mapping profile is missing required accounts-adjustment source ' . $key . '.');
            }
        }
        $tradeMapping = $byKey['computation.summary.accounting_profit'];
        $namespaceUri = trim((string)($tradeMapping['namespace_uri'] ?? ''));
        $concept = trim((string)($tradeMapping['taxonomy_concept'] ?? ''));
        [$prefix] = explode(':', $concept, 2);
        if ($namespaceUri === '' || $prefix === '' || !str_contains($concept, ':')) {
            throw new \RuntimeException('The active computation mapping profile has no usable HMRC taxonomy namespace.');
        }

        $wholeSourceValues = [
            'computation.summary.accounting_profit' => $this->money($whole['accounting_profit']),
            'computation.summary.disallowable_add_backs' => $this->money($whole['disallowable_add_backs']),
            'computation.summary.capital_add_backs' => $this->money($whole['capital_add_backs']),
            'computation.summary.depreciation_add_back' => $this->money($whole['depreciation_add_back']),
        ];
        $outputMappings = [];
        foreach ($mappings as $mapping) {
            $key = (string)($mapping['canonical_key'] ?? '');
            // This aggregate is not the Format 1.1 adjusted-period fact for a
            // split statutory period.  The profile supplies the prescribed
            // profit or loss concept below instead.
            if ($key === 'computation.summary.taxable_before_losses') {
                continue;
            }
            if (isset($wholeSourceValues[$key])) {
                $mapping['source_value'] = $wholeSourceValues[$key];
                $mapping['context_role'] = 'statutory_accounts_period';
            }
            $outputMappings[] = $mapping;
        }

        $synthetic = static function (
            string $key,
            string $localName,
            float $value,
            string $contextRole,
            int $sortOrder,
            string $periodType = 'duration',
            string $contextProfile = CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE,
            string $section = 'accounts_adjustments'
        ) use ($namespaceUri, $prefix): array {
            return [
                'id' => 10000 + $sortOrder,
                'canonical_key' => $key,
                'taxonomy_concept' => $prefix . ':' . $localName,
                'namespace_uri' => $namespaceUri,
                'local_name' => $localName,
                'value_type' => 'numeric',
                'period_type' => $periodType,
                'context_profile' => $contextProfile,
                'context_role' => $contextRole,
                'unit_ref' => 'GBP',
                'decimals_value' => '2',
                'dimensions_json' => null,
                'sign_multiplier' => 1,
                'presentation_section' => $section,
                'presentation_label' => $key,
                'null_policy' => 'omit',
                'is_required' => 0,
                'sort_order' => $sortOrder,
                'source_value' => $value,
            ];
        };
        $outputMappings[] = $synthetic(
            'report.accounts_adjustment.revised_figure_before_tax',
            'AdjustedProfitOrLossBeforeAccountingPeriodAdjustments',
            $adjustedWholePeriod,
            'statutory_accounts_period',
            45
        );
        if ($finalResult < -0.004) {
            $outputMappings[] = $synthetic(
                'report.accounts_adjustment.adjusted_loss_of_period',
                'AdjustedLossOfPeriod',
                abs($finalResult),
                'ct_period',
                70
            );
        } elseif ($finalResult > 0.004) {
            $outputMappings[] = $synthetic(
                'report.accounts_adjustment.adjusted_profit_for_period',
                'AdjustedProfitForThePeriod',
                $finalResult,
                'ct_period',
                70
            );
        }

        $rows = [
            $this->row('accounts_profit_loss', 'computation.summary.accounting_profit', 'Profit/(loss) before tax per statutory accounts', 'normal', 'statutory_accounts_period', 'whole_period_profit_before_tax'),
            $this->row('accounting_disallowable_expenses', 'computation.summary.disallowable_add_backs', 'Accounting adjustment for disallowable expenses', 'normal', 'statutory_accounts_period', 'whole_period_disallowable_add_backs'),
            $this->row('accounting_capital_expenditure', 'computation.summary.capital_add_backs', 'Accounting adjustment for capital expenditure', 'normal', 'statutory_accounts_period', 'whole_period_capital_add_backs'),
            $this->row('accounting_depreciation', 'computation.summary.depreciation_add_back', 'Accounting adjustment for depreciation', 'normal', 'statutory_accounts_period', 'whole_period_depreciation_add_back'),
            $this->row('revised_figure_before_tax', 'report.accounts_adjustment.revised_figure_before_tax', 'Revised figure before tax', 'normal', 'statutory_accounts_period', 'whole_period_adjusted_result_before_capital_allowances', 'subtotal'),
            [
                'id' => 'time_apportionment_figure',
                'label' => 'Time apportionment figure (' . (int)$allocation['ct_period_days'] . ' / ' . (int)$allocation['accounting_period_days'] . ' days)',
                'amount' => $apportioned,
                'direction' => 'normal',
                'taxonomy_concept' => null,
                'context_role' => 'ct_period',
                'visibility' => 'always',
                'nil_rule' => 'untagged_no_exact_taxonomy_concept',
                'source_calculation_reference' => 'whole_period_adjusted_result_time_apportionment',
            ],
            $this->row('capital_allowances', 'computation.summary.capital_allowances', 'Capital allowances', 'deduction', 'ct_period', 'frozen_ct_period_capital_allowances'),
        ];
        if ($finalResult < -0.004) {
            $rows[] = $this->row('adjusted_loss_of_period', 'report.accounts_adjustment.adjusted_loss_of_period', 'Adjusted loss of period', 'normal', 'ct_period', 'frozen_ct_period_adjusted_loss', 'final-total');
        } elseif ($finalResult > 0.004) {
            $rows[] = $this->row('adjusted_profit_for_period', 'report.accounts_adjustment.adjusted_profit_for_period', 'Adjusted profit for the period', 'normal', 'ct_period', 'frozen_ct_period_adjusted_profit', 'final-total');
        }
        $mappedByKey = [];
        foreach ($outputMappings as $mapping) {
            $mappedByKey[(string)$mapping['canonical_key']] = (string)$mapping['taxonomy_concept'];
        }
        foreach ($rows as &$row) {
            $factKey = (string)($row['fact_key'] ?? '');
            if ($factKey !== '') {
                $row['taxonomy_concept'] = $mappedByKey[$factKey] ?? null;
            }
        }
        unset($row);

        $mainPool = $this->mainPoolSchedule($filing, $summary, $synthetic);
        foreach ($mainPool['mappings'] as $mapping) {
            $outputMappings[] = $mapping;
        }
        $mappedByKey = [];
        foreach ($outputMappings as $mapping) {
            $mappedByKey[(string)$mapping['canonical_key']] = (string)$mapping['taxonomy_concept'];
        }
        foreach ($mainPool['rows'] as &$row) {
            $factKey = (string)($row['fact_key'] ?? '');
            if ($factKey !== '') {
                $row['taxonomy_concept'] = $mappedByKey[$factKey] ?? null;
            }
        }
        unset($row);

        $lossSchedule = $this->lossSchedule($summary, $synthetic);
        foreach ($lossSchedule['mappings'] as $mapping) {
            $outputMappings[] = $mapping;
        }
        $mappedByKey = [];
        foreach ($outputMappings as $mapping) {
            $mappedByKey[(string)$mapping['canonical_key']] = (string)$mapping['taxonomy_concept'];
        }
        foreach ($lossSchedule['rows'] as &$row) {
            $factKey = (string)($row['fact_key'] ?? '');
            if ($factKey !== '') {
                $row['taxonomy_concept'] = $mappedByKey[$factKey] ?? null;
            }
        }
        unset($row);

        return [
            'mappings' => $outputMappings,
            'accounts_adjustment_rows' => $rows,
            'main_pool_rows' => $mainPool['rows'],
            'loss_schedule_rows' => $lossSchedule['rows'],
            'untagged_row_allowlist' => self::UNTAGGED_ROW_ALLOWLIST,
            'format_version' => self::VERSION,
        ];
    }

    /**
     * @param \Closure(string,string,float,string,int,string,string,string):array<string,mixed> $synthetic
     * @return array{mappings:list<array<string,mixed>>,rows:list<array<string,mixed>>}
     */
    private function lossSchedule(array $summary, \Closure $synthetic): array
    {
        $restriction = (array)($summary['loss_restriction'] ?? []);
        $post = (array)($restriction['post_2017_trading_losses'] ?? []);
        $allowance = (array)($restriction['deduction_allowance'] ?? []);
        foreach (['arising'] as $key) {
            if (!is_numeric($post[$key] ?? null)) {
                throw new \RuntimeException('The frozen loss schedule is missing post-2017 trading loss ' . $key . '.');
            }
        }
        foreach (['qualifying_profits', 'carried_forward_loss_relief_claimed'] as $key) {
            if (!is_numeric($restriction[$key] ?? null)) {
                throw new \RuntimeException('The frozen loss-restriction schedule is missing ' . $key . '.');
            }
        }
        if (!is_numeric($allowance['amount'] ?? null) || !is_numeric($restriction['calculated_loss_restriction'] ?? null)) {
            throw new \RuntimeException('The frozen loss-restriction schedule is incomplete.');
        }

        $mappings = [
            $synthetic(
                'report.loss.post_2017_trading_loss_arising',
                'TradingLossesOfThisOrLaterAP',
                $this->money($post['arising']),
                'ct_period',
                230,
                'duration',
                CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE,
                'losses'
            ),
            $synthetic(
                'report.loss.carried_forward_relief_claimed',
                'TradingLossesBroughtForwardValueClaimedAgainstTotalProfits',
                $this->money($restriction['carried_forward_loss_relief_claimed']),
                'ct_period',
                240,
                'duration',
                CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE,
                'losses'
            ),
            $synthetic(
                'report.loss.qualifying_profits',
                'ProfitsThatCanBeCoveredByBroughtForwardLosses',
                $this->money($restriction['qualifying_profits']),
                'ct_period',
                250,
                'duration',
                CtFilingMappingService::CONTEXT_HMRC_CT_LOSS_RESTRICTION,
                'losses'
            ),
        ];
        $rows = [
            $this->row('post_2017_trading_losses_brought_forward', 'computation.summary.loss_restriction.post_2017_trading_losses.brought_forward', 'Post-1 April 2017 trading losses brought forward', 'normal', 'ct_period', 'frozen_post_2017_trading_losses_brought_forward'),
            $this->row('post_2017_trading_losses_arising', 'report.loss.post_2017_trading_loss_arising', 'Post-1 April 2017 trading losses arising', 'normal', 'ct_period', 'frozen_post_2017_trading_losses_arising'),
            $this->row('post_2017_trading_losses_used', 'computation.summary.loss_restriction.post_2017_trading_losses.used', 'Post-1 April 2017 trading losses used against total profits', 'normal', 'ct_period', 'frozen_post_2017_trading_losses_used'),
            $this->row('post_2017_trading_losses_carried_forward', 'computation.summary.loss_restriction.post_2017_trading_losses.carried_forward', 'Post-1 April 2017 trading losses carried forward', 'normal', 'ct_period_end', 'frozen_post_2017_trading_losses_carried_forward'),
            $this->row('deduction_allowance', 'computation.summary.loss_restriction.deduction_allowance.amount', 'Non-group deductions allowance for the period', 'normal', 'ct_period', 'frozen_non_group_deduction_allowance'),
            $this->row('qualifying_profits', 'report.loss.qualifying_profits', 'Qualifying profits', 'normal', 'ct_period', 'frozen_qualifying_profits'),
            $this->row('carried_forward_relief_claimed', 'report.loss.carried_forward_relief_claimed', 'Carried-forward loss relief claimed against total profits', 'normal', 'ct_period', 'frozen_carried_forward_loss_relief_claimed'),
            $this->row('calculated_loss_restriction', 'computation.summary.loss_restriction.calculated_loss_restriction', 'Calculated loss restriction', 'normal', 'ct_period', 'frozen_calculated_loss_restriction'),
            [
                'id' => 'loss_restriction_result',
                'label' => 'Loss restriction',
                'value_type' => 'text',
                'taxonomy_concept' => null,
                'context_role' => 'ct_period',
                'visibility' => 'always',
                'nil_rule' => 'untagged_allowlisted',
                'source_calculation_reference' => 'frozen_loss_restriction_result',
            ],
        ];
        return ['mappings' => $mappings, 'rows' => $rows];
    }

    /**
     * @param \Closure(string,string,float,string,int,string,string,string):array<string,mixed> $synthetic
     * @return array{mappings:list<array<string,mixed>>,rows:list<array<string,mixed>>}
     */
    private function mainPoolSchedule(array $filing, array $summary, \Closure $synthetic): array
    {
        $breakdown = (array)($summary['capital_allowance_breakdown'] ?? []);
        $main = null;
        foreach ((array)($breakdown['rows'] ?? []) as $pool) {
            if (is_array($pool) && (string)($pool['pool_type'] ?? '') === 'main_pool') {
                $main = $pool;
                break;
            }
        }
        if (!is_array($main)) {
            return ['mappings' => [], 'rows' => []];
        }
        $aiaQualifyingExpenditure = 0.0;
        $hasAssetActivity = false;
        foreach ((array)($breakdown['asset_calculations'] ?? []) as $calculation) {
            if (!is_array($calculation) || (string)($calculation['pool_type'] ?? '') !== 'main_pool') {
                continue;
            }
            $hasAssetActivity = true;
            if ((string)($calculation['allowance_type'] ?? '') === 'aia') {
                $aiaQualifyingExpenditure += $this->money($calculation['addition_amount'] ?? null);
            }
        }
        $opening = $this->money($main['opening_wdv'] ?? null);
        $allocatedToPool = $this->money($main['additions'] ?? null);
        $aiaClaimed = $this->money($main['aia_claimed'] ?? null);
        $fyaClaimed = $this->money($main['fya_claimed'] ?? null);
        $disposals = $this->money($main['disposal_value'] ?? null);
        $wdaClaimed = $this->money($main['wda_claimed'] ?? null);
        $balancingAllowance = $this->money($main['balancing_allowance'] ?? null);
        $balancingCharge = $this->money($main['balancing_charge'] ?? null);
        $closing = $this->money($main['closing_wdv'] ?? null);
        if (!$hasAssetActivity && max(
            abs($opening), abs($allocatedToPool), abs($aiaClaimed), abs($disposals), abs($wdaClaimed),
            abs($balancingAllowance), abs($balancingCharge), abs($closing)
        ) < 0.005) {
            return ['mappings' => [], 'rows' => []];
        }
        $aiaQualifyingExpenditure = round($aiaQualifyingExpenditure, 2);
        $totalAdditions = round($aiaQualifyingExpenditure + $allocatedToPool, 2);
        $available = round($opening + $totalAdditions, 2);
        $afterAia = round($available - $aiaClaimed, 2);
        $balanceBeforeWda = round($afterAia - $disposals + $balancingCharge, 2);
        $expectedClosing = round($balanceBeforeWda - $wdaClaimed - $balancingAllowance, 2);
        if (abs($closing - $expectedClosing) > 0.009) {
            throw new \RuntimeException('The frozen main-pool calculation does not reconcile to its closing written-down value.');
        }
        $run = (array)($filing['run'] ?? []);
        $periodStart = (string)($run['period_start'] ?? '');
        $periodEnd = (string)($run['period_end'] ?? '');
        if ($periodStart === '' || $periodEnd === '') {
            throw new \RuntimeException('The frozen main-pool report has no CT-period dates.');
        }
        $periodDays = (int)(new \DateTimeImmutable($periodStart))->diff(new \DateTimeImmutable($periodEnd))->days + 1;
        $rateRules = new TaxRateRuleService();
        $aiaLimit = round($rateRules->weightedAmountForPeriod(
            'capital_allowances', 'plant_machinery', 'aia_annual_limit', $periodStart, $periodEnd
        ) * min(1.0, $periodDays / 365), 2);
        $wdaRate = $rateRules->weightedRateForPeriod(
            'capital_allowances', 'plant_machinery', 'main_pool_wda', $periodStart, $periodEnd
        );
        $wdaAvailable = round($balanceBeforeWda * $wdaRate * min(1.0, $periodDays / 365), 2);
        $totalFyaAndWda = round($fyaClaimed + $wdaClaimed, 2);
        $totalAllowances = round($aiaClaimed + $totalFyaAndWda + $balancingAllowance, 2);

        $mappings = [
            $synthetic('report.main_pool.opening_wdv', 'MainPoolWrittenDownValue', $opening, 'ct_period_beginning', 310, 'instant'),
            $synthetic('report.main_pool.aia_qualifying_expenditure', 'MainPoolExpenditureQualifyingForAnnualInvestmentAllowance', $aiaQualifyingExpenditure, 'ct_period', 320),
            $synthetic('report.main_pool.wda_qualifying_expenditure', 'MainPoolExpenditureQualifyingForWritingDownAllowance', $allocatedToPool, 'ct_period', 330),
            $synthetic('report.main_pool.total_qualifying_expenditure', 'MainPoolTotalQualifyingExpenditure', $available, 'ct_period', 340),
            $synthetic('report.main_pool.aia_claimed', 'MainPoolAnnualInvestmentAllowance', $aiaClaimed, 'ct_period', 350),
            $synthetic('report.main_pool.disposal_receipts', 'MainPoolTotalDisposalReceipts', $disposals, 'ct_period', 360),
            $synthetic('report.main_pool.wda_claimed', 'MainPoolWritingDownAllowances', $wdaClaimed, 'ct_period', 370),
            $synthetic('report.main_pool.balancing_allowance', 'MainPoolBalancingAllowances', $balancingAllowance, 'ct_period', 380),
            $synthetic('report.main_pool.balancing_charge', 'MainPoolBalancingCharges', $balancingCharge, 'ct_period', 390),
            $synthetic('report.main_pool.closing_wdv', 'MainPoolWrittenDownValue', $closing, 'ct_period_end', 400, 'instant'),
            $synthetic('report.main_pool.total_fya_and_wda', 'MainPoolTotalFYAAndWDA', $totalFyaAndWda, 'ct_period', 410),
            $synthetic('report.main_pool.total_allowances', 'MainPoolTotalAllowances', $totalAllowances, 'ct_period', 420),
        ];
        $rows = [
            $this->row('main_pool_opening_wdv', 'report.main_pool.opening_wdv', 'Unrelieved qualifying expenditure brought forward', 'normal', 'ct_period_beginning', 'frozen_main_pool_opening_wdv'),
            $this->plainRow('main_pool_additions', 'Qualifying expenditure added to the pool', $totalAdditions, 'frozen_main_pool_aia_qualifying_expenditure_plus_allocated_additions'),
            $this->row('main_pool_aia_qualifying_expenditure', 'report.main_pool.aia_qualifying_expenditure', 'Additions qualifying for Annual Investment Allowance', 'normal', 'ct_period', 'frozen_main_pool_aia_qualifying_expenditure'),
            $this->row('main_pool_allocated', 'report.main_pool.wda_qualifying_expenditure', 'Amount allocated to the pool', 'normal', 'ct_period', 'frozen_main_pool_expenditure_qualifying_for_wda'),
            $this->row('main_pool_available', 'report.main_pool.total_qualifying_expenditure', 'Available qualifying expenditure', 'normal', 'ct_period', 'opening_plus_additions', 'subtotal'),
            $this->plainRow('main_pool_aia_limit', 'Annual Investment Allowance limit', $aiaLimit, 'published_aia_limit_for_ct_period'),
            $this->row('main_pool_aia_claimed', 'report.main_pool.aia_claimed', 'AIA claimed', 'normal', 'ct_period', 'frozen_main_pool_aia_claimed'),
            $this->plainRow('main_pool_after_aia', 'Available qualifying expenditure less AIA', $afterAia, 'available_qualifying_expenditure_less_aia'),
            $this->row('main_pool_disposals', 'report.main_pool.disposal_receipts', 'Total disposal receipts', 'normal', 'ct_period', 'frozen_main_pool_disposal_receipts'),
            $this->plainRow('main_pool_balance', 'Balance of qualifying expenditure', $balanceBeforeWda, 'available_less_aia_and_disposals'),
            $this->plainRow('main_pool_wda_rate', 'WDA rate', $wdaRate, 'published_main_pool_wda_rate', 'percent'),
            $this->plainRow('main_pool_wda_available', 'WDA available', $wdaAvailable, 'published_main_pool_wda_rate_applied_to_frozen_balance'),
            $this->row('main_pool_wda_claimed', 'report.main_pool.wda_claimed', 'WDA claimed', 'normal', 'ct_period', 'frozen_main_pool_wda_claimed'),
            $this->row('main_pool_balancing_allowance', 'report.main_pool.balancing_allowance', 'Balancing allowance', 'normal', 'ct_period', 'frozen_main_pool_balancing_allowance'),
            $this->row('main_pool_balancing_charge', 'report.main_pool.balancing_charge', 'Balancing charge', 'normal', 'ct_period', 'frozen_main_pool_balancing_charge'),
            $this->row('main_pool_closing_wdv', 'report.main_pool.closing_wdv', 'Unrelieved qualifying expenditure carried forward', 'normal', 'ct_period_end', 'frozen_main_pool_closing_wdv', 'subtotal'),
            $this->row('main_pool_total_fya_and_wda', 'report.main_pool.total_fya_and_wda', 'Total FYA and WDA claimed for the main pool', 'normal', 'ct_period', 'frozen_main_pool_total_fya_and_wda'),
            $this->row('main_pool_total_allowances', 'report.main_pool.total_allowances', 'Total capital allowances for the main pool', 'normal', 'ct_period', 'frozen_main_pool_total_allowances', 'final-total'),
        ];
        return ['mappings' => $mappings, 'rows' => $rows];
    }

    /** @return array<string,mixed> */
    private function row(string $id, string $factKey, string $label, string $direction, string $contextRole, string $source, string $class = ''): array
    {
        return [
            'id' => $id,
            'fact_key' => $factKey,
            'label' => $label,
            'direction' => $direction,
            'context_role' => $contextRole,
            'visibility' => 'always',
            'nil_rule' => 'omit_when_null',
            'source_calculation_reference' => $source,
            'class' => $class,
        ];
    }

    /** @return array<string,mixed> */
    private function plainRow(string $id, string $label, float $amount, string $source, string $displayType = 'money'): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'amount' => $amount,
            'direction' => 'normal',
            'taxonomy_concept' => null,
            'context_role' => 'ct_period',
            'visibility' => 'always',
            'nil_rule' => 'untagged_no_exact_taxonomy_concept',
            'source_calculation_reference' => $source,
            'display_type' => $displayType,
        ];
    }

    private function money(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException('The frozen long-period filing basis contains a non-numeric monetary value.');
        }
        return round((float)$value, 2);
    }
}
