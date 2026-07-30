<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlTaxonomyProfileService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlTaxonomyProfileService $service): void {
        $harness->check($service::class, 'exposes the required FRS 105 taxonomy profile and absence text', static function () use ($harness, $service): void {
            $mappings = $service->mappings();
            $harness->assertTrue(count($mappings) > 20);
            $harness->assertSame('The company made no advances or credits (including loans) to directors during the period.', $service->absenceStatementText('no_director_advances_or_credits'));
            $harness->assertSame('', $service->statementText('unknown'));
        });

        $harness->check($service::class, 'uses the exact 2026 fixed facts and statutory balance-sheet concepts', static function () use ($harness, $service): void {
            $mappings = [];
            foreach ($service->mappings() as $mapping) {
                $mappings[(string)$mapping['fact_key']] = $mapping;
            }

            $harness->assertSame('bus:AccountsType', (string)$mappings['accounts_type']['taxonomy_concept']);
            $harness->assertSame(
                ['bus:AccountsTypeDimension' => 'bus:FullAccounts'],
                json_decode((string)$mappings['accounts_type']['dimensions_json'], true)
            );
            $harness->assertSame('duration', (string)$mappings['accounts_type']['period_type']);
            $harness->assertFalse(array_key_exists('companies_house_revision_explanation', $mappings));
            $harness->assertSame('duration_accounts_type', (string)$mappings['accounts_type']['context_profile']);
            $harness->assertSame(
                'bus:DescriptionPrincipalActivities',
                (string)$mappings['principal_activity_description']['taxonomy_concept']
            );
            $harness->assertSame(
                'principal_activity_statement',
                (string)$mappings['principal_activity_description']['source_key']
            );
            $harness->assertSame(0, (int)$mappings['principal_activity_description']['comparative_enabled']);
            $harness->assertSame(1, (int)$mappings['principal_activity_description']['is_required']);
            $harness->assertSame(
                'core:CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset',
                (string)$mappings['called_up_share_capital_not_paid']['taxonomy_concept']
            );
            $harness->assertSame(
                'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
                (string)$mappings['prepayments_accrued_income']['taxonomy_concept']
            );
            $harness->assertSame(
                'core:GrossProfitLoss',
                (string)$mappings['gross_profit_loss']['taxonomy_concept']
            );
            $harness->assertSame(
                'core:OperatingProfitLoss',
                (string)$mappings['operating_profit_loss']['taxonomy_concept']
            );
            $harness->assertSame(
                'profit_loss_before_tax',
                (string)$mappings['operating_profit_loss']['source_key']
            );
            $harness->assertSame(
                'core:ProvisionsForLiabilitiesBalanceSheetSubtotal',
                (string)$mappings['provisions_for_liabilities']['taxonomy_concept']
            );
            $harness->assertSame(
                'core:AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal',
                (string)$mappings['accruals_deferred_income']['taxonomy_concept']
            );
            foreach (['period_start', 'period_end', 'balance_sheet_date', 'accounts_approval_date'] as $factKey) {
                $harness->assertSame('instant_end', (string)$mappings[$factKey]['context_profile']);
            }
        });

        $harness->check($service::class, 'maps only taxonomy-backed director monetary totals', static function () use ($harness, $service): void {
            $mappings = [];
            foreach ($service->mappings() as $mapping) {
                $mappings[(string)$mapping['fact_key']] = $mapping;
            }

            $harness->assertSame(
                'direp:AdvancesCreditsMadeInPeriodDirectors',
                (string)$mappings['director_advances_made']['taxonomy_concept']
            );
            $harness->assertSame(
                'direp:AdvancesCreditsRepaidInPeriodDirectors',
                (string)$mappings['director_cash_repayments']['taxonomy_concept']
            );
            $harness->assertSame(
                'direp:AdvancesCreditsDirectors',
                (string)$mappings['director_closing_advance']['taxonomy_concept']
            );
            $harness->assertSame('total_cash_repayments', (string)$mappings['director_cash_repayments']['source_key']);
            foreach (['director_advances_made', 'director_cash_repayments', 'director_closing_advance'] as $factKey) {
                $harness->assertSame('director_loan_numeric', (string)$mappings[$factKey]['calculation_type']);
                $harness->assertSame('GBP', (string)$mappings[$factKey]['unit_ref']);
                $harness->assertSame('2', (string)$mappings[$factKey]['decimals_value']);
            }

            $concepts = implode(' ', array_column($service->mappings(), 'taxonomy_concept'));
            foreach (['SetOffDirectors', 'WrittenOffDirectors', 'WaivedDirectors'] as $inventedConcept) {
                $harness->assertSame(false, str_contains($concepts, $inventedConcept));
            }
            $statement = $service->directorLoanStatementText([
                'disclosures' => [[
                    'director_name' => 'Fixture Director',
                    'advances' => 120.0,
                    'cash_repayments' => 20.0,
                    'amounts_legally_set_off' => 10.0,
                    'amounts_written_off' => 0.0,
                    'amounts_waived' => 0.0,
                    'closing_company_to_director_balance' => 90.0,
                    'interest_rate' => '0%',
                    'main_terms' => 'Unsecured',
                    'repayment_conditions' => 'Originally repayable 12 months after each advance but repaid early during the period.',
                ]],
            ]);
            $harness->assertTrue(str_contains($statement, 'Cash repayments during the period were £20.00'));
            $harness->assertTrue(str_contains($statement, 'Amounts legally set off were £10.00'));
            $harness->assertTrue(str_contains($statement, 'Interest rate: 0%.'));
            $harness->assertTrue(str_contains(
                $statement,
                'Repayment conditions: Originally repayable 12 months after each advance but repaid early during the period.'
            ));
            $harness->assertSame(false, str_contains($statement, 'repaid or settled'));
        });

        $harness->check($service::class, 'composes each director-advance narrative term once', static function () use ($harness, $service): void {
            $statement = static function (array $overrides = []) use ($service): string {
                return $service->directorLoanStatementText([
                    'disclosures' => [array_replace([
                        'director_name' => 'Fixture Director',
                        'advances' => 120.0,
                        'cash_repayments' => 20.0,
                        'amounts_legally_set_off' => 0.0,
                        'amounts_written_off' => 0.0,
                        'amounts_waived' => 0.0,
                        'closing_company_to_director_balance' => 100.0,
                        'interest_rate' => '0%',
                        'main_terms' => 'Unsecured',
                        'repayment_conditions' => 'No fixed repayment date was agreed',
                    ], $overrides)],
                ]);
            };

            $zeroInterest = $statement();
            $harness->assertSame(1, substr_count($zeroInterest, 'Interest rate: 0%.'));
            $harness->assertSame(1, substr_count($zeroInterest, 'Unsecured.'));
            $harness->assertSame(1, substr_count($zeroInterest, 'No fixed repayment date was agreed.'));
            $harness->assertTrue(str_contains(
                $zeroInterest,
                'Main terms: Unsecured. Interest rate: 0%. Repayment conditions: No fixed repayment date was agreed.'
            ));

            $nonZeroInterest = $statement(['interest_rate' => '3.5%']);
            $harness->assertSame(1, substr_count($nonZeroInterest, 'Interest rate: 3.5%.'));
            $harness->assertSame(0, substr_count($nonZeroInterest, 'Interest rate: 0%.'));

            $noSecurity = $statement(['main_terms' => 'No security was provided']);
            $harness->assertSame(1, substr_count($noSecurity, 'Main terms: No security was provided.'));
            $harness->assertSame(0, substr_count($noSecurity, 'Main terms: .'));
        });
    }
);
