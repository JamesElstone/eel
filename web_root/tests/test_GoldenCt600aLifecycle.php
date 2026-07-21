<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'IxbrlTestFixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$harness->check('GoldenCt600aLifecycle', 'reconciles transaction-backed CT600A evidence through filing, artifacts and later relief', static function () use ($harness): void {
    $companyId = GoldenAccountsFixture::CT600A_COMPANY_ID;
    $accountingPeriodId = GoldenAccountsFixture::CT600A_ACCOUNTING_PERIOD_ID;
    $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
    $sync = $periodService->syncForAccountingPeriod($companyId, $accountingPeriodId);
    goldenCt600aRequireSuccess($sync);
    test_confirm_ct_period_facts($companyId, $accountingPeriodId);
    goldenCt600aCompleteFilingInputs($companyId, $accountingPeriodId);

    $readiness = (new \eel_accounts\Service\YearEndTaxReadinessService())
        ->fetchAccountingPeriodCtSummary($companyId, $accountingPeriodId);
    $harness->assertTrue(!empty($readiness['available']));
    $harness->assertCount(2, (array)$readiness['periods']);
    $first = goldenCt600aPeriodBySequence((array)$readiness['periods'], 1);
    $second = goldenCt600aPeriodBySequence((array)$readiness['periods'], 2);
    foreach (['A15' => 8000.0, 'A20' => 2700.0, 'A45' => 1687.5, 'A70' => 0.0, 'A75' => 8000.0, 'A80' => 1012.5] as $box => $amount) {
        $harness->assertSame($amount, (float)(($first['ct600a_amounts'] ?? [])[$box] ?? -1));
    }
    foreach (['A15', 'A20', 'A45', 'A70', 'A80'] as $box) {
        $harness->assertSame(0.0, (float)(($second['ct600a_amounts'] ?? [])[$box] ?? -1));
    }
    $harness->assertSame(8000.0, (float)(($second['ct600a_amounts'] ?? [])['A75'] ?? -1));
    $harness->assertTrue((float)($readiness['totals']['ordinary_corporation_tax'] ?? 0) > 0.0);
    $harness->assertSame(1012.5, (float)($readiness['totals']['ct600a_tax'] ?? -1));
    $harness->assertSame(
        round((float)$readiness['totals']['ordinary_corporation_tax'] + 1012.5, 2),
        (float)($readiness['totals']['estimated_corporation_tax'] ?? -1)
    );

    $provision = (new \eel_accounts\Service\CorporationTaxProvisionService())
        ->fetchAccountingPeriodPosition($companyId, $accountingPeriodId, (array)$readiness['periods']);
    $harness->assertTrue(!empty($provision['available']));
    $harness->assertSame(
        (float)$readiness['totals']['estimated_corporation_tax'],
        (float)($provision['estimated_corporation_tax'] ?? -1)
    );
    $profitAndLoss = (new \eel_accounts\Service\ProfitLossService())
        ->getProfitLossSummary($companyId, $accountingPeriodId);
    $harness->assertSame(1012.5, (float)($profitAndLoss['ct600a_tax'] ?? -1));
    $harness->assertSame(
        (float)$readiness['totals']['estimated_corporation_tax'],
        (float)($profitAndLoss['estimated_corporation_tax'] ?? -1)
    );

    InterfaceDB::beginTransaction();
    try {
        goldenCt600aPostLateRepayment();
        $periods = $periodService->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        $open = (new \eel_accounts\Service\Ct600aService())->build(
            $companyId,
            $accountingPeriodId,
            (int)$periods[0]['id'],
            '2099-01-01'
        );
        $harness->assertSame(675.0, (float)($open['part3']['relief_due'] ?? -1));
        $harness->assertSame(337.5, (float)($open['tax_payable'] ?? -1));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }

    goldenCt600aFreezeAndApprove($companyId, $accountingPeriodId);
    $periods = $periodService->fetchForAccountingPeriod($companyId, $accountingPeriodId);
    $harness->assertCount(2, $periods);
    $firstCtPeriodId = (int)$periods[0]['id'];
    $secondCtPeriodId = (int)$periods[1]['id'];
    $filingService = new \eel_accounts\Service\CtPeriodFilingModelService();
    $firstFiling = $filingService->build($companyId, $accountingPeriodId, $firstCtPeriodId);
    $secondFiling = $filingService->build($companyId, $accountingPeriodId, $secondCtPeriodId);
    $harness->assertTrue(!empty($firstFiling['available']));
    $harness->assertTrue(!empty($secondFiling['available']));
    $harness->assertTrue((string)$firstFiling['basis_hash'] !== (string)$secondFiling['basis_hash']);
    $harness->assertSame(1012.5, (float)($firstFiling['model']['ct600a']['tax_payable'] ?? -1));
    $harness->assertSame(false, (bool)($periodService->canSubmit($companyId, $secondCtPeriodId)['ok'] ?? true));

    $ct600 = goldenCt600aCt600Builder($firstFiling)->buildForIds(
        $companyId,
        $accountingPeriodId,
        $firstCtPeriodId,
        ['declaration_confirmed' => true, 'declarant_name' => 'Golden CT600A Director', 'declarant_status' => 'Director']
    );
    if (empty($ct600['ok'])) {
        throw new RuntimeException(
            'The golden CT600 XML could not be built: ' . implode(' ', (array)($ct600['errors'] ?? []))
            . ' Tax bands: ' . json_encode($firstFiling['model']['filing_decisions']['tax_calculation_bands'] ?? [])
        );
    }
    $document = new DOMDocument();
    $harness->assertTrue($document->loadXML((string)$ct600['xml'], LIBXML_NONET | LIBXML_NOBLANKS));
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('ct', \eel_accounts\Service\Ct600BuilderService::CT_NAMESPACE);
    $harness->assertSame('yes', $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/ct:ReturnInfoSummary/ct:SupplementaryPages/ct:CT600A)'));
    $harness->assertSame('1012.50', $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/ct:LoansByCloseCompanies/ct:TaxPayable)'));
    $harness->assertSame('8000.00', $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/ct:LoansByCloseCompanies/ct:TotalLoansOutstanding)'));

    $secondCt600 = goldenCt600aCt600Builder($secondFiling)->buildForIds(
        $companyId,
        $accountingPeriodId,
        $secondCtPeriodId,
        ['declaration_confirmed' => true, 'declarant_name' => 'Golden CT600A Director', 'declarant_status' => 'Director']
    );
    if (empty($secondCt600['ok'])) {
        throw new RuntimeException('The second golden CT600 XML could not be built: ' . implode(' ', (array)($secondCt600['errors'] ?? [])));
    }
    $harness->assertTrue((string)$ct600['body_sha256'] !== (string)$secondCt600['body_sha256']);
    $secondDocument = new DOMDocument();
    $harness->assertTrue($secondDocument->loadXML((string)$secondCt600['xml'], LIBXML_NONET | LIBXML_NOBLANKS));
    $secondXpath = new DOMXPath($secondDocument);
    $secondXpath->registerNamespace('ct', \eel_accounts\Service\Ct600BuilderService::CT_NAMESPACE);
    $harness->assertSame(
        '2023-09-05',
        $secondXpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/ct:CompanyInformation/ct:PeriodCovered/ct:From)')
    );

    $ixbrl = new \eel_accounts\Service\IxbrlTaxComputationService();
    $render = new ReflectionMethod($ixbrl, 'renderMappedDocument');
    $render->setAccessible(true);
    $xhtml = (array)$render->invoke(
        $ixbrl,
        new \eel_accounts\Service\IxbrlGeneratorService(),
        $firstFiling,
        goldenCt600aIxbrlMappings($firstFiling),
        'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd'
    );
    $harness->assertTrue(str_contains((string)$xhtml['xhtml'], 'CT600A loans and arrangements schedule'));
    $harness->assertTrue(str_contains((string)$xhtml['xhtml'], 'Part 1 — loans and benefits'));
    $harness->assertTrue(str_contains((string)$xhtml['xhtml'], 'A75 total outstanding'));
    $harness->assertTrue(str_contains((string)$xhtml['xhtml'], 'A80 tax payable'));
    $secondXhtml = (array)$render->invoke(
        $ixbrl,
        new \eel_accounts\Service\IxbrlGeneratorService(),
        $secondFiling,
        goldenCt600aIxbrlMappings($secondFiling),
        'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd'
    );
    $harness->assertTrue(str_contains((string)$secondXhtml['xhtml'], 'Corporation Tax computation'));
    $harness->assertTrue(hash('sha256', (string)$xhtml['xhtml']) !== hash('sha256', (string)$secondXhtml['xhtml']));

    InterfaceDB::prepareExecute(
        'UPDATE corporation_tax_periods SET status = :status WHERE id = :id',
        ['status' => 'accepted', 'id' => $firstCtPeriodId]
    );
    $harness->assertSame(true, (bool)($periodService->canSubmit($companyId, $secondCtPeriodId)['ok'] ?? false));
    $frozenHash = (string)$firstFiling['basis_hash'];
    goldenCt600aPostLateRepayment();

    $liveAccepted = (new \eel_accounts\Service\Ct600aService())->build(
        $companyId,
        $accountingPeriodId,
        $firstCtPeriodId,
        '2099-01-01'
    );
    $harness->assertSame(1012.5, (float)($liveAccepted['tax_payable'] ?? -1));
    $harness->assertSame(675.0, (float)($liveAccepted['separate_l2p_relief_due'] ?? -1));
    $frozenAfterRepayment = $filingService->build($companyId, $accountingPeriodId, $firstCtPeriodId);
    $harness->assertTrue(!empty($frozenAfterRepayment['available']));
    $harness->assertSame($frozenHash, (string)$frozenAfterRepayment['basis_hash']);
    $harness->assertSame(1012.5, (float)($frozenAfterRepayment['model']['ct600a']['tax_payable'] ?? -1));

    $l2p = (new \eel_accounts\Service\Ct600aService())->fetchL2pReliefForAccountingPeriod(
        $companyId,
        GoldenAccountsFixture::CT600A_L2P_ACCOUNTING_PERIOD_ID,
        '2099-01-01'
    );
    $harness->assertTrue(!empty($l2p['available']));
    $harness->assertSame(675.0, (float)($l2p['relief_receivable'] ?? -1));
    $harness->assertSame(1, count((array)($l2p['claims'] ?? [])));
});

