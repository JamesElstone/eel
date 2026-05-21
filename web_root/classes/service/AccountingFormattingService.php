<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AccountingFormattingService
{
    public static function nominalTaxTreatmentLabel(string $taxTreatment): string
    {
        return match (trim($taxTreatment)) {
            'disallowable' => 'Disallowable',
            'capital' => 'Capital',
            default => 'Allowable',
        };
    }

    public static function displayDateFormat(?int $companyId = null): string
    {
        $companyId = HelperFramework::sanitiseId($companyId, (new AccountingContextService())->companyId());

        if ($companyId <= 0) {
            return 'd/m/Y';
        }

        $format = (string)(new CompanySettingsStore($companyId))->get('date_format', 'd/m/Y');

        return self::normaliseDateFormat($format);
    }

    private static function normaliseDateFormat(string $format): string
    {
        return in_array($format, ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'], true)
            ? $format
            : 'd/m/Y';
    }
}
