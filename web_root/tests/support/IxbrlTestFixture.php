<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

function ixbrl_test_ensure_frs105_thresholds(): void
{
    (new \eel_accounts\Service\TaxRateRuleService())->ensureSchema();
    foreach ([
        ['turnover', 632000.0],
        ['balance_sheet_total', 316000.0],
        ['employees', 10.0],
    ] as [$key, $amount]) {
        if ((int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM tax_rate_rules
             WHERE tax_domain = :domain AND regime = :regime
               AND rule_key = :rule_key AND period_start = :period_start',
            [
                'domain' => 'company_size',
                'regime' => 'frs105_micro_entity',
                'rule_key' => $key,
                'period_start' => '1900-01-01',
            ]
        ) > 0) {
            continue;
        }

        InterfaceDB::prepareExecute(
            'INSERT INTO tax_rate_rules (
                tax_domain, regime, rule_key, rule_label, period_start, period_end, value_type,
                amount_value, source_url, source_checked_at, rule_version, is_active, notes
             ) VALUES (
                :domain, :regime, :rule_key, :label, :period_start, :period_end, :value_type,
                :amount, :source_url, :checked_at, :version, 1, :notes
             )',
            [
                'domain' => 'company_size',
                'regime' => 'frs105_micro_entity',
                'rule_key' => $key,
                'label' => 'FRS 105 ' . $key,
                'period_start' => '1900-01-01',
                'period_end' => '2025-04-05',
                'value_type' => 'amount',
                'amount' => $amount,
                'source_url' => 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies',
                'checked_at' => '2026-07-17',
                'version' => 'fixture-frs105-' . $key,
                'notes' => 'Test fixture.',
            ]
        );
    }
}

function ixbrl_test_assign_sales_nominal(int $companyId): int
{
    $nominalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => '4000']
    );
    if ($nominalId <= 0) {
        InterfaceDB::prepareExecute(
            'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
             VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
            [
                'code' => '4000',
                'name' => 'Sales',
                'account_type' => 'income',
                'tax_treatment' => 'other',
                'sort_order' => 4000,
            ]
        );
        $nominalId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
            ['code' => '4000']
        );
    }

    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('default_sales_nominal_id', $nominalId, 'int');
    $settings->flush();

    return $nominalId;
}

/**
 * Give report-building fixtures valid Director Loan controls without making
 * unrelated bank or creditor balances part of the Director Loan Statement.
 * A fixture can supply either control when it needs to post against a custom
 * nominal; the other control remains the standard zero-balance nominal.
 */
function ixbrl_test_assign_director_loan_nominals(
    int $companyId,
    int $assetNominalId = 0,
    int $liabilityNominalId = 0
): array {
    StandardNominalTestFixture::ensureNominals(['1200', '2100']);
    $assetNominalId = $assetNominalId > 0
        ? $assetNominalId
        : StandardNominalTestFixture::id('1200');
    $liabilityNominalId = $liabilityNominalId > 0
        ? $liabilityNominalId
        : StandardNominalTestFixture::id('2100');

    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('director_loan_asset_nominal_id', $assetNominalId, 'int');
    $settings->set('director_loan_liability_nominal_id', $liabilityNominalId, 'int');
    $settings->flush();

    return ['asset' => $assetNominalId, 'liability' => $liabilityNominalId];
}

function ixbrl_test_complete_disclosures(int $companyId, int $accountingPeriodId, string $actor = 'test-fixture'): array
{
    ixbrl_test_ensure_frs105_thresholds();
    $period = InterfaceDB::fetchOne(
        'SELECT period_end FROM accounting_periods WHERE id = :period_id AND company_id = :company_id',
        ['period_id' => $accountingPeriodId, 'company_id' => $companyId]
    );
    if (!is_array($period)) {
        throw new RuntimeException('The accounting period is unavailable for the iXBRL disclosure fixture.');
    }
    InterfaceDB::prepareExecute(
        'UPDATE companies SET
            company_status = COALESCE(NULLIF(company_status, \'\'), :company_status),
            companies_house_type = COALESCE(NULLIF(companies_house_type, \'\'), :company_type),
            companies_house_jurisdiction = COALESCE(NULLIF(companies_house_jurisdiction, \'\'), :jurisdiction),
            registered_office_address_line_1 = COALESCE(NULLIF(registered_office_address_line_1, \'\'), :address_line_1),
            registered_office_locality = COALESCE(NULLIF(registered_office_locality, \'\'), :locality),
            registered_office_postal_code = COALESCE(NULLIF(registered_office_postal_code, \'\'), :postal_code),
            registered_office_country = COALESCE(NULLIF(registered_office_country, \'\'), :country)
         WHERE id = :company_id',
        [
            'company_status' => 'active',
            'company_type' => 'ltd',
            'jurisdiction' => 'england-wales',
            'address_line_1' => '1 Test Street',
            'locality' => 'Test Town',
            'postal_code' => 'TE1 1ST',
            'country' => 'United Kingdom',
            'company_id' => $companyId,
        ]
    );
    $result = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->save(
        $companyId,
        $accountingPeriodId,
        [
            'accounting_standard' => 'FRS_105',
            'average_number_employees' => 1,
            'entity_dormant' => 0,
            'is_still_trading' => 1,
            'micro_entity_eligibility_confirmed' => 1,
            'going_concern_basis_appropriate' => 1,
            'has_material_off_balance_sheet_arrangements' => 0,
            'has_director_advances_credits_or_guarantees' => 0,
            'has_financial_commitments_guarantees_or_contingencies' => 0,
            'accounts_approval_date' => (string)$period['period_end'],
            'approving_director_name' => 'Test Director',
            'prepared_under_small_companies_regime' => 1,
            'audit_exempt_section_477' => 1,
            'directors_acknowledge_responsibilities' => 1,
            'members_have_not_required_audit' => 1,
        ],
        $actor
    );
    if (empty($result['success']) || empty($result['complete'])) {
        throw new RuntimeException(implode(' ', (array)($result['errors'] ?? $result['profile_errors'] ?? $result['missing_labels'] ?? ['Unable to complete iXBRL disclosures.'])));
    }
    return $result;
}