function goldenCt600aCompleteFilingInputs(int $companyId, int $accountingPeriodId): void
{
    $scope = new \eel_accounts\Service\CorporationTaxFilingScopeService();
    foreach (array_keys($scope->definitions()) as $field) {
        goldenCt600aRequireSuccess($scope->saveAnswer($companyId, $accountingPeriodId, $field, 'no', 'golden_ct600a'));
    }
    $ct600a = new \eel_accounts\Service\Ct600aService();
    goldenCt600aRequireSuccess($ct600a->saveReview(
        $companyId,
        $accountingPeriodId,
        array_fill_keys(array_keys($ct600a->reviewQuestions()), 'no'),
        'director',
        'Golden CT600A Director',
        'No CT600A arrangements exist outside the posted transaction evidence.'
    ));
}

function goldenCt600aFreezeAndApprove(int $companyId, int $accountingPeriodId): void
{
    InterfaceDB::beginTransaction();
    try {
        $readiness = (new \eel_accounts\Service\YearEndTaxReadinessService())
            ->fetchAccountingPeriodCtSummary($companyId, $accountingPeriodId);
        $basis = (new \eel_accounts\Service\YearEndTaxFreezeService())->approvalBasis($readiness);
        if (!is_array($basis)) {
            throw new RuntimeException('The golden CT600A tax basis was not ready for approval.');
        }
        goldenCt600aRequireSuccess((new \eel_accounts\Service\YearEndAcknowledgementService())->save(
            $companyId,
            $accountingPeriodId,
            'tax_readiness_acknowledgement',
            $basis,
            'golden_ct600a',
            '',
            true
        ));
        goldenCt600aRequireSuccess((new \eel_accounts\Service\CorporationTaxComputationService())
            ->persistSummariesForYearEndLock($companyId, $accountingPeriodId));
        goldenCt600aRequireSuccess((new \eel_accounts\Service\YearEndLockService())
            ->lockPeriod($companyId, $accountingPeriodId, 'golden_ct600a'));
        goldenCt600aRequireSuccess((new \eel_accounts\Service\CorporationTaxComputationService())
            ->sealSummariesForYearEndLock($companyId, $accountingPeriodId));
        InterfaceDB::commit();
    } catch (Throwable $exception) {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
        throw $exception;
    }

    ixbrl_test_complete_disclosures($companyId, $accountingPeriodId, 'golden_ct600a');
    $approval = (new \eel_accounts\Service\IxbrlAccountsFilingApprovalService())
        ->approveAndBuildFacts($companyId, $accountingPeriodId, 'golden_ct600a', 'Golden CT600A filing approval.');
    if ((int)($approval['approval_id'] ?? 0) <= 0) {
        throw new RuntimeException('The golden CT600A filing basis could not be approved.');
    }
}

