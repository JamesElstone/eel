<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Shared statutory-accounts classification for income-statement ledger rows. */
final class IncomeStatementClassificationService
{
    public const INCOME_TURNOVER = 'turnover';
    public const INCOME_OTHER = 'other_income';

    public function incomeBucket(array $row): string
    {
        $subtype = strtolower(trim((string)(
            $row['account_subtype_code']
                ?? $row['subtype_code']
                ?? ''
        )));
        if (in_array($subtype, [
            'other_income',
            'interest_income',
            'finance_income',
            'grant_income',
            'rental_income',
            'asset_disposal_gain',
        ], true)) {
            return self::INCOME_OTHER;
        }

        $name = strtolower(trim((string)($row['name'] ?? '')));
        return preg_match(
            '/\b(other income|interest (?:income|received)|grant income|rental income|profit on (?:asset )?disposal)\b/',
            $name
        ) === 1
            ? self::INCOME_OTHER
            : self::INCOME_TURNOVER;
    }

    public function isSubcontractorCost(array $row): bool
    {
        $subtype = strtolower(trim((string)(
            $row['account_subtype_code']
                ?? $row['subtype_code']
                ?? ''
        )));
        if (in_array($subtype, [
            'subcontract',
            'subcontractor',
            'subcontractors',
            'subcontracting',
            'subcontract_labour',
            'subcontractor_labour',
        ], true)) {
            return true;
        }

        $name = strtolower(trim((string)($row['name'] ?? $row['label'] ?? '')));
        return preg_match('/\bsub[\s-]?contract(?:or|ors|ing|ed)?\b/', $name) === 1;
    }
}
