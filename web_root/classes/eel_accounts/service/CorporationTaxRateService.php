<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CorporationTaxRateService
{
    /**
     * @param array<int, array<string, mixed>>|null $ruleFixtures Unit-test only. Production loads rules from corporation_tax_rate_rules.
     */
    public function __construct(
        private readonly ?array $ruleFixtures = null,
        private readonly ?\eel_accounts\Service\CorporationTaxRateRuleService $ruleService = null,
    ) {
    }

    public function calculate(
        string $periodStart,
        string $periodEnd,
        float $taxableProfit,
        int $associatedCompanyCount = 0,
        ?float $augmentedProfit = null
    ): array {
        $start = new \DateTimeImmutable($periodStart);
        $end = new \DateTimeImmutable($periodEnd);
        if ($start > $end) {
            throw new \RuntimeException('Corporation Tax period start must be on or before the period end.');
        }

        $taxableProfit = round($taxableProfit, 2);
        $augmentedProfit = round($augmentedProfit ?? $taxableProfit, 2);
        if ($taxableProfit <= 0.0) {
            return [
                'liability' => 0.0,
                'effective_rate' => 0.0,
                'associated_company_count' => max(0, $associatedCompanyCount),
                'bands' => [],
                'warnings' => [],
            ];
        }

        $augmentedProfit = max($taxableProfit, $augmentedProfit);
        $associatedCompanyCount = max(0, $associatedCompanyCount);
        $associatedDivisor = $associatedCompanyCount + 1;
        $totalDays = $this->inclusiveDays($start, $end);
        $segments = $this->financialYearSegments($start, $end);
        $liability = 0.0;
        $bands = [];

        foreach ($segments as $segment) {
            $segmentStart = $segment['start'];
            $segmentEnd = $segment['end'];
            $segmentDays = $this->inclusiveDays($segmentStart, $segmentEnd);
            $profitShare = $segmentDays / $totalDays;
            $segmentTaxableProfit = round($taxableProfit * $profitShare, 10);
            $segmentAugmentedProfit = round($augmentedProfit * $profitShare, 10);
            $rules = $this->rulesForFinancialYear($segmentStart);
            $segmentTax = $this->calculateSegmentTax(
                $segmentStart,
                $segmentTaxableProfit,
                $segmentAugmentedProfit,
                $segmentDays,
                $associatedDivisor,
                $rules
            );
            $liability += $segmentTax['liability'];
            $bands[] = $segmentTax;
        }

        $liability = round($liability, 2);

        return [
            'liability' => $liability,
            'effective_rate' => round($liability / $taxableProfit, 6),
            'associated_company_count' => $associatedCompanyCount,
            'bands' => $bands,
            'warnings' => $this->warnings($augmentedProfit, $taxableProfit),
        ];
    }

    private function calculateSegmentTax(
        \DateTimeImmutable $segmentStart,
        float $taxableProfit,
        float $augmentedProfit,
        int $segmentDays,
        int $associatedDivisor,
        array $rules
    ): array {
        if (empty($rules['small_profits_available'])) {
            return [
                'financial_year' => $this->financialYearLabel($segmentStart),
                'taxable_profit' => round($taxableProfit, 2),
                'augmented_profit' => round($augmentedProfit, 2),
                'lower_limit' => null,
                'upper_limit' => null,
                'main_rate' => $rules['main_rate'],
                'small_profits_rate' => null,
                'marginal_relief' => 0.0,
                'liability' => round($taxableProfit * (float)$rules['main_rate'], 2),
                'basis' => 'flat_main_rate',
                'rule_version' => (string)($rules['rule_version'] ?? ''),
                'source_url' => (string)($rules['source_url'] ?? ''),
                'source_checked_at' => (string)($rules['source_checked_at'] ?? ''),
            ];
        }

        $financialYearDays = $this->financialYearDays($segmentStart);
        $periodFactor = $segmentDays / $financialYearDays;
        $lowerLimit = round(((float)$rules['lower_limit'] * $periodFactor) / $associatedDivisor, 10);
        $upperLimit = round(((float)$rules['upper_limit'] * $periodFactor) / $associatedDivisor, 10);
        $mainTax = $taxableProfit * (float)$rules['main_rate'];
        $marginalRelief = 0.0;
        $basis = 'main_rate';

        if ($augmentedProfit <= $lowerLimit) {
            $liability = $taxableProfit * (float)$rules['small_profits_rate'];
            $basis = 'small_profits_rate';
        } elseif ($augmentedProfit <= $upperLimit && $augmentedProfit > 0.0) {
            $marginalRelief = ((float)$rules['marginal_relief_fraction'] * ($upperLimit - $augmentedProfit))
                * ($taxableProfit / $augmentedProfit);
            $liability = max(0.0, $mainTax - $marginalRelief);
            $basis = 'main_rate_less_marginal_relief';
        } else {
            $liability = $mainTax;
        }

        return [
            'financial_year' => $this->financialYearLabel($segmentStart),
            'taxable_profit' => round($taxableProfit, 2),
            'augmented_profit' => round($augmentedProfit, 2),
            'lower_limit' => round($lowerLimit, 2),
            'upper_limit' => round($upperLimit, 2),
            'main_rate' => (float)$rules['main_rate'],
            'small_profits_rate' => (float)$rules['small_profits_rate'],
            'marginal_relief' => round($marginalRelief, 2),
            'liability' => round($liability, 2),
            'basis' => $basis,
            'rule_version' => (string)($rules['rule_version'] ?? ''),
            'source_url' => (string)($rules['source_url'] ?? ''),
            'source_checked_at' => (string)($rules['source_checked_at'] ?? ''),
        ];
    }

    private function rulesForFinancialYear(\DateTimeImmutable $date): array
    {
        $financialYearStart = $this->financialYearStart($date);
        $row = $this->fetchRateRule($financialYearStart);

        return [
            'small_profits_available' => $row['small_profits_rate'] !== null
                && $row['lower_limit'] !== null
                && $row['upper_limit'] !== null
                && $row['marginal_relief_fraction'] !== null,
            'main_rate' => (float)$row['main_rate'],
            'small_profits_rate' => $row['small_profits_rate'] !== null ? (float)$row['small_profits_rate'] : null,
            'lower_limit' => $row['lower_limit'] !== null ? (float)$row['lower_limit'] : null,
            'upper_limit' => $row['upper_limit'] !== null ? (float)$row['upper_limit'] : null,
            'marginal_relief_fraction' => $row['marginal_relief_fraction'] !== null ? (float)$row['marginal_relief_fraction'] : null,
            'rule_version' => (string)($row['rule_version'] ?? ''),
            'source_url' => (string)($row['source_url'] ?? ''),
            'source_checked_at' => (string)($row['source_checked_at'] ?? ''),
        ];
    }

    private function fetchRateRule(\DateTimeImmutable $financialYearStart): array
    {
        $date = $financialYearStart->format('Y-m-d');
        $rule = $this->fetchFixtureRateRule($date);
        if ($rule !== null) {
            return $rule;
        }

        $row = ($this->ruleService ?? new \eel_accounts\Service\CorporationTaxRateRuleService())
            ->fetchActiveRuleForFinancialYear($financialYearStart);

        if (!is_array($row)) {
            throw new \RuntimeException('No active Corporation Tax rate rule was found for FY' . $financialYearStart->format('Y') . '. Add a corporation_tax_rate_rules row before generating CT estimates.');
        }

        return $this->normaliseRateRule($row);
    }

    private function fetchFixtureRateRule(string $financialYearStart): ?array
    {
        if ($this->ruleFixtures === null) {
            return null;
        }

        foreach ($this->ruleFixtures as $row) {
            if (
                (string)($row['financial_year_start'] ?? '') <= $financialYearStart
                && (string)($row['financial_year_end'] ?? '') >= $financialYearStart
                && (int)($row['is_active'] ?? 1) === 1
            ) {
                return $this->normaliseRateRule($row);
            }
        }

        return null;
    }

    private function normaliseRateRule(array $row): array
    {
        return [
            'main_rate' => (float)$row['main_rate'],
            'small_profits_rate' => $this->nullableFloat($row['small_profits_rate'] ?? null),
            'lower_limit' => $this->nullableFloat($row['lower_limit'] ?? null),
            'upper_limit' => $this->nullableFloat($row['upper_limit'] ?? null),
            'marginal_relief_fraction' => $this->nullableFloat($row['marginal_relief_fraction'] ?? null),
            'rule_version' => (string)($row['rule_version'] ?? ''),
            'source_url' => (string)($row['source_url'] ?? ''),
            'source_checked_at' => (string)($row['source_checked_at'] ?? ''),
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        return (float)$value;
    }

    private function financialYearSegments(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $segments = [];
        $cursor = $start;

        while ($cursor <= $end) {
            $financialYearEnd = $this->financialYearStart($cursor)->modify('+1 year')->modify('-1 day');
            $segmentEnd = $financialYearEnd < $end ? $financialYearEnd : $end;
            $segments[] = [
                'start' => $cursor,
                'end' => $segmentEnd,
            ];
            $cursor = $segmentEnd->modify('+1 day');
        }

        return $segments;
    }

    private function financialYearStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $year = (int)$date->format('Y');
        $candidate = new \DateTimeImmutable($year . '-04-01');

        return $date < $candidate ? $candidate->modify('-1 year') : $candidate;
    }

    private function financialYearLabel(\DateTimeImmutable $date): string
    {
        return 'FY' . $this->financialYearStart($date)->format('Y');
    }

    private function financialYearDays(\DateTimeImmutable $date): int
    {
        $start = $this->financialYearStart($date);
        $end = $start->modify('+1 year')->modify('-1 day');

        return $this->inclusiveDays($start, $end);
    }

    private function inclusiveDays(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return $start->diff($end)->days + 1;
    }

    private function warnings(float $augmentedProfit, float $taxableProfit): array
    {
        $warnings = ['Corporation Tax estimate assumes non-ring-fence profits.'];
        if (abs($augmentedProfit - $taxableProfit) < 0.005) {
            $warnings[] = 'Corporation Tax estimate assumes augmented profits equal taxable profits; review if exempt distributions were received.';
        }

        return $warnings;
    }
}