function goldenCt600aPostLateRepayment(): void
{
    $companyId = GoldenAccountsFixture::CT600A_COMPANY_ID;
    $periodId = GoldenAccountsFixture::CT600A_LATER_RELIEF_ACCOUNTING_PERIOD_ID;
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads
            (id, company_id, accounting_period_id, account_id, source_type, workflow_status, statement_month,
             original_filename, stored_filename, file_sha256, date_range_start, date_range_end,
             rows_parsed, rows_inserted, rows_valid, rows_committed, committed_at)
         VALUES
            (:id, :company_id, :period_id, :account_id, :source_type, :workflow_status, :statement_month,
             :original_filename, :stored_filename, :file_sha256, :date_range_start, :date_range_end,
             1, 1, 1, 1, :committed_at)',
        [
            'id' => 9833, 'company_id' => $companyId, 'period_id' => $periodId, 'account_id' => 9820,
            'source_type' => 'bank_account', 'workflow_status' => 'completed', 'statement_month' => '2024-10-01',
            'original_filename' => 'GOLDEN-CT600A-LATE-REPAYMENT.csv',
            'stored_filename' => 'golden-ct600a-late-repayment.csv',
            'file_sha256' => hash('sha256', 'GOLDEN-CT600A-LATE-REPAYMENT'),
            'date_range_start' => '2024-10-15', 'date_range_end' => '2024-10-15', 'committed_at' => '2024-10-15 12:00:00',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions
            (id, company_id, accounting_period_id, statement_upload_id, account_id, txn_date, txn_type, description,
             reference, amount, currency, source_type, source_account_label, balance, counterparty_name, dedupe_hash,
             nominal_account_id, party_id, category_status, document_download_status)
         VALUES
            (:id, :company_id, :period_id, :upload_id, :account_id, :txn_date, :txn_type, :description,
             :reference, :amount, :currency, :source_type, :source_account_label, 0, :counterparty_name, :dedupe_hash,
             :nominal_account_id, :party_id, :category_status, :document_download_status)',
        [
            'id' => 9845, 'company_id' => $companyId, 'period_id' => $periodId, 'upload_id' => 9833, 'account_id' => 9820,
            'txn_date' => '2024-10-15', 'txn_type' => 'Synthetic', 'description' => 'Synthetic participator loan repayment after nine months',
            'reference' => 'GOLDEN-CT600A-9845', 'amount' => 2000.00, 'currency' => 'GBP', 'source_type' => 'statement_csv',
            'source_account_label' => 'Golden CT600A Current Account', 'counterparty_name' => 'Golden CT600A Director',
            'dedupe_hash' => hash('sha256', 'GOLDEN-CT600A-9845'), 'nominal_account_id' => 91006,
            'party_id' => GoldenAccountsFixture::CT600A_PARTY_ID, 'category_status' => 'manual', 'document_download_status' => 'skipped',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (id, company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:id, :company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'id' => 9855, 'company_id' => $companyId, 'period_id' => $periodId, 'source_type' => 'bank_csv',
            'source_ref' => 'transaction:9845', 'journal_date' => '2024-10-15',
            'description' => 'Synthetic participator loan repayment after nine months',
        ]
    );
    foreach ([[91001, null, 2000.00, 0.00], [91006, GoldenAccountsFixture::CT600A_PARTY_ID, 0.00, 2000.00]] as [$nominalId, $partyId, $debit, $credit]) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, party_id, debit, credit, line_description)
             VALUES (:journal_id, :nominal_account_id, :party_id, :debit, :credit, :description)',
            ['journal_id' => 9855, 'nominal_account_id' => $nominalId, 'party_id' => $partyId, 'debit' => $debit, 'credit' => $credit, 'description' => 'Late relief repayment']
        );
    }
}

