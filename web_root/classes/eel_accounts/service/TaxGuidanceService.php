<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class TaxGuidanceService
{
    private const LINKS = [
        'corporation_tax' => 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax',
        'company_tax_returns' => 'https://www.gov.uk/company-tax-returns',
        'capital_allowances' => 'https://www.gov.uk/capital-allowances',
        'aia' => 'https://www.gov.uk/capital-allowances/annual-investment-allowance',
        'wda' => 'https://www.gov.uk/work-out-capital-allowances',
        'business_cars' => 'https://www.gov.uk/capital-allowances/business-cars',
        'losses' => 'https://www.gov.uk/guidance/corporation-tax-calculating-and-claiming-a-loss',
        'marginal_relief' => 'https://www.gov.uk/guidance/corporation-tax-marginal-relief',
        'bim35201' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim35201',
        'bim42201' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim42201',
        'bim70066' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim70066',
        'ctm01405' => 'https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm01405',
        'frs105' => 'https://www.frc.org.uk/library/standards-codes-policy/accounting-and-reporting/uk-accounting-standards/frs-105/',
    ];

    public static function url(string $key): string
    {
        return self::LINKS[$key] ?? self::LINKS['company_tax_returns'];
    }

    public static function all(): array
    {
        return self::LINKS;
    }
}
