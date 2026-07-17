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
 * Filing profile for FRS 105 accounts using the FRC 2026 taxonomy suite.
 *
 * FRS 105 reporters use the FRS-102 entry point. Keeping the profile in one
 * place prevents the database seeds and renderer from drifting apart.
 */
final class IxbrlTaxonomyProfileService
{
    public const PROFILE = 'frc-2026-frs-105';
    public const BASIS_VERSION = 'ixbrl-accounts-v2';
    public const SCHEMA_REF = 'https://xbrl.frc.org.uk/FRS-102/2026-01-01/FRS-102-2026-01-01.xsd';

    public const NAMESPACES = [
        'bus' => 'http://xbrl.frc.org.uk/cd/2026-01-01/business',
        'countries' => 'http://xbrl.frc.org.uk/cd/2026-01-01/countries',
        'core' => 'http://xbrl.frc.org.uk/fr/2026-01-01/core',
        'direp' => 'http://xbrl.frc.org.uk/reports/2026-01-01/direp',
    ];

    /** @return array<int, array<string, mixed>> */
    public function mappings(): array
    {
        return [
            $this->mapping('entity_name', 'bus', 'EntityCurrentLegalOrRegisteredName', 'Entity name', 'text', 'company_field', 'company_name', 'duration', null, null, null, false, 10),
            $this->mapping('company_number', 'bus', 'UKCompaniesHouseRegisteredNumber', 'Company number', 'text', 'company_field', 'company_number', 'duration', null, null, null, false, 20),
            $this->mapping('country_formation_or_incorporation', 'bus', 'CountryFormationOrIncorporation', 'Country of formation or incorporation', 'text', 'fixed_marker', 'companies_house_jurisdiction', 'duration_country_formation', null, null, [
                'countries:CountriesRegionsDimension' => 'countries:EnglandWales',
            ], false, 21),
            $this->mapping('legal_form_entity', 'bus', 'LegalFormEntity', 'Legal form of entity', 'text', 'fixed_marker', 'companies_house_type', 'duration_legal_form', null, null, [
                'bus:LegalFormEntityDimension' => 'bus:PrivateLimitedCompanyLtd',
            ], false, 22),
            $this->mapping('registered_office_address_line_1', 'bus', 'AddressLine1', 'Registered office address line 1', 'text', 'company_field', 'registered_office_address_line_1', 'duration_registered_office', null, null, [
                'bus:EntityContactTypeDimension' => 'bus:RegisteredOffice',
                'countries:CountriesRegionsDimension' => 'countries:UnitedKingdom',
            ], false, 23),
            $this->mapping('registered_office_address_line_2', 'bus', 'AddressLine2', 'Registered office address line 2', 'text', 'company_field', 'registered_office_address_line_2', 'duration_registered_office', null, null, [
                'bus:EntityContactTypeDimension' => 'bus:RegisteredOffice',
                'countries:CountriesRegionsDimension' => 'countries:UnitedKingdom',
            ], false, 24),
            $this->mapping('registered_office_address_line_3', 'bus', 'AddressLine3', 'Registered office address line 3', 'text', 'company_field', 'registered_office_address_line_3', 'duration_registered_office', null, null, [
                'bus:EntityContactTypeDimension' => 'bus:RegisteredOffice',
                'countries:CountriesRegionsDimension' => 'countries:UnitedKingdom',
            ], false, 25),
            $this->mapping('registered_office_postal_code', 'bus', 'PostalCodeZip', 'Registered office postal code', 'text', 'company_field', 'registered_office_postal_code', 'duration_registered_office', null, null, [
                'bus:EntityContactTypeDimension' => 'bus:RegisteredOffice',
                'countries:CountriesRegionsDimension' => 'countries:UnitedKingdom',
            ], false, 26),
            $this->mapping('period_start', 'bus', 'StartDateForPeriodCoveredByReport', 'Period start', 'date', 'period_field', 'period_start', 'instant_start', null, null, null, false, 30),
            $this->mapping('period_end', 'bus', 'EndDateForPeriodCoveredByReport', 'Period end', 'date', 'period_field', 'period_end', 'instant_end', null, null, null, false, 40),
            $this->mapping('balance_sheet_date', 'bus', 'BalanceSheetDate', 'Balance sheet date', 'date', 'period_field', 'period_end', 'instant_end', null, null, null, false, 50),
            $this->mapping('accounts_approval_date', 'core', 'DateAuthorisationFinancialStatementsForIssue', 'Accounts approval date', 'date', 'disclosure_field', 'accounts_approval_date', 'instant_approval', null, null, null, false, 60),
            $this->mapping('approving_director_name', 'bus', 'NameEntityOfficer', 'Director approving the financial statements', 'text', 'disclosure_field', 'approving_director_name', 'duration_director_1', null, null, [
                'bus:EntityOfficersDimension' => 'bus:Director1',
            ], false, 70),
            $this->mapping('director_signing_financial_statements', 'core', 'DirectorSigningFinancialStatements', 'Director signing financial statements', 'text', 'fixed_marker', 'approving_director_name', 'duration_director_1', null, null, [
                'bus:EntityOfficersDimension' => 'bus:Director1',
            ], false, 75),
            // EntityTradingDefault is the taxonomy default and must not be
            // emitted as an explicit member in an instance context.
            $this->mapping('entity_trading_status', 'bus', 'EntityTradingStatus', 'Entity trading status', 'text', 'fixed_marker', 'entity_trading_status', 'duration_trading_status', null, null, null, false, 80),
            $this->mapping('accounting_standards_applied', 'bus', 'AccountingStandardsApplied', 'Accounting standards applied', 'text', 'fixed_marker', 'accounting_standard', 'duration_accounting_standards', null, null, [
                'bus:AccountingStandardsDimension' => 'bus:Micro-entities',
            ], false, 85),
            $this->mapping('accounts_status', 'bus', 'AccountsStatusAuditedOrUnaudited', 'Accounts status audited or unaudited', 'text', 'fixed_marker', 'audit_exempt_section_477', 'duration_accounts_status', null, null, [
                'bus:AccountsStatusDimension' => 'bus:AuditExempt-NoAccountantsReport',
            ], false, 90),

            $this->mapping('turnover', 'core', 'TurnoverRevenue', 'Turnover', 'numeric', 'derived', 'turnover', 'duration', 'GBP', '2', null, true, 100),
            $this->mapping('other_income', 'core', 'OtherOperatingIncomeFormat2', 'Other income', 'numeric', 'derived', 'other_income', 'duration', 'GBP', '2', null, true, 110),
            $this->mapping('raw_materials_consumables', 'core', 'RawMaterialsConsumablesUsed', 'Raw materials and consumables', 'numeric', 'derived', 'raw_materials_consumables', 'duration', 'GBP', '2', null, true, 120),
            $this->mapping('staff_costs', 'core', 'StaffCostsEmployeeBenefitsExpense', 'Staff costs', 'numeric', 'derived', 'staff_costs', 'duration', 'GBP', '2', null, true, 130),
            $this->mapping('depreciation_write_offs', 'core', 'DepreciationAmortisationImpairmentExpense', 'Depreciation and other amounts written off assets', 'numeric', 'derived', 'depreciation_write_offs', 'duration', 'GBP', '2', null, true, 140),
            $this->mapping('other_charges', 'core', 'OtherExternalCharges', 'Other charges', 'numeric', 'derived', 'other_charges', 'duration', 'GBP', '2', null, true, 145),
            $this->mapping('tax_on_profit', 'core', 'TaxTaxCreditOnProfitOrLossOnOrdinaryActivities', 'Tax on profit / loss', 'numeric', 'derived', 'tax_on_profit', 'duration', 'GBP', '2', null, true, 150),
            $this->mapping('profit_loss', 'core', 'ProfitLoss', 'Profit / loss for the financial year', 'numeric', 'derived', 'profit_loss', 'duration', 'GBP', '2', null, true, 160),

            $this->mapping('fixed_assets', 'core', 'FixedAssets', 'Fixed assets', 'numeric', 'derived', 'fixed_assets', 'instant_end', 'GBP', '2', null, true, 200),
            $this->mapping('current_assets', 'core', 'CurrentAssets', 'Current assets', 'numeric', 'derived', 'current_assets', 'instant_end', 'GBP', '2', null, true, 210),
            $this->mapping('prepayments_accrued_income', 'core', 'PrepaymentsAccruedIncome', 'Prepayments and accrued income', 'numeric', 'derived', 'prepayments_accrued_income', 'instant_end', 'GBP', '2', null, true, 215),
            $this->mapping('creditors_within_one_year', 'core', 'Creditors', 'Creditors within one year', 'numeric', 'derived', 'creditors_within_one_year', 'instant_end_creditors_within', 'GBP', '2', [
                'core:MaturitiesOrExpirationPeriodsDimension' => 'core:WithinOneYear',
            ], true, 220),
            $this->mapping('net_current_assets_liabilities', 'core', 'NetCurrentAssetsLiabilities', 'Net current assets / liabilities', 'numeric', 'derived', 'net_current_assets_liabilities', 'instant_end', 'GBP', '2', null, true, 230),
            $this->mapping('total_assets_less_current_liabilities', 'core', 'TotalAssetsLessCurrentLiabilities', 'Total assets less current liabilities', 'numeric', 'derived', 'total_assets_less_current_liabilities', 'instant_end', 'GBP', '2', null, true, 240),
            $this->mapping('creditors_after_one_year', 'core', 'Creditors', 'Creditors after more than one year', 'numeric', 'derived', 'creditors_after_more_than_one_year', 'instant_end_creditors_after', 'GBP', '2', [
                'core:MaturitiesOrExpirationPeriodsDimension' => 'core:AfterOneYear',
            ], true, 250),
            $this->mapping('net_assets_liabilities', 'core', 'NetAssetsLiabilities', 'Net assets / liabilities', 'numeric', 'derived', 'net_assets_liabilities', 'instant_end', 'GBP', '2', null, true, 260),
            $this->mapping('equity', 'core', 'Equity', 'Equity', 'numeric', 'derived', 'equity_capital_reserves', 'instant_end', 'GBP', '2', null, true, 270),

            $this->mapping('average_number_employees', 'core', 'AverageNumberEmployeesDuringPeriod', 'Average number of employees', 'numeric', 'disclosure_field', 'average_number_employees', 'duration', 'pure', '0', null, true, 300),
            $this->mapping('entity_dormant', 'bus', 'EntityDormantTruefalse', 'Entity dormant', 'boolean', 'disclosure_field', 'entity_dormant', 'duration', null, null, null, false, 310),
            $this->mapping('small_companies_regime_statement', 'direp', 'StatementThatAccountsHaveBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime', 'Small companies regime statement', 'text', 'disclosure_statement', 'prepared_under_small_companies_regime', 'duration', null, null, null, false, 320),
            $this->mapping('audit_exemption_statement', 'direp', 'StatementThatCompanyEntitledToExemptionFromAuditUnderSection477CompaniesAct2006RelatingToSmallCompanies', 'Audit exemption statement', 'text', 'disclosure_statement', 'audit_exempt_section_477', 'duration', null, null, null, false, 330),
            $this->mapping('directors_responsibility_statement', 'direp', 'StatementThatDirectorsAcknowledgeTheirResponsibilitiesUnderCompaniesAct', 'Directors responsibilities statement', 'text', 'disclosure_statement', 'directors_acknowledge_responsibilities', 'duration', null, null, null, false, 340),
            $this->mapping('members_no_audit_statement', 'direp', 'StatementThatMembersHaveNotRequiredCompanyToObtainAnAudit', 'Members have not required an audit statement', 'text', 'disclosure_statement', 'members_have_not_required_audit', 'duration', null, null, null, false, 350),
            $this->mapping('no_material_off_balance_sheet_arrangements', 'core', 'GeneralDescriptionAnyOff-balanceSheetArrangementsIncludingNaturePurposeFinancialImpactOnEntity', 'No material off-balance sheet arrangements', 'text', 'absence_statement', 'has_material_off_balance_sheet_arrangements', 'duration', null, null, null, false, 360),
            $this->mapping('no_director_advances_or_credits', 'direp', 'GeneralDescriptionAdvancesCreditsToDirectorsIncludingTermsInterestRates', 'No advances or credits to directors', 'text', 'absence_statement', 'has_director_advances_credits_or_guarantees', 'duration', null, null, null, false, 361),
            $this->mapping('no_director_guarantees', 'direp', 'GeneralDescriptionGuaranteesTheirTermsDirectors', 'No guarantees on behalf of directors', 'text', 'absence_statement', 'has_director_advances_credits_or_guarantees', 'duration', null, null, null, false, 362),
            $this->mapping('no_capital_commitments', 'core', 'DescriptionCapitalCommitments', 'No capital commitments', 'text', 'absence_statement', 'has_financial_commitments_guarantees_or_contingencies', 'duration', null, null, null, false, 363),
            $this->mapping('no_financial_commitments', 'core', 'DescriptionFinancialCommitmentsOtherThanCapitalCommitments', 'No other financial commitments', 'text', 'absence_statement', 'has_financial_commitments_guarantees_or_contingencies', 'duration', null, null, null, false, 364),
            $this->mapping('no_contingent_liabilities', 'core', 'GeneralDescriptionContingentLiabilitiesIncludingFinancialEffectUncertaintiesPossibleReimbursement', 'No contingent liabilities', 'text', 'absence_statement', 'has_financial_commitments_guarantees_or_contingencies', 'duration', null, null, null, false, 365),
            $this->mapping('production_software', 'bus', 'NameProductionSoftware', 'Production software', 'text', 'application_value', 'app_name', 'duration', null, null, null, false, 370),
            $this->mapping('production_software_version', 'bus', 'VersionProductionSoftware', 'Production software version', 'text', 'application_value', 'app_version', 'duration', null, null, null, false, 371),
        ];
    }