/**
 * The golden database deliberately has no live HMRC RIM package inventory.
 * Keep that external catalogue out of this deterministic regression, while
 * still exercising the real frozen-return adapter and CT600 XML serializer.
 */
function goldenCt600aCt600Builder(array $filing): \eel_accounts\Service\Ct600BuilderService
{
    $returnModel = new \eel_accounts\Service\Ct600ReturnModelService(
        static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId): array => $filing,
        static fn(string $startDate, string $endDate): array => [
            'ok' => true,
            'package_id' => 9800,
            'form_version' => 'V3',
            'artifact_version' => 'V1.994',
            'sha256' => hash('sha256', 'GOLDEN-CT600A-RIM-V3'),
            'warnings' => [],
        ],
        static fn(int $packageId): array => [
            'id' => 9800,
            'revision_no' => 1,
            'content_hash' => hash('sha256', 'GOLDEN-CT600A-MAPPING-PROFILE'),
        ],
        static fn(array $input, array $profile): array => [
            'success' => true,
            'errors' => [],
            'monetary_policy_version' => 'golden-ct600a-monetary-v1',
            'mappings' => goldenCt600aCt600Mappings((array)($input['facts'] ?? [])),
        ]
    );
    return new \eel_accounts\Service\Ct600BuilderService(
        static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId): array => $returnModel->build(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId
        )
    );
}

