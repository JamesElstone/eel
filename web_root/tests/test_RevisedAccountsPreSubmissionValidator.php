<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'scripts'
    . DIRECTORY_SEPARATOR . 'ixbrl'
    . DIRECTORY_SEPARATOR . 'RevisedAccountsPreSubmissionValidator.php';
require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'scripts'
    . DIRECTORY_SEPARATOR . 'ixbrl'
    . DIRECTORY_SEPARATOR . 'validate-revised-accounts.php';

use eel_accounts\Scripts\Ixbrl\RevisedAccountsPreSubmissionValidator;
use eel_accounts\Service\IxbrlTaxonomyProfileService;

(new GeneratedServiceClassTestHarness())->run(
    RevisedAccountsPreSubmissionValidator::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $privateFixtureRoot = dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'files'
            . DIRECTORY_SEPARATOR . 'tmp'
            . DIRECTORY_SEPARATOR . 'ixbrl';
        $artifact = $privateFixtureRoot
            . DIRECTORY_SEPARATOR . 'generated'
            . DIRECTORY_SEPARATOR . 'integration-revised-accounts.xhtml';
        $responsePath = $privateFixtureRoot
            . DIRECTORY_SEPARATOR . 'companies_house'
            . DIRECTORY_SEPARATOR . 'get-submission-status-accepted.xml';
        if (!is_file($artifact) || !is_file($responsePath)) {
            $harness->skip(
                'Private revised-accounts validation fixtures are not installed below files/tmp/.'
            );
        }
        $sha256 = hash_file('sha256', $artifact);
        $harness->assertTrue(is_string($sha256));

        $arelle = [
            'ok' => true,
            'status' => 'passed',
            'validator' => 'arelle',
            'version' => 'Arelle test',
            'errors' => [],
            'warnings' => [],
            'log_path' => 'test.log',
            'duration_ms' => 1,
            'validated_sha256' => $sha256,
        ];
        $submissionNumber = '000001';
        $responseXml = file_get_contents($responsePath);
        $harness->assertTrue(is_string($responseXml));
        $companiesHouse = [
            'official' => true,
            'environment' => 'TEST',
            'status' => 'accepted',
            'validator' => 'Companies House XML Gateway',
            'artifact_sha256' => $sha256,
            'validated_at' => '2026-07-26T12:00:00Z',
            'errors' => [],
            'warnings' => [],
            'submission_number' => $submissionNumber,
            'response_transaction_id' => 'response-transaction-1',
            'response_artifact' => $responsePath,
            'response_artifact_sha256' => hash('sha256', $responseXml),
        ];
        $validator = new RevisedAccountsPreSubmissionValidator(
            (new IxbrlTaxonomyProfileService())->mappings()
        );

        $harness->check(
            RevisedAccountsPreSubmissionValidator::class,
            'confirms the current and superseded arithmetic without changing the figures',
            static function () use (
                $harness,
                $validator,
                $artifact,
                $arelle,
                $companiesHouse
            ): void {
                $report = $validator->validate($artifact, $arelle, $companiesHouse);
                $arithmetic = (array)$report['arithmetic_validation'];
                $values = (array)$arithmetic['evidence']['observed_values'];

                $harness->assertSame('PASS', $arithmetic['status']);
                foreach ([
                    'turnover' => 10025.44,
                    'raw_materials_consumables' => 4594.93,
                    'depreciation_write_offs' => 197.41,
                    'other_external_charges' => 5360.21,
                    'gross_profit_loss' => 5430.51,
                    'operating_profit_loss' => -127.11,
                    'profit_loss' => -127.11,
                    'fixed_assets' => 431.43,
                    'current_assets' => 1368.54,
                    'prepayments_accrued_income' => 140.55,
                    'creditors_within_one_year' => 1567.63,
                    'net_current_assets_liabilities' => -58.54,
                    'total_assets_less_current_liabilities' => 372.89,
                    'net_assets_liabilities' => 372.89,
                    'capital_and_reserves' => 372.89,
                    'superseded_current_assets' => 275.0,
                    'superseded_creditors_within_one_year' => 64.0,
                    'superseded_net_current_assets_liabilities' => 211.0,
                    'superseded_total_assets_less_current_liabilities' => 211.0,
                    'superseded_net_assets_liabilities' => 211.0,
                    'superseded_capital_and_reserves' => 211.0,
                ] as $key => $expected) {
                    $harness->assertSame($expected, (float)$values[$key]);
                }
                foreach ((array)$arithmetic['evidence']['checks'] as $check) {
                    $harness->assertSame('PASS', (string)$check['status']);
                }
            }
        );

        $harness->check(
            RevisedAccountsPreSubmissionValidator::class,
            'reports categorical marker strategies director identity contexts and approval-date context',
            static function () use (
                $harness,
                $validator,
                $artifact,
                $arelle,
                $companiesHouse
            ): void {
                $report = $validator->validate($artifact, $arelle, $companiesHouse);
                $validation = (array)$report['fact_context_unit_validation'];
                $harness->assertSame([], (array)$validation['errors']);
                $facts = (array)$validation['facts'];
                $byName = [];
                foreach ($facts as $fact) {
                    $byName[(string)$fact['name']][] = $fact;
                }

                $tradingStatus = (array)$byName['bus:EntityTradingStatus'][0];
                $harness->assertSame(true, (bool)$tradingStatus['empty']);
                $harness->assertSame(
                    'taxonomy_default_marker',
                    (string)$tradingStatus['empty_marker_strategy']
                );

                $signing = (array)$byName['core:DirectorSigningFinancialStatements'][0];
                $directorName = (array)$byName['bus:NameEntityOfficer'][0];
                $harness->assertSame('', (string)$signing['machine_value']);
                $harness->assertSame(
                    'dimensional_marker',
                    (string)$signing['empty_marker_strategy']
                );
                $harness->assertSame($signing['context_ref'], $directorName['context_ref']);
                $harness->assertTrue(trim((string)$directorName['machine_value']) !== '');

                $approval = (array)$byName[
                    'core:DateAuthorisationFinancialStatementsForIssue'
                ][0];
                $harness->assertSame('current_period_end', $approval['context_ref']);
                $harness->assertSame('2026-07-17', $approval['machine_value']);

                $contexts = [];
                foreach ((array)$validation['contexts'] as $context) {
                    $contexts[(string)$context['id']] = $context;
                }
                $directorContext = (array)$contexts[(string)$signing['context_ref']];
                $harness->assertSame(
                    'bus:EntityOfficersDimension',
                    (string)$directorContext['dimensions'][0]['dimension']
                );
                $harness->assertSame(
                    'bus:Director1',
                    (string)$directorContext['dimensions'][0]['member']
                );
                $harness->assertSame(2, count((array)$directorContext['facts']));
            }
        );

        $harness->check(
            RevisedAccountsPreSubmissionValidator::class,
            'keeps unavailable mandatory validation layers blocking and detects hash mismatch',
            static function () use (
                $harness,
                $validator,
                $artifact,
                $arelle,
                $companiesHouse
            ): void {
                $notOfficiallyValidated = $validator->validate($artifact, $arelle, null);
                $harness->assertSame('FAIL', $notOfficiallyValidated['overall_status']);
                $harness->assertSame(
                    1,
                    revised_accounts_validation_exit_code($notOfficiallyValidated)
                );
                $harness->assertSame(
                    'NOT RUN',
                    $notOfficiallyValidated['layers']['companies_house']['status']
                );

                $wrongArtifact = $companiesHouse;
                $wrongArtifact['artifact_sha256'] = str_repeat('0', 64);
                $mismatch = $validator->validate($artifact, $arelle, $wrongArtifact);
                $harness->assertSame('FAIL', $mismatch['overall_status']);
                $harness->assertSame(
                    'FAIL',
                    $mismatch['layers']['companies_house']['status']
                );

                $missingResponse = $companiesHouse;
                $missingResponse['response_artifact'] = $companiesHouse[
                    'response_artifact'
                ] . '.missing';
                $missingReport = $validator->validate(
                    $artifact,
                    $arelle,
                    $missingResponse
                );
                $harness->assertSame(
                    'FAIL',
                    $missingReport['layers']['companies_house']['status']
                );

                $wrongResponseHash = $companiesHouse;
                $wrongResponseHash['response_artifact_sha256'] = str_repeat('0', 64);
                $wrongResponseHashReport = $validator->validate(
                    $artifact,
                    $arelle,
                    $wrongResponseHash
                );
                $harness->assertSame(
                    'FAIL',
                    $wrongResponseHashReport['layers']['companies_house']['status']
                );

                $missingTransaction = $companiesHouse;
                unset($missingTransaction['response_transaction_id']);
                $missingTransactionReport = $validator->validate(
                    $artifact,
                    $arelle,
                    $missingTransaction
                );
                $harness->assertSame(
                    'FAIL',
                    $missingTransactionReport['layers']['companies_house']['status']
                );

                $wrongCompanyXml = str_replace(
                    '<CompanyNumber>14337285</CompanyNumber>',
                    '<CompanyNumber>99999999</CompanyNumber>',
                    (string)file_get_contents($companiesHouse['response_artifact'])
                );
                $wrongCompanyPath = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'companies-house-wrong-company-'
                        . bin2hex(random_bytes(3)) . '.xml'
                );
                file_put_contents($wrongCompanyPath, $wrongCompanyXml);
                $wrongCompany = $companiesHouse;
                $wrongCompany['response_artifact'] = $wrongCompanyPath;
                $wrongCompany['response_artifact_sha256'] = hash(
                    'sha256',
                    $wrongCompanyXml
                );
                $wrongCompanyReport = $validator->validate(
                    $artifact,
                    $arelle,
                    $wrongCompany
                );
                $harness->assertSame(
                    'FAIL',
                    $wrongCompanyReport['layers']['companies_house']['status']
                );

                $completed = $validator->validate(
                    $artifact,
                    $arelle,
                    $companiesHouse
                );
                $harness->assertSame('PASS', $completed['overall_status']);
                $harness->assertSame(0, revised_accounts_validation_exit_code($completed));

                $withReviewedWarning = $companiesHouse;
                $withReviewedWarning['warnings'] = [
                    'Example non-blocking official warning retained for review.',
                ];
                $completedWithWarning = $validator->validate(
                    $artifact,
                    $arelle,
                    $withReviewedWarning
                );
                $harness->assertSame(
                    'PASS WITH WARNINGS',
                    $completedWithWarning['overall_status']
                );
                $harness->assertSame(
                    0,
                    revised_accounts_validation_exit_code($completedWithWarning)
                );

                $rejectedXml = str_replace(
                    '<StatusCode>ACCEPT</StatusCode>',
                    '<StatusCode>REJECT</StatusCode>',
                    (string)file_get_contents($companiesHouse['response_artifact'])
                );
                $rejectedPath = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'companies-house-rejected-'
                        . bin2hex(random_bytes(3)) . '.xml'
                );
                file_put_contents($rejectedPath, $rejectedXml);
                $dishonestResult = $companiesHouse;
                $dishonestResult['response_artifact'] = $rejectedPath;
                $dishonestResult['response_artifact_sha256'] = hash(
                    'sha256',
                    $rejectedXml
                );
                $rejectedReport = $validator->validate(
                    $artifact,
                    $arelle,
                    $dishonestResult
                );
                $harness->assertSame(
                    'FAIL',
                    $rejectedReport['layers']['companies_house']['status']
                );
            }
        );

        $harness->check(
            RevisedAccountsPreSubmissionValidator::class,
            'selects reporting-period facts when comparative facts are also present',
            static function () use (
                $harness,
                $validator,
                $artifact,
                $arelle,
                $companiesHouse
            ): void {
                $source = file_get_contents($artifact);
                $harness->assertTrue(is_string($source));
                $comparativeContext = '<xbrli:context id="validator_test_comparative_duration">'
                    . '<xbrli:entity><xbrli:identifier scheme="http://www.companieshouse.gov.uk/">'
                    . '14337285</xbrli:identifier></xbrli:entity><xbrli:period>'
                    . '<xbrli:startDate>2021-09-05</xbrli:startDate>'
                    . '<xbrli:endDate>2022-09-04</xbrli:endDate>'
                    . '</xbrli:period></xbrli:context>';
                $comparativeFact = '<div class="validator-test-comparative">'
                    . '<ix:nonFraction name="core:TurnoverRevenue" '
                    . 'contextRef="validator_test_comparative_duration" '
                    . 'unitRef="GBP" decimals="2" format="ixt:numdotdecimal">'
                    . '999.00</ix:nonFraction></div>';
                $withContext = str_replace(
                    '</ix:resources>',
                    $comparativeContext . '</ix:resources>',
                    (string)$source
                );
                $comparativeSource = str_replace(
                    '</body>',
                    $comparativeFact . '</body>',
                    $withContext
                );
                $harness->assertTrue($comparativeSource !== $source);
                $comparativePath = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'comparative-revised-accounts-'
                        . bin2hex(random_bytes(3)) . '.xhtml'
                );
                file_put_contents($comparativePath, $comparativeSource);
                $comparativeHash = hash('sha256', $comparativeSource);
                $comparativeArelle = $arelle;
                $comparativeArelle['validated_sha256'] = $comparativeHash;
                $comparativeCompaniesHouse = $companiesHouse;
                $comparativeCompaniesHouse['artifact_sha256'] = $comparativeHash;

                $report = $validator->validate(
                    $comparativePath,
                    $comparativeArelle,
                    $comparativeCompaniesHouse
                );
                $harness->assertSame(
                    'PASS',
                    $report['layers']['arithmetic']['status']
                );
                $harness->assertSame(
                    10025.44,
                    (float)$report['arithmetic_validation']['evidence']
                        ['observed_values']['turnover']
                );
            }
        );

        $harness->check(
            RevisedAccountsPreSubmissionValidator::class,
            'fails when a required zero-valued Format 2 fact is removed',
            static function () use (
                $harness,
                $validator,
                $artifact,
                $arelle,
                $companiesHouse
            ): void {
                $source = file_get_contents($artifact);
                $harness->assertTrue(is_string($source));
                $zeroFact = '<ix:nonFraction name="core:OtherOperatingIncomeFormat2" '
                    . 'contextRef="current_period_duration" unitRef="GBP" '
                    . 'decimals="2" format="ixt:zerodash">-</ix:nonFraction>';
                $missingSource = str_replace($zeroFact, '', (string)$source);
                $harness->assertTrue($missingSource !== $source);
                $missingPath = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'missing-format2-zero-'
                        . bin2hex(random_bytes(3)) . '.xhtml'
                );
                file_put_contents($missingPath, $missingSource);
                $missingHash = hash('sha256', $missingSource);
                $missingArelle = $arelle;
                $missingArelle['validated_sha256'] = $missingHash;
                $missingCompaniesHouse = $companiesHouse;
                $missingCompaniesHouse['artifact_sha256'] = $missingHash;

                $report = $validator->validate(
                    $missingPath,
                    $missingArelle,
                    $missingCompaniesHouse
                );
                $harness->assertSame(
                    'FAIL',
                    $report['layers']['context_units']['status']
                );
                $harness->assertTrue(str_contains(
                    implode(
                        ' ',
                        (array)$report['layers']['context_units']['errors']
                    ),
                    'core:OtherOperatingIncomeFormat2'
                ));
                $harness->assertSame(
                    'FAIL',
                    $report['layers']['arithmetic']['status']
                );
            }
        );

        $harness->check(
            RevisedAccountsPreSubmissionValidator::class,
            'rejects protocol-relative resources CSS imports and meta refresh',
            static function () use (
                $harness,
                $validator,
                $artifact,
                $arelle,
                $companiesHouse
            ): void {
                $source = file_get_contents($artifact);
                $harness->assertTrue(is_string($source));
                $externalMarkup = '<meta http-equiv="refresh" '
                    . 'content="0; URL=//example.invalid/redirect"/>'
                    . '<img src="//example.invalid/preview.png" alt=""/>';
                $externalSource = str_replace(
                    '</head>',
                    $externalMarkup . '</head>',
                    str_replace(
                        '</style>',
                        '@import url(//example.invalid/external.css);</style>',
                        (string)$source
                    )
                );
                $externalPath = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'external-resource-revised-accounts-'
                        . bin2hex(random_bytes(3)) . '.xhtml'
                );
                file_put_contents($externalPath, $externalSource);
                $externalHash = hash('sha256', $externalSource);
                $externalArelle = $arelle;
                $externalArelle['validated_sha256'] = $externalHash;
                $externalCompaniesHouse = $companiesHouse;
                $externalCompaniesHouse['artifact_sha256'] = $externalHash;

                $report = $validator->validate(
                    $externalPath,
                    $externalArelle,
                    $externalCompaniesHouse
                );
                $harness->assertSame(
                    'FAIL',
                    $report['layers']['xhtml_inline_xbrl']['status']
                );
                $harness->assertSame(
                    false,
                    (bool)$report['layers']['xhtml_inline_xbrl']
                        ['evidence']['self_contained']
                );
            }
        );

        $harness->check(
            RevisedAccountsPreSubmissionValidator::class,
            'detects exact duplicate facts and visibly positive negative facts',
            static function () use (
                $harness,
                $validator,
                $artifact,
                $arelle,
                $companiesHouse
            ): void {
                $source = file_get_contents($artifact);
                $harness->assertTrue(is_string($source));
                $profitFact = '<ix:nonFraction name="core:ProfitLoss" contextRef="current_period_duration" unitRef="GBP" decimals="2" format="ixt:numdotdecimal" sign="-">127.11</ix:nonFraction>';
                $duplicateSource = str_replace(
                    $profitFact,
                    $profitFact . $profitFact,
                    (string)$source
                );
                $harness->assertTrue($duplicateSource !== $source);
                $duplicatePath = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'duplicate-revised-accounts-' . bin2hex(random_bytes(3)) . '.xhtml'
                );
                file_put_contents($duplicatePath, $duplicateSource);
                $duplicateCompaniesHouse = $companiesHouse;
                $duplicateCompaniesHouse['artifact_sha256'] = hash(
                    'sha256',
                    $duplicateSource
                );
                $duplicateReport = $validator->validate(
                    $duplicatePath,
                    $arelle,
                    $duplicateCompaniesHouse
                );
                $harness->assertSame(
                    'FAIL',
                    $duplicateReport['layers']['duplicates']['status']
                );

                $positiveSource = str_replace(
                    '(' . $profitFact . ')',
                    $profitFact,
                    (string)$source
                );
                $harness->assertTrue($positiveSource !== $source);
                $positivePath = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'positive-loss-revised-accounts-' . bin2hex(random_bytes(3)) . '.xhtml'
                );
                file_put_contents($positivePath, $positiveSource);
                $positiveCompaniesHouse = $companiesHouse;
                $positiveCompaniesHouse['artifact_sha256'] = hash('sha256', $positiveSource);
                $positiveReport = $validator->validate(
                    $positivePath,
                    $arelle,
                    $positiveCompaniesHouse
                );
                $harness->assertSame(
                    'FAIL',
                    $positiveReport['layers']['visible_tagged_reconciliation']['status']
                );
            }
        );

        $harness->check(
            RevisedAccountsPreSubmissionValidator::class,
            'writes separate machine-readable reports with every financial-table total tagged',
            static function () use (
                $harness,
                $validator,
                $artifact,
                $arelle,
                $companiesHouse
            ): void {
                $report = $validator->validate($artifact, $arelle, $companiesHouse);
                $directory = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'revised-accounts-reports-' . bin2hex(random_bytes(3))
                );
                $files = revised_accounts_write_reports($report, $directory);
                $harness->assertSame(3, count($files));
                $bundleDirectories = [];
                foreach ($files as $file) {
                    $harness->assertTrue(is_file($file));
                    $bundleDirectories[dirname($file)] = true;
                    $decoded = json_decode(
                        (string)file_get_contents($file),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    $harness->assertTrue(is_array($decoded));
                }
                $harness->assertSame(1, count($bundleDirectories));
                $secondFiles = revised_accounts_write_reports($report, $directory);
                $harness->assertTrue(
                    dirname((string)reset($secondFiles))
                        !== dirname((string)reset($files))
                );
                foreach ($secondFiles as $file) {
                    $harness->assertTrue(is_file($file));
                }
                $untagged = (array)$report[
                    'visible_tagged_reconciliation'
                ]['evidence']['untagged_visible_numbers'];
                $harness->assertSame([], $untagged);

                $rows = (array)$report[
                    'visible_tagged_reconciliation'
                ]['evidence']['rows'];
                $concepts = array_column($rows, 'xbrl_concept');
                $harness->assertTrue(in_array('core:GrossProfitLoss', $concepts, true));
                $harness->assertTrue(in_array('core:OperatingProfitLoss', $concepts, true));
            }
        );
    }
);
