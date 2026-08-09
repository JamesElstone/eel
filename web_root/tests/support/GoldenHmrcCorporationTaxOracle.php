<?php
/**
 * Pure HMRC corporation-tax oracle for the deterministic golden company.
 *
 * It deliberately has no application-service or database dependency. Rates and
 * formulae are based on current HMRC guidance:
 * https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax
 * https://www.gov.uk/guidance/capital-allowances-accounting-periods-which-are-more-or-less-than-a-year
 * https://www.gov.uk/guidance/corporation-tax-interest-charges
 */
declare(strict_types=1);

final class GoldenHmrcCorporationTaxOracle
{
    private const SMALL_PROFITS_RATE = 0.19;
    private const MAIN_RATE = 0.25;
    private const LOWER_LIMIT = 50000.0;
    private const UPPER_LIMIT = 250000.0;
    private const MARGINAL_RELIEF_FRACTION = 3 / 200;

    /**
     * @param array<int, array<string, mixed>> $periodFacts
     * @return array<int, array<string, float>>
     */
    public static function calculateSequence(array $periodFacts): array
    {
        $results = [];
        $lossesCarriedForward = 0.0;

        foreach ($periodFacts as $periodId => $facts) {
            if (
                (float)($facts['hmrc_interest_amount'] ?? 0) > 0
                && (string)($facts['hmrc_interest_type'] ?? '') !== 'corporation_tax_late_payment'
            ) {
                throw new InvalidArgumentException('HMRC interest is deductible only when the golden evidence identifies Corporation Tax late-payment interest.');
            }
            $accountingProfit = round((float)($facts['accounting_profit'] ?? 0), 2);
            $disallowableAddBacks = round((float)($facts['disallowable_add_backs'] ?? 0), 2);
            $depreciationAddBack = round((float)($facts['depreciation_add_back'] ?? 0), 2);
            $charitableDonationAddBack = round((float)($facts['charitable_donation_add_back'] ?? 0), 2);
            $qualifyingDonationsPaid = round(max(0.0, (float)($facts['qualifying_charitable_donations_paid'] ?? 0)), 2);
            $capitalAllowances = round((float)($facts['capital_allowances'] ?? 0), 2);
            $taxableBeforeLosses = round(
                $accountingProfit + $disallowableAddBacks + $charitableDonationAddBack + $depreciationAddBack - $capitalAllowances,
                2
            );

            $lossesBroughtForward = $lossesCarriedForward;
            $lossClaimCapacity = max(0.0, round($taxableBeforeLosses - $qualifyingDonationsPaid, 2));
            $lossesUsed = $lossClaimCapacity > 0
                ? min($lossesBroughtForward, $lossClaimCapacity)
                : 0.0;
            $profitsBeforeDonations = round(max(0.0, $taxableBeforeLosses - $lossesUsed), 2);
            $qualifyingDonationsClaimed = min($qualifyingDonationsPaid, $profitsBeforeDonations);
            $taxableProfit = round(max(0.0, $profitsBeforeDonations - $qualifyingDonationsClaimed), 2);
            $newLoss = max(0.0, -$taxableBeforeLosses);
            $lossesCarriedForward = round($lossesBroughtForward - $lossesUsed + $newLoss, 2);

            $periodStart = new DateTimeImmutable((string)$facts['period_start']);
            $periodEnd = new DateTimeImmutable((string)$facts['period_end']);
            $associatedCompanyCount = max(0, (int)($facts['associated_company_count'] ?? 0));
            $tax = self::taxForAccountingPeriod(
                $taxableProfit,
                $taxableProfit,
                $periodStart,
                $periodEnd,
                $associatedCompanyCount
            );

            $results[(int)$periodId] = [
                'accounting_profit' => $accountingProfit,
                'disallowable_add_backs' => $disallowableAddBacks,
                'depreciation_add_back' => $depreciationAddBack,
                'charitable_donation_add_back' => $charitableDonationAddBack,
                'qualifying_charitable_donations_paid' => $qualifyingDonationsPaid,
                'qualifying_charitable_donations_claimed' => round($qualifyingDonationsClaimed, 2),
                'profits_before_donations_group_relief' => $profitsBeforeDonations,
                'capital_allowances' => $capitalAllowances,
                'taxable_before_losses' => $taxableBeforeLosses,
                'losses_brought_forward' => $lossesBroughtForward,
                'losses_used' => round($lossesUsed, 2),
                'taxable_profit' => $taxableProfit,
                'losses_carried_forward' => $lossesCarriedForward,
                'corporation_tax' => $tax,
            ];
        }

        return $results;
    }

    private static function taxForAccountingPeriod(
        float $taxableProfit,
        float $augmentedProfit,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        int $associatedCompanyCount
    ): float {
        if ($taxableProfit <= 0 || $periodEnd < $periodStart) {
            return 0.0;
        }

        $totalDays = (int)$periodStart->diff($periodEnd)->days + 1;
        $companies = $associatedCompanyCount + 1;
        $tax = 0.0;
        $sliceStart = $periodStart;

        while ($sliceStart <= $periodEnd) {
            $financialYearStartYear = (int)$sliceStart->format('Y') - ((int)$sliceStart->format('n') < 4 ? 1 : 0);
            $financialYearEnd = new DateTimeImmutable(($financialYearStartYear + 1) . '-03-31');
            $sliceEnd = $financialYearEnd < $periodEnd ? $financialYearEnd : $periodEnd;
            $sliceDays = (int)$sliceStart->diff($sliceEnd)->days + 1;
            $sliceTaxableProfit = $taxableProfit * ($sliceDays / $totalDays);
            $sliceAugmentedProfit = $augmentedProfit * ($sliceDays / $totalDays);

            if ($sliceEnd < new DateTimeImmutable('2023-04-01')) {
                $tax += $sliceTaxableProfit * self::SMALL_PROFITS_RATE;
            } else {
                $limitFactor = ($totalDays / 365) / $companies;
                $lowerLimit = self::LOWER_LIMIT * $limitFactor;
                $upperLimit = self::UPPER_LIMIT * $limitFactor;

                if ($augmentedProfit <= $lowerLimit) {
                    $tax += $sliceTaxableProfit * self::SMALL_PROFITS_RATE;
                } elseif ($augmentedProfit >= $upperLimit) {
                    $tax += $sliceTaxableProfit * self::MAIN_RATE;
                } else {
                    $marginalRelief = ($upperLimit - $augmentedProfit)
                        * self::MARGINAL_RELIEF_FRACTION
                        * ($sliceTaxableProfit / max($sliceAugmentedProfit, 0.01));
                    $tax += ($sliceTaxableProfit * self::MAIN_RATE) - $marginalRelief;
                }
            }

            $sliceStart = $sliceEnd->modify('+1 day');
        }

        return round(max(0.0, $tax), 2);
    }
}
