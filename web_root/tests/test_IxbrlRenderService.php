<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlRenderService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlRenderService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlRenderService::class, 'refuses generation when no fact run exists', static function () use ($harness, $service): void {
            $result = $service->generatePreview(0, 0);
            $harness->assertSame(false, $result['success']);
        });

        $harness->check(\eel_accounts\Service\IxbrlRenderService::class, 'renders the FRC 2026 Inline XBRL profile with valid contexts units and signs', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlRenderService::class, 'renderXhtml');
            $method->setAccessible(true);
            $xhtml = (string)$method->invoke($service, ixbrlRenderFixtureFacts());

            $harness->assertTrue(str_contains($xhtml, '<ix:header>'));
            $harness->assertTrue(str_contains($xhtml, 'FRS-102/2026-01-01/FRS-102-2026-01-01.xsd'));
            $harness->assertTrue(str_contains($xhtml, 'xmlns:core="http://xbrl.frc.org.uk/fr/2026-01-01/core"'));
            $harness->assertTrue(str_contains($xhtml, 'xmlns:countries="http://xbrl.frc.org.uk/cd/2026-01-01/countries"'));
            $harness->assertTrue(str_contains($xhtml, '<xbrli:unit id="pure"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonFraction name="core:FixedAssets"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonNumeric name="direp:StatementThatAccountsHaveBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime"'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="core:MaturitiesOrExpirationPeriodsDimension">core:WithinOneYear'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:EntityOfficersDimension">bus:Director1'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:AccountingStandardsDimension">bus:Micro-entities'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:AccountsStatusDimension">bus:AuditExempt-NoAccountantsReport'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="countries:CountriesRegionsDimension">countries:EnglandWales'));
            $harness->assertTrue(str_contains($xhtml, 'dimension="bus:EntityContactTypeDimension">bus:RegisteredOffice'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonNumeric name="core:DirectorSigningFinancialStatements"'));
            $harness->assertTrue(str_contains($xhtml, '<ix:nonNumeric name="bus:EntityTradingStatus" contextRef="current_period_duration"></ix:nonNumeric>'));
            $harness->assertTrue(str_contains($xhtml, 'name="core:ProfitLoss" contextRef="current_period_duration" unitRef="GBP" decimals="2" format="ixt:numdotdecimal" sign="-">127.11'));
            $harness->assertFalse(str_contains($xhtml, '<section'));
            $harness->assertFalse(str_contains($xhtml, ' lang="en"'));

            $validator = new ReflectionMethod(\eel_accounts\Service\IxbrlRenderService::class, 'validateInlineXbrl');
            $validator->setAccessible(true);
            $harness->assertSame([], $validator->invoke($service, $xhtml));
        });

        $harness->check(\eel_accounts\Service\IxbrlRenderService::class, 'rejects a required fact that exists only in a comparative context', static function () use ($harness, $service): void {
            $facts = ixbrlRenderFixtureFacts();
            foreach ($facts as &$fact) {
                if ((string)$fact['fact_key'] === 'accounts_status') {
                    $fact['context_ref'] = 'comparative_period_duration_accounts_status';
                }
            }
            unset($fact);
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlRenderService::class, 'renderXhtml');
            $method->setAccessible(true);
            $thrown = false;
            try {
                $method->invoke($service, $facts);
            } catch (Throwable $exception) {
                $thrown = str_contains($exception->getMessage(), 'accounts_status');
            }
            $harness->assertTrue($thrown);
        });

        $harness->check(\eel_accounts\Service\IxbrlRenderService::class, 'renders the prior locked period employee disclosure as a comparative', static function () use ($harness, $service): void {
            $facts = ixbrlRenderFixtureFacts();
            $comparativeKeys = [];
            foreach ((new \eel_accounts\Service\IxbrlTaxonomyProfileService())->mappings() as $mapping) {
                if (!empty($mapping['comparative_enabled'])) {
                    $comparativeKeys[(string)$mapping['fact_key']] = true;
                }
            }
            foreach ($facts as $fact) {
                if (!isset($comparativeKeys[(string)$fact['fact_key']])) {
                    continue;
                }
                $comparative = $fact;
                $comparative['context_ref'] = str_replace('current_', 'comparative_', (string)$fact['context_ref']);
                $comparative['source_json'] = json_encode(['period_start' => '2024-01-01', 'period_end' => '2024-12-31']);
                if ((string)$fact['fact_key'] === 'average_number_employees') {
                    $comparative['numeric_value'] = 3.0;
                }
                $facts[] = $comparative;
            }
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlRenderService::class, 'renderXhtml');
            $method->setAccessible(true);
            $xhtml = (string)$method->invoke($service, $facts);
            $harness->assertTrue(str_contains($xhtml, 'name="core:AverageNumberEmployeesDuringPeriod" contextRef="comparative_period_duration"'));
            $harness->assertTrue(str_contains($xhtml, '(comparative:'));
        });

        $harness->check(\eel_accounts\Service\IxbrlRenderService::class, 'rejects a missing comparative fact when a prior locked period exists', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\IxbrlRenderService::class, 'renderXhtml');
            $method->setAccessible(true);
            $thrown = false;
            try {
                $method->invoke($service, ixbrlRenderFixtureFacts(), true);
            } catch (Throwable $exception) {
                $thrown = str_contains($exception->getMessage(), 'comparative-period')
                    && str_contains($exception->getMessage(), 'turnover');
            }
            $harness->assertTrue($thrown);
        });
    }
);

