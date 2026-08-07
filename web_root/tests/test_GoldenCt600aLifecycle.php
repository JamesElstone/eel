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

    goldenCt600aFreezeAndApprove($harness, $companyId, $accountingPeriodId);
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

    // Regression: a genuine £253 advance-and-repayment sequence belongs to
    // the first CT period only. The later period contains only funds
    // introduced by the participator and must not inherit the explanation.
    $narrativeReview = ['current' => true, 'complete' => true, 'errors' => []];
    $narrativeS455 = [
        'errors' => [],
        'window_status' => 'window_complete',
        'lots' => [
            ['transaction_id' => 129, 'party_id' => GoldenAccountsFixture::CT600A_PARTY_ID,
                'party_name' => 'Golden CT600A Director', 'origin_date' => '2022-11-02',
                'original_amount' => 129.0, 'remaining_at_period_end' => 0.0, 'rate' => 0.0],
            ['transaction_id' => 40, 'party_id' => GoldenAccountsFixture::CT600A_PARTY_ID,
                'party_name' => 'Golden CT600A Director', 'origin_date' => '2023-06-01',
                'original_amount' => 40.0, 'remaining_at_period_end' => 0.0, 'rate' => 0.0],
            ['transaction_id' => 84, 'party_id' => GoldenAccountsFixture::CT600A_PARTY_ID,
                'party_name' => 'Golden CT600A Director', 'origin_date' => '2023-06-05',
                'original_amount' => 84.0, 'remaining_at_period_end' => 0.0, 'rate' => 0.0],
        ],
        'repayment_allocations' => [
            ['loan_transaction_id' => 129, 'repayment_date' => '2023-06-08', 'amount' => 129.0],
            ['loan_transaction_id' => 40, 'repayment_date' => '2023-06-08', 'amount' => 40.0],
            ['loan_transaction_id' => 84, 'repayment_date' => '2023-06-08', 'amount' => 81.0],
            ['loan_transaction_id' => 84, 'repayment_date' => '2023-08-15', 'amount' => 3.0],
        ],
    ];
    $narrativeService = new \eel_accounts\Service\Ct600aService();
    $firstNarrative = $narrativeService->buildFromEvidence(
        ['period_start' => '2022-09-05', 'period_end' => '2023-09-04'],
        $narrativeS455,
        [],
        $narrativeReview,
        '2024-10-30'
    );
    $secondNarrative = $narrativeService->buildFromEvidence(
        ['period_start' => '2023-09-05', 'period_end' => '2023-09-30'],
        $narrativeS455,
        [],
        $narrativeReview,
        '2024-10-30'
    );
    foreach ([$firstNarrative, $secondNarrative] as $narrative) {
        $harness->assertSame(false, (bool)$narrative['required']);
        $harness->assertSame(0.0, (float)$narrative['total_loans_outstanding']);
        $harness->assertSame(0.0, (float)$narrative['tax_payable']);
    }
    $harness->assertSame('repaid_within_period', (string)$firstNarrative['section_455_narrative']);
    $harness->assertSame(null, $secondNarrative['section_455_narrative']);

    $firstNarrativeFiling = $firstFiling;
    $firstNarrativeFiling['model']['ct600a'] = $firstNarrative;
    $secondNarrativeFiling = $secondFiling;
    $secondNarrativeFiling['model']['ct600a'] = $secondNarrative;
    $firstNarrativeXhtml = (string)($render->invoke(
        $ixbrl,
        new \eel_accounts\Service\IxbrlGeneratorService(),
        $firstNarrativeFiling,
        goldenCt600aIxbrlMappings($firstNarrativeFiling),
        'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd'
    ))['xhtml'];
    $secondNarrativeXhtml = (string)($render->invoke(
        $ixbrl,
        new \eel_accounts\Service\IxbrlGeneratorService(),
        $secondNarrativeFiling,
        goldenCt600aIxbrlMappings($secondNarrativeFiling),
        'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd'
    ))['xhtml'];
    $visibleText = static function (string $xhtml): string {
        $document = new DOMDocument();
        if (!$document->loadXML($xhtml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('Golden CT600 iXBRL XHTML could not be parsed.');
        }
        return trim((string)preg_replace('/\s+/', ' ', (string)$document->textContent));
    };
    $statement = 'Repaid within the accounting period; no amount reportable and no Section 455 tax payable.';
    $firstNarrativeText = $visibleText($firstNarrativeXhtml);
    $secondNarrativeText = $visibleText($secondNarrativeXhtml);
    $harness->assertSame(1, substr_count($firstNarrativeText, $statement));
    $harness->assertTrue(str_contains($firstNarrativeText, '5 September 2022 to 4 September 2023'));
    $harness->assertFalse(str_contains($firstNarrativeText, 'No exposure'));
    $harness->assertFalse(str_contains($firstNarrativeText, '253.00'));
    $harness->assertSame(0, substr_count($secondNarrativeText, $statement));
    $harness->assertTrue(str_contains($secondNarrativeText, '5 September 2023 to 30 September 2023'));
    $harness->assertFalse(str_contains($secondNarrativeText, 'Section 455'));
    $harness->assertFalse(str_contains($secondNarrativeText, 'No exposure'));
    $harness->assertFalse(str_contains($secondNarrativeText, 'No reportable participator loan'));

    InterfaceDB::prepareExecute(
        'UPDATE corporation_tax_periods SET status = :status WHERE id = :id',
        ['status' => 'accepted', 'id' => $firstCtPeriodId]
    );
    // The fixture changes protocol state directly; invalidate request-scoped
    // filing read models just as the real submission service does.
    \eel_accounts\Support\RequestCache::clear();
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
    // The later evidence makes the combined approval stale for the unfiled
    // second CT period, so the current-output model fails closed. The accepted
    // first-period evidence itself remains immutable and independently
    // verifiable in its bound filing-basis row.
    $harness->assertFalse(!empty($frozenAfterRepayment['available']));
    $harness->assertTrue(str_contains(
        implode(' ', array_map('strval', (array)($frozenAfterRepayment['errors'] ?? []))),
        'CT600A evidence changed'
    ));
    $storedFrozen = (array)(InterfaceDB::fetchOne(
        'SELECT basis_hash, basis_json FROM ct_period_filing_bases
         WHERE company_id = :company_id AND accounting_period_id = :period_id
           AND ct_period_id = :ct_period_id AND basis_hash = :basis_hash
         ORDER BY id DESC LIMIT 1',
        [
            'company_id' => $companyId,
            'period_id' => $accountingPeriodId,
            'ct_period_id' => $firstCtPeriodId,
            'basis_hash' => $frozenHash,
        ]
    ) ?: []);
    $storedModel = json_decode((string)($storedFrozen['basis_json'] ?? ''), true);
    $harness->assertSame($frozenHash, (string)($storedFrozen['basis_hash'] ?? ''));
    $harness->assertTrue(is_array($storedModel));
    $harness->assertSame(1012.5, (float)($storedModel['ct600a']['tax_payable'] ?? -1));

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

function goldenCt600aFreezeAndApprove(GeneratedServiceClassTestHarness $harness, int $companyId, int $accountingPeriodId): void
{
    goldenCt600aSavePartyLoanTerms($companyId);
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
        $taxPersistence = (new \eel_accounts\Service\CorporationTaxComputationService())
            ->persistSummariesForYearEndLock($companyId, $accountingPeriodId);
        goldenCt600aRequireSuccess($taxPersistence);
        goldenCt600aRequireSuccess((new \eel_accounts\Service\DirectorLoanReconciliationService())
            ->saveYearEndReview($companyId, $accountingPeriodId, true, 'golden_ct600a'));
        $approvedFreezeManifestHash = (new \eel_accounts\Service\YearEndAcknowledgementService())
            ->hashBasis((array)($basis['freeze_manifest'] ?? []));
        foreach ((array)($taxPersistence['summaries'] ?? []) as $persistedSummary) {
            $harness->assertSame(
                $approvedFreezeManifestHash,
                (string)($persistedSummary['year_end_freeze_manifest_hash'] ?? '')
            );
        }
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
    goldenCt600aSaveReturnAuthorisation($companyId, $accountingPeriodId);
    \eel_accounts\Support\RequestCache::clear();
    $workflow = new \eel_accounts\Service\IxbrlFilingApprovalWorkflowService();
    $status = $workflow->status($companyId, $accountingPeriodId);
    $stateToken = (string)($status['state_token'] ?? '');
    if (strlen($stateToken) !== 64) {
        throw new RuntimeException(
            'The golden CT600A combined filing approval is unavailable: '
            . implode(' ', array_map('strval', (array)($status['blockers'] ?? [])))
        );
    }
    $approval = $workflow->approveAll(
        $companyId,
        $accountingPeriodId,
        [],
        'golden_ct600a',
        'Golden CT600A filing approval.',
        $stateToken
    );
    if (empty($approval['success'])
        || (int)($approval['accounts_approval_id'] ?? 0) <= 0
        || (int)($approval['fact_run_id'] ?? 0) <= 0
        || (int)($approval['hmrc_approval_id'] ?? 0) <= 0) {
        throw new RuntimeException(
            'The golden CT600A accounts and HMRC filing bases could not be approved: '
            . implode(' ', array_map('strval', (array)($approval['errors'] ?? [])))
        );
    }
    $ctPeriods = (new \eel_accounts\Service\CorporationTaxPeriodService())
        ->fetchForAccountingPeriod($companyId, $accountingPeriodId);
    $harness->assertSame(count($ctPeriods), count((array)($approval['ct_basis_ids'] ?? [])));
    $approvedStatus = $workflow->status($companyId, $accountingPeriodId);
    $harness->assertTrue(!empty($approvedStatus['both_current']));
    $harness->assertSame(
        (int)$approval['accounts_approval_id'],
        (int)(($approvedStatus['accounts'] ?? [])['approval_id'] ?? 0)
    );
    $harness->assertSame(
        (int)$approval['hmrc_approval_id'],
        (int)(($approvedStatus['hmrc'] ?? [])['approval_id'] ?? 0)
    );
}

function goldenCt600aSaveReturnAuthorisation(int $companyId, int $accountingPeriodId): void
{
    $service = new \eel_accounts\Service\Ct600ReturnAuthorisationService();
    $authorisers = $service->eligibleAuthorisers($companyId, (new \DateTimeImmutable('now'))->format('Y-m-d'));
    $reference = (string)($authorisers[0]['reference'] ?? '');
    if ($reference === '') {
        throw new RuntimeException('The golden CT600A fixture has no eligible Corporation Tax return authoriser.');
    }
    goldenCt600aRequireSuccess($service->save(
        $companyId,
        $accountingPeriodId,
        [
            'declarant_authority' => $reference,
            'original_unfiled_confirmed' => '1',
            'authority_confirmed' => '1',
            'declaration_confirmed' => '1',
        ],
        'golden_ct600a'
    ));
}

function goldenCt600aSavePartyLoanTerms(int $companyId): void
{
    goldenCt600aRequireSuccess((new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->save(
        $companyId,
        GoldenAccountsFixture::CT600A_PARTY_ID,
        [
            'interest_rate_percent' => 0,
            'security_type' => 'unsecured',
            'repayable_on_demand' => 1,
            'repayment_timing' => 'within_12_months',
            'deferment_right_confirmed' => 0,
            'set_off_right_confirmed' => 0,
            'settlement_intention' => 'independently',
            'advance_interest_rate_percent' => 0,
            'advance_security_type' => 'unsecured',
            'advance_repayment_basis' => 'on_demand',
        ],
        'golden_ct600a'
    ));
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
        ),
        test_register_cleanup_path(
            test_tmp_directory() . DIRECTORY_SEPARATOR . 'golden-ct600a-' . bin2hex(random_bytes(4))
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
    $specs = [
        ['identity.company_name', 'CompanyName', 'text', 'instant', 'identity'],
        ['filing_identity.utr', 'TaxReference', 'text', 'instant', 'identity'],
        ['accounting_period.start_date', 'PeriodOfAccountStartDate', 'date', 'instant', 'identity'],
        ['accounting_period.end_date', 'PeriodOfAccountEndDate', 'date', 'instant', 'identity'],
        ['ct_period.start_date', 'StartOfPeriodCoveredByReturn', 'date', 'instant', 'identity'],
        ['ct_period.end_date', 'EndOfPeriodCoveredByReturn', 'date', 'instant', 'identity'],
        ['supported_return_profile.company_is_partner_in_firm', 'CompanyIsMemberOfPartnership', 'boolean', 'instant', 'identity'],
        ['computation.summary.accounting_profit', 'ProfitLossPerAccounts', 'numeric', 'duration', 'accounts_adjustments'],
        ['computation.summary.disallowable_add_backs', 'AdjustmentsMiscellaneousExpensesPerAccounts', 'numeric', 'duration', 'accounts_adjustments'],
        ['computation.summary.capital_expenditure_add_backs', 'AdjustmentsCapitalExpenditure', 'numeric', 'duration', 'accounts_adjustments'],
        ['computation.summary.disposal_profit_or_loss_adjustment', 'AdjustmentsLossOrProfitOnSale', 'numeric', 'duration', 'accounts_adjustments'],
        ['computation.summary.depreciation_add_back', 'AdjustmentsDepreciation', 'numeric', 'duration', 'accounts_adjustments'],
        ['computation.summary.capital_allowances', 'TotalCapitalAllowances', 'numeric', 'duration', 'capital_allowances'],
        ['computation.summary.taxable_before_losses', 'ProfitsBeforeOtherDeductionsAndReliefs', 'numeric', 'duration', 'losses'],
        ['computation.summary.loss_restriction.post_2017_trading_losses.brought_forward', 'TradingLossesBroughtForward', 'numeric', 'duration', 'losses'],
        ['computation.summary.loss_restriction.post_2017_trading_losses.used', 'TradingLossesBroughtForwardAmountUsedAgainstTotalProfits', 'numeric', 'duration', 'losses'],
        ['computation.summary.loss_restriction.post_2017_trading_losses.carried_forward', 'BalanceOfLossesBroughtForwardCarriedForward', 'numeric', 'instant', 'losses'],
        ['computation.summary.loss_restriction.deduction_allowance.amount', 'DeductionAllowance', 'numeric', 'duration', 'losses'],
        ['computation.summary.loss_restriction.calculated_loss_restriction', 'CalculatedLossRestriction', 'numeric', 'duration', 'losses'],
        ['computation.summary.taxable_profit', 'TotalProfitsChargeableToCorporationTax', 'numeric', 'duration', 'tax_liability'],
        ['computation.summary.ordinary_corporation_tax', 'CorporationTaxChargeable', 'numeric', 'duration', 'tax_liability'],
        ['return_position.ct600a_a80', 'TaxPayableOnLoansToParticipators', 'numeric', 'duration', 'tax_liability'],
        ['return_position.tax_payable', 'NetTaxPayable', 'numeric', 'duration', 'tax_liability'],
    ];
    $facts = (array)($filing['facts'] ?? []);
    $mappings = [];
    foreach ($specs as $index => [$key, $localName, $type, $periodType, $section]) {
        $mappings[] = [
            'id' => $index + 1,
            'sort_order' => ($index + 1) * 10,
            'canonical_key' => $key,
            'taxonomy_concept' => 'ct:' . $localName,
            'namespace_uri' => 'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01',
            'local_name' => $localName,
            'value_type' => $type,
            'period_type' => $periodType,
            'context_profile' => in_array($localName, ['DeductionAllowance', 'CalculatedLossRestriction'], true)
                ? \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_LOSS_RESTRICTION
                : (in_array($localName, [
                    'ProfitLossPerAccounts',
                    'AdjustmentsMiscellaneousExpensesPerAccounts',
                    'AdjustmentsCapitalExpenditure',
                    'AdjustmentsLossOrProfitOnSale',
                    'AdjustmentsDepreciation',
                    'TotalCapitalAllowances',
                    'TradingLossesBroughtForward',
                    'TradingLossesBroughtForwardAmountUsedAgainstTotalProfits',
                    'BalanceOfLossesBroughtForwardCarriedForward',
                ], true)
                    ? \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE
                    : \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY),
            'unit_ref' => $type === 'numeric' ? 'GBP' : null,
            'decimals_value' => $type === 'numeric' ? '2' : null,
            'dimensions_json' => null,
            'sign_multiplier' => 1,
            'presentation_section' => $section,
            'presentation_label' => $key,
            'source_value' => $facts[$key] ?? match ($type) {
                'numeric' => 0.0,
                'boolean' => false,
                default => '',
            },
        ];
    }
    return $mappings;
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