/** @return list<array<string,mixed>> */
function goldenCt600aCt600Mappings(array $facts): array
{
    $money = static fn(mixed $value): string => number_format((float)$value, 2, '.', '');
    $value = static fn(string $key, mixed $default = 0): mixed => $facts[$key] ?? $default;
    $paths = [
        'IRenvelope/CompanyTaxReturn/CompanyInformation/CompanyName' => $value('ct600.identity.company_name', ''),
        'IRenvelope/CompanyTaxReturn/CompanyInformation/RegistrationNumber' => $value('ct600.identity.company_number', ''),
        'IRenvelope/CompanyTaxReturn/CompanyInformation/Reference' => $value('ct600.identity.utr', ''),
        'IRenvelope/CompanyTaxReturn/CompanyInformation/PeriodCovered/From' => $value('ct600.period.start_date', ''),
        'IRenvelope/CompanyTaxReturn/CompanyInformation/PeriodCovered/To' => $value('ct600.period.end_date', ''),
        'IRenvelope/CompanyTaxReturn/Turnover/Total' => $money($value('ct600.amounts.turnover')),
        'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ChargeableProfits' => $money($value('ct600.amounts.taxable_profit')),
        'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/NetCorporationTaxChargeable' => $money($value('ct600.amounts.net_corporation_tax_chargeable')),
        'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/NetCorporationTaxLiability' => $money($value('ct600.amounts.net_corporation_tax_liability')),
        'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/LoansToParticipators' => $money($value('return_position.ct600a_a80')),
        'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxChargeable' => $money($value('ct600.amounts.tax_chargeable')),
        'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxPayable' => $money($value('ct600.amounts.tax_payable')),
    ];
    $mappings = [];
    foreach ($paths as $path => $mapped) {
        $mappings[] = [
            'canonical_key' => 'golden.ct600a.' . count($mappings),
            'target_xpath' => $path,
            'source_value' => $mapped,
            'serialized_value' => is_numeric($mapped) && !is_string($mapped) ? $money($mapped) : (string)$mapped,
        ];
    }
    return $mappings;
}

/** @return list<array<string,mixed>> */
function goldenCt600aIxbrlMappings(array $filing): array
{
    return [
        [
            'id' => 1, 'sort_order' => 1,
            'canonical_key' => 'identity.company_name', 'taxonomy_concept' => 'ct:CompanyName',
            'namespace_uri' => 'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01', 'local_name' => 'CompanyName',
            'value_type' => 'text', 'period_type' => 'instant',
            'context_profile' => \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY,
            'unit_ref' => null, 'decimals_value' => null, 'dimensions_json' => null, 'sign_multiplier' => 1,
            'presentation_section' => 'identity', 'presentation_label' => 'Company name',
            'source_value' => (string)($filing['facts']['identity.company_name'] ?? ''),
        ],
        [
            'id' => 2, 'sort_order' => 2,
            'canonical_key' => 'return_position.tax_payable', 'taxonomy_concept' => 'ct:NetTaxPayable',
            'namespace_uri' => 'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01', 'local_name' => 'NetTaxPayable',
            'value_type' => 'numeric', 'period_type' => 'duration',
            'context_profile' => \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY,
            'unit_ref' => 'GBP', 'decimals_value' => '2', 'dimensions_json' => null, 'sign_multiplier' => 1,
            'presentation_section' => 'tax_liability', 'presentation_label' => 'Net tax payable',
            'source_value' => (float)($filing['facts']['return_position.tax_payable'] ?? 0),
        ],
    ];
}

/** @return array<string,mixed> */
function goldenCt600aPeriodBySequence(array $periods, int $sequence): array
{
    foreach ($periods as $period) {
        if ((int)($period['ct_period_sequence_no'] ?? 0) === $sequence) {
            return $period;
        }
    }
    throw new RuntimeException('Golden CT600A CT period ' . $sequence . ' was not found.');
}

function goldenCt600aRequireSuccess(array $result): void
{
    if (empty($result['success'])) {
        throw new RuntimeException(implode(' ', array_map('strval', (array)($result['errors'] ?? ['Golden CT600A operation failed.']))));
    }
}
