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
    public const VERSION = 'hmrc-ct-computations-format-1.1';

    /**
     * @param list<array<string,mixed>> $mappings
     * @return array{mappings:list<array<string,mixed>>,accounts_adjustment_rows:list<array<string,mixed>>,format_version:string}
     */
    public function apply(array $filing, array $mappings): array
    {
        $summary = (array)($filing['model']['computation']['summary'] ?? []);
        $allocation = (array)($summary['accounting_allocation_basis'] ?? []);
        if (empty($allocation['time_apportioned'])) {
            return [
                'mappings' => $mappings,
                'accounts_adjustment_rows' => [],
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

        $synthetic = static function (string $key, string $localName, float $value, string $contextRole, int $sortOrder) use ($tradeMapping, $namespaceUri, $prefix): array {
            return [
                'id' => 10000 + $sortOrder,
                'canonical_key' => $key,
                'taxonomy_concept' => $prefix . ':' . $localName,
                'namespace_uri' => $namespaceUri,
                'local_name' => $localName,
                'value_type' => 'numeric',
                'period_type' => 'duration',
                'context_profile' => CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE,
                'context_role' => $contextRole,
                'unit_ref' => 'GBP',
                'decimals_value' => '2',
                'dimensions_json' => null,
                'sign_multiplier' => 1,
                'presentation_section' => 'accounts_adjustments',
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

        return [
            'mappings' => $outputMappings,
            'accounts_adjustment_rows' => $rows,
            'format_version' => self::VERSION,
        ];
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

    private function money(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException('The frozen long-period filing basis contains a non-numeric monetary value.');
        }
        return round((float)$value, 2);
    }
}