    public function statementText(string $sourceKey): string
    {
        return match ($sourceKey) {
            'prepared_under_small_companies_regime' => 'These accounts have been prepared in accordance with the micro-entity provisions and delivered in accordance with the provisions applicable to companies subject to the small companies regime.',
            'audit_exempt_section_477' => 'For the financial year the company was entitled to exemption from audit under section 477 of the Companies Act 2006 relating to small companies.',
            'directors_acknowledge_responsibilities' => 'The directors acknowledge their responsibilities for complying with the requirements of the Companies Act 2006 with respect to accounting records and the preparation of accounts.',
            'members_have_not_required_audit' => 'The members have not required the company to obtain an audit of its accounts for the financial year in accordance with section 476 of the Companies Act 2006.',
            default => '',
        };
    }

    public function absenceStatementText(string $factKey): string
    {
        return match ($factKey) {
            'no_material_off_balance_sheet_arrangements' => 'None. The company had no material off-balance sheet arrangements.',
            'no_director_advances_or_credits' => 'None. The company made no advances or credits to directors.',
            'no_director_guarantees' => 'None. The company entered into no guarantees on behalf of directors.',
            'no_capital_commitments' => 'None. The company had no capital commitments.',
            'no_financial_commitments' => 'None. The company had no other financial commitments or guarantees.',
            'no_contingent_liabilities' => 'None. The company had no contingent liabilities.',
            default => '',
        };
    }

    /** @return array<string, mixed> */
    private function mapping(
        string $factKey,
        string $prefix,
        string $localName,
        string $label,
        string $valueType,
        string $calculationType,
        ?string $sourceKey,
        string $contextProfile,
        ?string $unitRef,
        ?string $decimals,
        ?array $dimensions,
        bool $comparativeEnabled,
        int $sortOrder
    ): array {
        return [
            'fact_key' => $factKey,
            'taxonomy_concept' => $prefix . ':' . $localName,
            'namespace_uri' => self::NAMESPACES[$prefix],
            'local_name' => $localName,
            'label' => $label,
            'value_type' => $valueType,
            'calculation_type' => $calculationType,
            'source_key' => $sourceKey,
            'sign_multiplier' => '1.00',
            'period_type' => str_starts_with($contextProfile, 'instant_') ? 'instant' : 'duration',
            'unit_ref' => $unitRef,
            'decimals_value' => $decimals,
            'context_profile' => $contextProfile,
            'dimensions_json' => $dimensions !== null
                ? json_encode($dimensions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : null,
            'comparative_enabled' => $comparativeEnabled ? 1 : 0,
            'is_required' => 1,
            'sort_order' => $sortOrder,
            'is_active' => 1,
        ];
    }
}