function ixbrlRenderFixtureFacts(): array
{
    return [
        ixbrlRenderFact('entity_name', 'bus:EntityCurrentLegalOrRegisteredName', 'text', null, 'Example Limited', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('company_number', 'bus:UKCompaniesHouseRegisteredNumber', 'text', null, '01234567', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('country_formation_or_incorporation', 'bus:CountryFormationOrIncorporation', 'text', null, '', null, null, null, 'current_period_duration_country_formation'),
        ixbrlRenderFact('legal_form_entity', 'bus:LegalFormEntity', 'text', null, '', null, null, null, 'current_period_duration_legal_form'),
        ixbrlRenderFact('registered_office_address_line_1', 'bus:AddressLine1', 'text', null, '1 Example Street', null, null, null, 'current_period_duration_registered_office'),
        ixbrlRenderFact('registered_office_address_line_2', 'bus:AddressLine2', 'text', null, 'Example Park', null, null, null, 'current_period_duration_registered_office'),
        ixbrlRenderFact('registered_office_address_line_3', 'bus:AddressLine3', 'text', null, 'London', null, null, null, 'current_period_duration_registered_office'),
        ixbrlRenderFact('registered_office_postal_code', 'bus:PostalCodeZip', 'text', null, 'SW1A 1AA', null, null, null, 'current_period_duration_registered_office'),
        ixbrlRenderFact('period_start', 'bus:StartDateForPeriodCoveredByReport', 'date', null, null, '2025-01-01', null, null, 'current_period_start'),
        ixbrlRenderFact('period_end', 'bus:EndDateForPeriodCoveredByReport', 'date', null, null, '2025-12-31', null, null, 'current_period_end'),
        ixbrlRenderFact('balance_sheet_date', 'bus:BalanceSheetDate', 'date', null, null, '2025-12-31', null, null, 'current_period_end'),
        ixbrlRenderFact('accounts_approval_date', 'core:DateAuthorisationFinancialStatementsForIssue', 'date', null, null, '2026-03-01', null, null, 'accounts_approval_date'),
        ixbrlRenderFact('approving_director_name', 'bus:NameEntityOfficer', 'text', null, 'Example Director', null, null, null, 'current_period_duration_director_1'),
        ixbrlRenderFact('director_signing_financial_statements', 'core:DirectorSigningFinancialStatements', 'text', null, '', null, null, null, 'current_period_duration_director_1'),
        ixbrlRenderFact('entity_trading_status', 'bus:EntityTradingStatus', 'text', null, '', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('accounting_standards_applied', 'bus:AccountingStandardsApplied', 'text', null, '', null, null, null, 'current_period_duration_accounting_standards'),
        ixbrlRenderFact('accounts_status', 'bus:AccountsStatusAuditedOrUnaudited', 'text', null, '', null, null, null, 'current_period_duration_accounts_status'),
        ixbrlRenderFact('turnover', 'core:TurnoverRevenue', 'numeric', 1000.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('other_income', 'core:OtherOperatingIncomeFormat2', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('raw_materials_consumables', 'core:RawMaterialsConsumablesUsed', 'numeric', 100.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('staff_costs', 'core:StaffCostsEmployeeBenefitsExpense', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('depreciation_write_offs', 'core:DepreciationAmortisationImpairmentExpense', 'numeric', 27.11, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('other_charges', 'core:OtherExternalCharges', 'numeric', 1000.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('tax_on_profit', 'core:TaxTaxCreditOnProfitOrLossOnOrdinaryActivities', 'numeric', 0.0, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('profit_loss', 'core:ProfitLoss', 'numeric', -127.11, null, null, 'GBP', '2', 'current_period_duration'),
        ixbrlRenderFact('fixed_assets', 'core:FixedAssets', 'numeric', 1000.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('current_assets', 'core:CurrentAssets', 'numeric', 475.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('prepayments_accrued_income', 'core:PrepaymentsAccruedIncome', 'numeric', 25.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('creditors_within_one_year', 'core:Creditors', 'numeric', 50.0, null, null, 'GBP', '2', 'current_period_end_creditors_within_one_year'),
        ixbrlRenderFact('net_current_assets_liabilities', 'core:NetCurrentAssetsLiabilities', 'numeric', 450.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('total_assets_less_current_liabilities', 'core:TotalAssetsLessCurrentLiabilities', 'numeric', 1450.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('creditors_after_one_year', 'core:Creditors', 'numeric', 400.0, null, null, 'GBP', '2', 'current_period_end_creditors_after_one_year'),
        ixbrlRenderFact('net_assets_liabilities', 'core:NetAssetsLiabilities', 'numeric', 1050.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('equity', 'core:Equity', 'numeric', 1050.0, null, null, 'GBP', '2', 'current_period_end'),
        ixbrlRenderFact('average_number_employees', 'core:AverageNumberEmployeesDuringPeriod', 'numeric', 1.0, null, null, 'pure', '0', 'current_period_duration'),
        ixbrlRenderFact('entity_dormant', 'bus:EntityDormantTruefalse', 'boolean', null, 'false', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('small_companies_regime_statement', 'direp:StatementThatAccountsHaveBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime', 'text', null, 'Prepared under the small companies regime.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('audit_exemption_statement', 'direp:StatementThatCompanyEntitledToExemptionFromAuditUnderSection477CompaniesAct2006RelatingToSmallCompanies', 'text', null, 'The company is entitled to audit exemption.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('directors_responsibility_statement', 'direp:StatementThatDirectorsAcknowledgeTheirResponsibilitiesUnderCompaniesAct', 'text', null, 'The directors acknowledge their responsibilities.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('members_no_audit_statement', 'direp:StatementThatMembersHaveNotRequiredCompanyToObtainAnAudit', 'text', null, 'The members have not required an audit.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_material_off_balance_sheet_arrangements', 'core:GeneralDescriptionAnyOff-balanceSheetArrangementsIncludingNaturePurposeFinancialImpactOnEntity', 'text', null, 'None. The company had no material off-balance sheet arrangements.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_director_advances_or_credits', 'direp:GeneralDescriptionAdvancesCreditsToDirectorsIncludingTermsInterestRates', 'text', null, 'None. The company made no advances or credits to directors.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_director_guarantees', 'direp:GeneralDescriptionGuaranteesTheirTermsDirectors', 'text', null, 'None. The company entered into no guarantees on behalf of directors.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_capital_commitments', 'core:DescriptionCapitalCommitments', 'text', null, 'None. The company had no capital commitments.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_financial_commitments', 'core:DescriptionFinancialCommitmentsOtherThanCapitalCommitments', 'text', null, 'None. The company had no other financial commitments or guarantees.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('no_contingent_liabilities', 'core:GeneralDescriptionContingentLiabilitiesIncludingFinancialEffectUncertaintiesPossibleReimbursement', 'text', null, 'None. The company had no contingent liabilities.', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('production_software', 'bus:NameProductionSoftware', 'text', null, 'EEL Accounts', null, null, null, 'current_period_duration'),
        ixbrlRenderFact('production_software_version', 'bus:VersionProductionSoftware', 'text', null, 'ixbrl-accounts-v2', null, null, null, 'current_period_duration'),
    ];
}

function ixbrlRenderFact(
    string $key,
    string $concept,
    string $type,
    ?float $numeric,
    ?string $text,
    ?string $date,
    ?string $unit,
    ?string $decimals,
    string $context
): array {
    return [
        'fact_key' => $key,
        'taxonomy_concept' => $concept,
        'label' => $key,
        'value_type' => $type,
        'numeric_value' => $numeric,
        'text_value' => $text,
        'date_value' => $date,
        'unit_ref' => $unit,
        'decimals_value' => $decimals,
        'context_ref' => $context,
        'source_json' => json_encode(['period_start' => '2025-01-01', 'period_end' => '2025-12-31']),
    ];
}
