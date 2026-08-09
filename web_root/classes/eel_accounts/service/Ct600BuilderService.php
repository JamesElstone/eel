<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Serializes one deterministic CT600 IRenvelope from the frozen return model. */
final class Ct600BuilderService
{
    public const SERIALIZER_VERSION = 'ct600-xml-v1';
    public const CT_NAMESPACE = 'http://www.govtalk.gov.uk/taxation/CT/5';

    private ?\Closure $returnModelBuilder;
    private ?string $artifactRoot;

    /**
     * @param null|callable(int,int,int):array $returnModelBuilder
     * @param null|string $artifactRoot Root directory containing company artifact directories.
     */
    public function __construct(?callable $returnModelBuilder = null, ?string $artifactRoot = null)
    {
        $this->returnModelBuilder = $returnModelBuilder !== null
            ? \Closure::fromCallable($returnModelBuilder)
            : null;
        $this->artifactRoot = $artifactRoot !== null && trim($artifactRoot) !== ''
            ? rtrim($artifactRoot, '\\/')
            : null;
    }

    /**
     * Compatibility entry point: returns one result per active CT period.
     * @return array<string,mixed>
     */
    public function buildCt600Xml(int $companyId, int $accountingPeriodId, array $declaration = []): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return $this->failure('Select a company and accounting period with Corporation Tax periods.');
        }
        $periods = \InterfaceDB::fetchAll(
            'SELECT id FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
               AND status <> :superseded
             ORDER BY sequence_no, id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'superseded' => 'superseded',
            ]
        );
        if ($periods === []) {
            return $this->failure('No active Corporation Tax period exists for this accounting period.');
        }
        $returns = [];
        $errors = [];
        foreach ($periods as $period) {
            $result = $this->buildCt600XmlForCtPeriod($companyId, (int)$period['id'], $declaration);
            $returns[] = $result;
            if (empty($result['ok'])) {
                $errors = array_merge($errors, (array)($result['errors'] ?? []));
            }
        }
        return [
            'ok' => $errors === [],
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'returns' => $returns,
            'warnings' => array_values(array_unique(array_merge(...array_map(
                static fn(array $item): array => (array)($item['warnings'] ?? []),
                $returns
            )))),
            'errors' => array_values(array_unique(array_map('strval', $errors))),
        ];
    }

    /** @return array<string,mixed> */
    public function buildCt600XmlForCtPeriod(int $companyId, int $ctPeriodId, array $declaration = []): array
    {
        if ($companyId <= 0 || $ctPeriodId <= 0) {
            return $this->failure('Select a company and CT period.');
        }
        $period = \InterfaceDB::fetchOne(
            'SELECT accounting_period_id FROM corporation_tax_periods
             WHERE id = :id AND company_id = :company_id AND status <> :superseded LIMIT 1',
            ['id' => $ctPeriodId, 'company_id' => $companyId, 'superseded' => 'superseded']
        );
        if (!is_array($period)) {
            return $this->failure('The selected CT period does not belong to this company or is superseded.');
        }
        return $this->buildForIds($companyId, (int)$period['accounting_period_id'], $ctPeriodId, $declaration);
    }

    /**
     * Explicit-id entry point used by package preparation and focused tests.
     * @return array<string,mixed>
     */
    public function buildForIds(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        array $declaration = [],
        ?array $returnOverride = null
    ): array {
        return $this->buildInternal(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $declaration,
            $returnOverride,
            true
        );
    }

    /**
     * Serialize the provisional CT600 body without writing an intermediate
     * artifact. The prepared-artifact pipeline owns final immutable storage.
     *
     * @return array<string,mixed>
     */
    public function buildForGeneration(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        array $declaration = [],
        ?array $returnOverride = null
    ): array {
        return $this->buildInternal(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $declaration,
            $returnOverride,
            false
        );
    }

    /** @return array<string,mixed> */
    private function buildInternal(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        array $declaration,
        ?array $returnOverride,
        bool $storeArtifact
    ): array {
        try {
            $return = $returnOverride ?? ($this->returnModelBuilder !== null
                ? (array)($this->returnModelBuilder)($companyId, $accountingPeriodId, $ctPeriodId)
                : (new Ct600ReturnModelService())->build($companyId, $accountingPeriodId, $ctPeriodId));
        } catch (\Throwable $exception) {
            return $this->failure('The CT600 return model could not be built.', [$exception->getMessage()]);
        }
        if (empty($return['ok'])) {
            return $this->failure('The CT600 return model is not ready.', (array)($return['errors'] ?? []));
        }

        $name = trim((string)($declaration['declarant_name'] ?? $declaration['declaration_name'] ?? $declaration['name'] ?? ''));
        $status = trim((string)($declaration['declarant_status'] ?? $declaration['declaration_status'] ?? $declaration['status'] ?? ''));
        if (empty($declaration['declaration_confirmed'])) {
            return $this->failure('Confirm the CT600 declaration before preparing the filing body.');
        }
        if (!$this->validDeclarationText($name) || !$this->validDeclarationText($status)) {
            return $this->failure('Declaration name and status must each contain 2 to 56 supported characters.');
        }

        try {
            $document = $this->serialize($return, $name, $status);
            $xml = $document->saveXML();
            if (!is_string($xml) || $xml === '') {
                throw new \RuntimeException('The XML serializer produced no output.');
            }
            $hash = hash('sha256', $xml);
            $path = $storeArtifact ? $this->store($companyId, $ctPeriodId, $hash, $xml) : '';
        } catch (\Throwable $exception) {
            return $this->failure('The CT600 XML could not be serialized.', [$exception->getMessage()]);
        }

        return [
            'ok' => true,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'serializer_version' => self::SERIALIZER_VERSION,
            'namespace' => self::CT_NAMESPACE,
            'xml' => $xml,
            'body' => $xml,
            'filing_body_xml' => $xml,
            'body_sha256' => $hash,
            'path' => $path,
            'filename' => $path !== '' ? basename($path) : 'ct600-provisional-' . $hash . '.xml',
            'return_model' => $return,
            'source_manifest' => (array)$return['source_manifest'],
            'source_manifest_sha256' => (string)$return['source_manifest_sha256'],
            'warnings' => (array)($return['warnings'] ?? []),
            'errors' => [],
        ];
    }

    private function serialize(array $return, string $declarationName, string $declarationStatus): \DOMDocument
    {
        $model = (array)$return['model'];
        $mapping = (array)($return['mapping']['mappings'] ?? []);
        $values = $this->mappingValues($mapping);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;
        $root = $document->createElementNS(self::CT_NAMESPACE, 'IRenvelope');
        $document->appendChild($root);

        $header = $this->element($document, $root, 'IRheader');
        $keys = $this->element($document, $header, 'Keys');
        $utr = $this->element($document, $keys, 'Key', $this->mapped(
            $values,
            'IRenvelope/CompanyTaxReturn/CompanyInformation/Reference'
        ));
        $utr->setAttribute('Type', 'UTR');
        $this->element($document, $header, 'PeriodEnd', (string)$model['period']['end_date']);
        $this->element($document, $header, 'DefaultCurrency', 'GBP');
        $manifest = $this->element($document, $header, 'Manifest');
        $contains = $this->element($document, $manifest, 'Contains');
        $reference = $this->element($document, $contains, 'Reference');
        $this->element($document, $reference, 'Namespace', self::CT_NAMESPACE);
        $this->element($document, $reference, 'SchemaVersion', $this->schemaVersion($return));
        $this->element($document, $reference, 'TopElementName', 'IRenvelope');
        $this->element($document, $header, 'Sender', 'Company');

        $companyReturn = $this->element($document, $root, 'CompanyTaxReturn');
        $companyReturn->setAttribute('ReturnType', (string)$model['return']['type']);
        $company = $this->element($document, $companyReturn, 'CompanyInformation');
        $this->element($document, $company, 'CompanyName', $this->mapped(
            $values,
            'IRenvelope/CompanyTaxReturn/CompanyInformation/CompanyName'
        ));
        $this->element($document, $company, 'RegistrationNumber', $this->mapped(
            $values,
            'IRenvelope/CompanyTaxReturn/CompanyInformation/RegistrationNumber'
        ));
        $this->element($document, $company, 'Reference', $this->mapped(
            $values,
            'IRenvelope/CompanyTaxReturn/CompanyInformation/Reference'
        ));
        $this->element($document, $company, 'CompanyType', (string)$model['identity']['company_type']);
        $covered = $this->element($document, $company, 'PeriodCovered');
        $this->element($document, $covered, 'From', $this->mapped(
            $values,
            'IRenvelope/CompanyTaxReturn/CompanyInformation/PeriodCovered/From'
        ));
        $this->element($document, $covered, 'To', $this->mapped(
            $values,
            'IRenvelope/CompanyTaxReturn/CompanyInformation/PeriodCovered/To'
        ));

        $summary = $this->element($document, $companyReturn, 'ReturnInfoSummary');
        if (!empty($model['return']['this_period'])) {
            $this->element($document, $summary, 'ThisPeriod', 'yes');
        }
        if (!empty($model['return']['multiple_returns'])) {
            $this->element($document, $summary, 'MultipleReturns', 'yes');
        }
        $accounts = $this->element($document, $summary, 'Accounts');
        $this->element(
            $document,
            $accounts,
            !empty($model['attachments']['accounts_same_period']) ? 'ThisPeriodAccounts' : 'DifferentPeriod',
            'yes'
        );
        $computations = $this->element($document, $summary, 'Computations');
        $this->element($document, $computations, 'ThisPeriodComputations', 'yes');
        if (in_array('CT600A', (array)($model['attachments']['supplementary_pages'] ?? []), true)) {
            $pages = $this->element($document, $summary, 'SupplementaryPages');
            $this->element($document, $pages, 'CT600A', 'yes');
        }

        if (isset($values['IRenvelope/CompanyTaxReturn/Turnover/Total'])) {
            $turnover = $this->element($document, $companyReturn, 'Turnover');
            $this->element($document, $turnover, 'Total', $values['IRenvelope/CompanyTaxReturn/Turnover/Total']);
        }

        $calculation = $this->element($document, $companyReturn, 'CompanyTaxCalculation');
        $income = $this->element($document, $calculation, 'Income');
        $tradingProfitPath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/Profits';
        $lossesBroughtForwardPath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/LossesBroughtForward';
        $netTradingProfitsPath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/NetProfits';
        if ($this->positive($values, $tradingProfitPath)) {
            $trading = $this->element($document, $income, 'Trading');
            $this->element($document, $trading, 'Profits', $values[$tradingProfitPath]);
            if ($this->positive($values, $lossesBroughtForwardPath)) {
                $this->element($document, $trading, 'LossesBroughtForward', $values[$lossesBroughtForwardPath]);
            }
            $this->element($document, $trading, 'NetProfits', $this->mapped($values, $netTradingProfitsPath));
        }

        $profitsBeforePath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ProfitsBeforeOtherDeductions';
        if ($this->positive($values, $profitsBeforePath)) {
            $this->element($document, $calculation, 'ProfitsBeforeOtherDeductions', $values[$profitsBeforePath]);
        }
        $tradingLossesPath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/'
            . 'DeductionsAndReliefs/TradingLosses';
        $tradingLossesCarriedForwardPath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/'
            . 'DeductionsAndReliefs/TradingLossesCarriedForward';
        $deductionsTotalPath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/'
            . 'DeductionsAndReliefs/Total';
        if ($this->anyPositive($values, [
            $tradingLossesPath, $tradingLossesCarriedForwardPath, $deductionsTotalPath,
        ])) {
            $deductions = $this->element($document, $calculation, 'DeductionsAndReliefs');
            if ($this->positive($values, $tradingLossesPath)) {
                $this->element($document, $deductions, 'TradingLosses', $values[$tradingLossesPath]);
            }
            if ($this->positive($values, $tradingLossesCarriedForwardPath)) {
                $this->element($document, $deductions, 'TradingLossesCarriedForward', $values[$tradingLossesCarriedForwardPath]);
            }
            $this->element($document, $deductions, 'Total', $this->mapped($values, $deductionsTotalPath));
        }
        $profitsBeforeDonationsPath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/'
            . 'ChargesAndReliefs/ProfitsBeforeDonationsAndGroupRelief';
        // CT600 box 300 is required when box 235 is present, including where
        // carried-forward losses reduce the derived amount to zero.
        if ($this->positive($values, $profitsBeforeDonationsPath)
            || $this->positive($values, $profitsBeforePath)) {
            $charges = $this->element($document, $calculation, 'ChargesAndReliefs');
            $profitsBeforeDonations = array_key_exists($profitsBeforeDonationsPath, $values)
                ? $values[$profitsBeforeDonationsPath]
                : number_format(
                    max(0, (float)$values[$profitsBeforePath] - (float)($values[$deductionsTotalPath] ?? 0)),
                    2,
                    '.',
                    ''
                );
            $this->element($document, $charges, 'ProfitsBeforeDonationsAndGroupRelief', $profitsBeforeDonations);
        }
        // The CT600 schema defines ChargeableProfits as a whole-pound value.
        // The frozen computation remains in pence for reconciliation elsewhere,
        // but the return must always use the schema's .00 representation.
        $serializedChargeableProfits = $this->wholePounds(
            $this->mapped($values, 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ChargeableProfits'),
            'CompanyTaxCalculation/ChargeableProfits'
        );
        $this->element($document, $calculation, 'ChargeableProfits', $serializedChargeableProfits);
        $calculationModel = (array)($model['calculation'] ?? []);
        $taxBands = $this->serializeTaxBands(
            array_values((array)($calculationModel['tax_bands'] ?? [])),
            $serializedChargeableProfits,
            (array)($model['period'] ?? [])
        );
        $serializedGrossTax = 0.0;
        if ($taxBands !== []) {
            $taxChargeable = $this->element($document, $calculation, 'CorporationTaxChargeable');
            $associated = $this->element($document, $taxChargeable, 'AssociatedCompanies');
            $this->element(
                $document,
                $associated,
                'ThisPeriod',
                (string)(int)($calculationModel['associated_company_count'] ?? 0)
            );
            if (array_filter($taxBands, static fn(array $band): bool => in_array(
                (string)($band['basis'] ?? ''),
                ['small_profits_rate', 'main_rate_less_marginal_relief'],
                true
            )) !== []) {
                $this->element($document, $associated, 'StartingOrSmallCompaniesRate', 'yes');
            }
            foreach ($taxBands as $index => $band) {
                if (!is_array($band) || $index > 1) {
                    throw new \RuntimeException('The frozen CT600 tax bands are outside the one/two financial-year MVP.');
                }
                $financialYear = $this->element(
                    $document,
                    $taxChargeable,
                    $index === 0 ? 'FinancialYearOne' : 'FinancialYearTwo'
                );
                $this->element($document, $financialYear, 'Year', (string)$band['financial_year']);
                $details = $this->element($document, $financialYear, 'Details');
                $displayProfit = (string)$band['serialized_profit'];
                $displayRate = $this->taxRate($band['tax_rate_percent'] ?? null);
                $displayTax = (float)$band['serialized_tax'];
                $serializedGrossTax += $displayTax;
                $this->element($document, $details, 'Profit', $displayProfit);
                $this->element($document, $details, 'TaxRate', $displayRate);
                $this->element($document, $details, 'Tax', $this->poundPence(
                    $displayTax,
                    'CompanyTaxCalculation/CorporationTaxChargeable/FinancialYear/Details/Tax'
                ));
            }
        }
        $serializedGrossTax = round($serializedGrossTax, 2);
        $marginalRelief = round((float)($calculationModel['marginal_relief'] ?? 0), 2);
        $netCorporationTax = $taxBands !== []
            ? round($serializedGrossTax - $marginalRelief, 2)
            : (float)$this->mapped(
                $values,
                'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/NetCorporationTaxChargeable'
            );
        if ($netCorporationTax < 0.0) {
            throw new \RuntimeException('The serialized CT600 marginal relief exceeds the gross Corporation Tax.');
        }
        if ($serializedGrossTax > 0.0) {
            $this->element($document, $calculation, 'CorporationTax', $this->poundPence(
                $serializedGrossTax,
                'CompanyTaxCalculation/CorporationTax'
            ));
        }
        if ($marginalRelief > 0.0) {
            $this->element($document, $calculation, 'MarginalReliefForRingFenceTrades', $this->poundPence(
                $marginalRelief,
                'CompanyTaxCalculation/MarginalReliefForRingFenceTrades'
            ));
        }
        $this->element($document, $calculation, 'NetCorporationTaxChargeable', $this->poundPence(
            $netCorporationTax,
            'CompanyTaxCalculation/NetCorporationTaxChargeable'
        ));

        $outstanding = $this->element($document, $companyReturn, 'CalculationOfTaxOutstandingOrOverpaid');
        $netLiabilityPath = 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/NetCorporationTaxLiability';
        if ($netCorporationTax > 0.0 || $this->positive($values, $netLiabilityPath)) {
            $this->element($document, $outstanding, 'NetCorporationTaxLiability', $taxBands !== []
                ? $this->poundPence($netCorporationTax, 'CalculationOfTaxOutstandingOrOverpaid/NetCorporationTaxLiability')
                : $values[$netLiabilityPath]);
        }
        $loansPath = 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/LoansToParticipators';
        $loansTax = $this->positive($values, $loansPath) ? (float)$values[$loansPath] : 0.0;
        if ($this->positive($values, $loansPath)) {
            $this->element($document, $outstanding, 'LoansToParticipators', $values[$loansPath]);
        }
        if (!empty($model['ct600a']['relief_due'])) {
            $this->element($document, $outstanding, 'CT600AreliefDue', 'yes');
        }
        $taxChargeablePath = 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxChargeable';
        $serializedTaxPayable = round($netCorporationTax + $loansTax, 2);
        if ($taxBands !== [] && $serializedTaxPayable > 0.0) {
            $this->element($document, $outstanding, 'TaxChargeable', $this->poundPence(
                $serializedTaxPayable,
                'CalculationOfTaxOutstandingOrOverpaid/TaxChargeable'
            ));
        } elseif ($this->positive($values, $taxChargeablePath)) {
            $this->element($document, $outstanding, 'TaxChargeable', $values[$taxChargeablePath]);
        }
        $this->element($document, $outstanding, 'TaxPayable', $taxBands !== []
            ? $this->poundPence($serializedTaxPayable, 'CalculationOfTaxOutstandingOrOverpaid/TaxPayable')
            : $this->mapped($values, 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxPayable'));

        $aiaPath = 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/AIACapitalAllowancesInc';
        $specialAllowancePath = 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/'
            . 'MachineryAndPlantSpecialRatePool/CapitalAllowances';
        $specialChargePath = 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/'
            . 'MachineryAndPlantSpecialRatePool/BalancingCharges';
        $mainAllowancePath = 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/'
            . 'MachineryAndPlantMainPool/CapitalAllowances';
        $mainChargePath = 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/'
            . 'MachineryAndPlantMainPool/BalancingCharges';
        if ($this->anyPositive($values, [
            $aiaPath, $specialAllowancePath, $specialChargePath, $mainAllowancePath, $mainChargePath,
        ])) {
            $allowances = $this->element($document, $companyReturn, 'AllowancesAndCharges');
            if ($this->positive($values, $aiaPath)) {
                $this->element($document, $allowances, 'AIACapitalAllowancesInc', $values[$aiaPath]);
            }
            if ($this->positive($values, $specialAllowancePath) || $this->positive($values, $specialChargePath)) {
                $specialPool = $this->element($document, $allowances, 'MachineryAndPlantSpecialRatePool');
                if ($this->positive($values, $specialChargePath)) {
                    $this->element($document, $specialPool, 'BalancingCharges', $values[$specialChargePath]);
                }
                if ($this->positive($values, $specialAllowancePath)) {
                    $this->element($document, $specialPool, 'CapitalAllowances', $values[$specialAllowancePath]);
                }
            }
            if ($this->positive($values, $mainAllowancePath) || $this->positive($values, $mainChargePath)) {
                $mainPool = $this->element($document, $allowances, 'MachineryAndPlantMainPool');
                if ($this->positive($values, $mainChargePath)) {
                    $this->element($document, $mainPool, 'BalancingCharges', $values[$mainChargePath]);
                }
                if ($this->positive($values, $mainAllowancePath)) {
                    $this->element($document, $mainPool, 'CapitalAllowances', $values[$mainAllowancePath]);
                }
            }
        }

        $qualifyingPath = 'IRenvelope/CompanyTaxReturn/QualifyingExpenditure/OtherMachineryAndPlant';
        if ($this->positive($values, $qualifyingPath)) {
            $qualifying = $this->element($document, $companyReturn, 'QualifyingExpenditure');
            $this->element($document, $qualifying, 'OtherMachineryAndPlant', $values[$qualifyingPath]);
        }

        $lossArisingPath = 'IRenvelope/CompanyTaxReturn/LossesDeficitsAndExcess/'
            . 'AmountArising/LossesOfTradesUK/Arising';
        if (isset($values[$lossArisingPath]) && (float)$values[$lossArisingPath] > 0.0) {
            $losses = $this->element($document, $companyReturn, 'LossesDeficitsAndExcess');
            $arising = $this->element($document, $losses, 'AmountArising');
            $tradingLosses = $this->element($document, $arising, 'LossesOfTradesUK');
            $this->element($document, $tradingLosses, 'Arising', $values[$lossArisingPath]);
        }

        $declaration = $this->element($document, $companyReturn, 'Declaration');
        $this->element($document, $declaration, 'AcceptDeclaration', 'yes');
        $this->element($document, $declaration, 'Name', $declarationName);
        $this->element($document, $declaration, 'Status', $declarationStatus);
        if (in_array('CT600A', (array)($model['attachments']['supplementary_pages'] ?? []), true)) {
            $this->serializeCt600a($document, $companyReturn, (array)$model['ct600a']);
        }
        return $document;
    }

    private function serializeCt600a(\DOMDocument $document, \DOMElement $companyReturn, array $model): void
    {
        $page = $this->element($document, $companyReturn, 'LoansByCloseCompanies');
        $this->element($document, $page, 'BeforeEndPeriod', !empty($model['before_end_period']) ? 'yes' : 'no');
        $part1 = (array)($model['part1'] ?? []);
        $part1Rows = array_values((array)($part1['rows'] ?? []));
        if ($part1Rows !== []) {
            $section = $this->element($document, $page, 'LoansInformation');
            foreach ($part1Rows as $row) {
                if ((float)($row['amount'] ?? 0) < 0.005) { continue; }
                $loan = $this->element($document, $section, 'Loan');
                $this->element($document, $loan, 'Name', $this->ct600aName((string)($row['name'] ?? 'Participator')));
                $this->element($document, $loan, 'AmountOfLoan', $this->wholePounds($row['amount'], 'LoansByCloseCompanies/LoansInformation/Loan/AmountOfLoan'));
            }
            $this->element($document, $section, 'TotalLoans', $this->wholePounds($part1['total_loans'] ?? 0, 'LoansByCloseCompanies/LoansInformation/TotalLoans'));
            $this->element($document, $section, 'TaxChargeable', $this->poundPence($part1['tax_chargeable'] ?? 0, 'LoansByCloseCompanies/LoansInformation/TaxChargeable'));
        }
        $this->serializeCt600aRelief($document, $page, 'ReliefEarlierThan', (array)($model['part2'] ?? []));
        $this->serializeCt600aRelief($document, $page, 'LoanLaterReliefNow', (array)($model['part3'] ?? []));
        if ((float)($model['total_loans_outstanding'] ?? 0) >= 0.005) {
            $this->element($document, $page, 'TotalLoansOutstanding', $this->wholePounds($model['total_loans_outstanding'], 'LoansByCloseCompanies/TotalLoansOutstanding'));
        }
        $this->element($document, $page, 'TaxPayable', $this->poundPence($model['tax_payable'] ?? 0, 'LoansByCloseCompanies/TaxPayable'));
    }

    private function serializeCt600aRelief(\DOMDocument $document, \DOMElement $page, string $elementName, array $sectionModel): void
    {
        $rows = array_values((array)($sectionModel['rows'] ?? []));
        if ($rows === []) { return; }
        $section = $this->element($document, $page, $elementName);
        foreach ($rows as $row) {
            $loan = $this->element($document, $section, 'Loan');
            $this->element($document, $loan, 'Name', $this->ct600aName((string)($row['name'] ?? 'Participator')));
            if ((float)($row['amount_repaid'] ?? 0) >= 0.005) {
                $this->element($document, $loan, 'AmountRepaid', $this->wholePounds($row['amount_repaid'], 'LoansByCloseCompanies/' . $elementName . '/Loan/AmountRepaid'));
            }
            if ((float)($row['amount_released_or_written_off'] ?? 0) >= 0.005) {
                $this->element($document, $loan, 'AmountReleasedOrWrittenOff', $this->wholePounds($row['amount_released_or_written_off'], 'LoansByCloseCompanies/' . $elementName . '/Loan/AmountReleasedOrWrittenOff'));
            }
            $this->element($document, $loan, 'Date', (string)$row['date']);
        }
        if ((float)($sectionModel['total_repaid'] ?? 0) >= 0.005) {
            $this->element($document, $section, 'TotalAmountRepaid', $this->wholePounds($sectionModel['total_repaid'], 'LoansByCloseCompanies/' . $elementName . '/TotalAmountRepaid'));
        }
        if ((float)($sectionModel['total_released_or_written_off'] ?? 0) >= 0.005) {
            $this->element($document, $section, 'TotalAmountReleasedOrWritten', $this->wholePounds($sectionModel['total_released_or_written_off'], 'LoansByCloseCompanies/' . $elementName . '/TotalAmountReleasedOrWritten'));
        }
        $this->element($document, $section, 'TotalLoans', $this->wholePounds($sectionModel['total'] ?? 0, 'LoansByCloseCompanies/' . $elementName . '/TotalLoans'));
        $this->element($document, $section, 'ReliefDue', $this->poundPence($sectionModel['relief_due'] ?? 0, 'LoansByCloseCompanies/' . $elementName . '/ReliefDue'));
    }

    private function ct600aName(string $name): string
    {
        $name = trim($name);
        if (mb_strlen($name) < 2) { throw new \RuntimeException('A CT600A participator name must contain at least two characters.'); }
        return mb_substr($name, 0, 56);
    }

    /** @return array<string,string> */
    private function mappingValues(array $mappings): array
    {
        $values = [];
        foreach ($mappings as $mapping) {
            $path = trim(str_replace('\\', '/', (string)($mapping['target_xpath'] ?? '')));
            if ($path === '' || isset($values[$path])) {
                throw new \RuntimeException('The resolved CT600 mappings contain a blank or duplicate target.');
            }
            $value = $mapping['serialized_value'] ?? $mapping['source_value'] ?? null;
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            } elseif (is_float($value)) {
                $value = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
            }
            if ($value === null || $value === '') {
                throw new \RuntimeException('A resolved CT600 mapping has no serializable value: ' . $path . '.');
            }
            $values[$path] = (string)$value;
        }
        return $values;
    }

    private function mapped(array $values, string $path): string
    {
        if (!array_key_exists($path, $values)) {
            throw new \RuntimeException('The active CT600 profile did not resolve required target: ' . $path . '.');
        }
        return $values[$path];
    }

    private function positive(array $values, string $path): bool
    {
        return isset($values[$path]) && is_numeric($values[$path]) && (float)$values[$path] > 0.0;
    }

    /** @param list<string> $paths */
    private function anyPositive(array $values, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($this->positive($values, $path)) {
                return true;
            }
        }
        return false;
    }

    private function wholePounds(mixed $value, string $path): string
    {
        return (new Ct600MonetaryValuePolicyService())->serialize($value, 'ct:CTwholePoundStructure', $path);
    }

    private function poundPence(mixed $value, string $path): string
    {
        return (new Ct600MonetaryValuePolicyService())->serialize($value, 'ct:CTpoundPenceStructure', $path);
    }

    private function taxRate(mixed $value): string
    {
        if (!is_int($value) && !is_float($value) || !is_finite((float)$value)
            || (float)$value < 0.0 || (float)$value > 100.0) {
            throw new \RuntimeException('A frozen CT600 tax rate is invalid.');
        }
        return number_format((float)$value, 2, '.', '');
    }

    /**
     * HMRC boxes 315, 335 and 385 are whole-pound values. The official
     * Schematron apportions Box 315 by inclusive days at 31 March and derives
     * each tax line from that displayed profit, so frozen penny allocations
     * cannot be copied into those display fields directly.
     *
     * @param list<array<string,mixed>> $bands
     * @param array<string,mixed> $period
     * @return list<array<string,mixed>>
     */
    private function serializeTaxBands(array $bands, string $chargeableProfits, array $period): array
    {
        if ($bands === []) {
            return [];
        }
        if (count($bands) > 2) {
            throw new \RuntimeException('The frozen CT600 tax bands are outside the one/two financial-year MVP.');
        }
        $totalProfit = (int)round((float)$chargeableProfits, 0, PHP_ROUND_HALF_UP);
        $profits = [$totalProfit];
        if (count($bands) === 2) {
            $start = new \DateTimeImmutable((string)($period['start_date'] ?? ''));
            $end = new \DateTimeImmutable((string)($period['end_date'] ?? ''));
            $firstYear = (string)($bands[0]['financial_year'] ?? '');
            if (preg_match('/^[0-9]{4}$/D', $firstYear) !== 1 || $end < $start) {
                throw new \RuntimeException('The frozen CT600 financial-year apportionment is invalid.');
            }
            $firstYearEnd = new \DateTimeImmutable(((int)$firstYear + 1) . '-03-31');
            if ($firstYearEnd < $start || $firstYearEnd >= $end) {
                throw new \RuntimeException('The frozen CT600 bands do not match the return period financial years.');
            }
            $totalDays = (int)$start->diff($end)->days + 1;
            $firstDays = (int)$start->diff($firstYearEnd)->days + 1;
            $firstProfit = (int)round(
                $totalProfit * $firstDays / $totalDays,
                0,
                PHP_ROUND_HALF_UP
            );
            $firstProfit = min($totalProfit, max(0, $firstProfit));
            $profits = [$firstProfit, $totalProfit - $firstProfit];
        }
        foreach ($bands as $index => &$band) {
            $rate = (float)($band['tax_rate_percent'] ?? -1);
            if ($rate < 0.0 || $rate > 100.0) {
                throw new \RuntimeException('A frozen CT600 tax rate is invalid.');
            }
            $band['serialized_profit'] = number_format($profits[$index], 2, '.', '');
            $band['serialized_tax'] = round($profits[$index] * $rate / 100, 2, PHP_ROUND_HALF_UP);
        }
        unset($band);
        return $bands;
    }

    private function element(
        \DOMDocument $document,
        \DOMElement $parent,
        string $name,
        ?string $value = null
    ): \DOMElement {
        $element = $document->createElementNS(self::CT_NAMESPACE, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode(\eel_accounts\Support\Utf8::normalize($value)));
        }
        $parent->appendChild($element);
        return $element;
    }

    private function schemaVersion(array $return): string
    {
        $artifact = ltrim(strtolower((string)($return['rim']['artifact_version'] ?? 'v1.0')), 'v');
        if (preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,3}){1,2}$/', $artifact) !== 1) {
            throw new \RuntimeException('The selected RIM artifact version is not valid for the IRheader manifest.');
        }
        return '2014-v' . $artifact;
    }

    private function validDeclarationText(string $value): bool
    {
        return strlen($value) >= 2 && strlen($value) <= 56 && preg_match('/[£$#~€]/u', $value) !== 1;
    }

    private function store(int $companyId, int $ctPeriodId, string $hash, string $xml): string
    {
        $root = defined('PROJECT_ROOT') ? (string)PROJECT_ROOT : dirname(__DIR__, 4);
        $artifactRoot = $this->artifactRoot
            ?? rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'files';
        $directory = $artifactRoot . DIRECTORY_SEPARATOR . $companyId . DIRECTORY_SEPARATOR . 'hmrc';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('The immutable CT600 artifact directory could not be created.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'ct600-' . $ctPeriodId . '-' . $hash . '.xml';
        if (is_file($path)) {
            $existing = hash_file('sha256', $path);
            if (!is_string($existing) || !hash_equals($hash, $existing)) {
                throw new \RuntimeException('An existing CT600 artifact failed its content hash check.');
            }
            return $path;
        }
        if (file_put_contents($path, $xml, LOCK_EX) !== strlen($xml)) {
            @unlink($path);
            throw new \RuntimeException('The immutable CT600 artifact could not be stored completely.');
        }
        @chmod($path, 0660);
        return $path;
    }

    /** @return array<string,mixed> */
    private function failure(string $message, array $details = []): array
    {
        return [
            'ok' => false,
            'path' => null,
            'body' => null,
            'xml' => null,
            'filing_body_xml' => null,
            'warnings' => [],
            'errors' => array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge([$message], $details)
            ), static fn(string $item): bool => trim($item) !== ''))),
        ];
    }
}
