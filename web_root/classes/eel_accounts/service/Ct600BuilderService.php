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

    /** @param null|callable(int,int,int):array $returnModelBuilder */
    public function __construct(?callable $returnModelBuilder = null)
    {
        $this->returnModelBuilder = $returnModelBuilder !== null
            ? \Closure::fromCallable($returnModelBuilder)
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
        array $declaration = []
    ): array {
        try {
            $return = $this->returnModelBuilder !== null
                ? (array)($this->returnModelBuilder)($companyId, $accountingPeriodId, $ctPeriodId)
                : (new Ct600ReturnModelService())->build($companyId, $accountingPeriodId, $ctPeriodId);
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
            $path = $this->store($companyId, $ctPeriodId, $hash, $xml);
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
            'filename' => basename($path),
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
        $profitsBeforeDonationsPath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/'
            . 'ChargesAndReliefs/ProfitsBeforeDonationsAndGroupRelief';
        if ($this->positive($values, $profitsBeforeDonationsPath)) {
            $charges = $this->element($document, $calculation, 'ChargesAndReliefs');
            $this->element($document, $charges, 'ProfitsBeforeDonationsAndGroupRelief', $values[$profitsBeforeDonationsPath]);
        }
        $this->element($document, $calculation, 'ChargeableProfits', $this->mapped(
            $values,
            'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ChargeableProfits'
        ));
        $calculationModel = (array)($model['calculation'] ?? []);
        $taxBands = array_values((array)($calculationModel['tax_bands'] ?? []));
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
                $displayProfit = $this->wholePounds(
                    $band['profit'] ?? null,
                    'CompanyTaxCalculation/CorporationTaxChargeable/FinancialYear/Details/Profit'
                );
                $displayRate = $this->taxRate($band['tax_rate_percent'] ?? null);
                $displayTax = round((float)$displayProfit * ((float)$displayRate / 100), 2);
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
        if (abs($serializedGrossTax - (float)($calculationModel['gross_corporation_tax'] ?? 0)) > 0.009) {
            throw new \RuntimeException(
                'The whole-pound CT600 financial-year profits do not reconcile to the frozen gross Corporation Tax.'
            );
        }
        $netCorporationTax = $this->mapped(
            $values,
            'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/NetCorporationTaxChargeable'
        );
        if ($serializedGrossTax > 0.0) {
            $this->element($document, $calculation, 'CorporationTax', $this->poundPence(
                $serializedGrossTax,
                'CompanyTaxCalculation/CorporationTax'
            ));
        }
        if ((float)($calculationModel['marginal_relief'] ?? 0) > 0.0) {
            $this->element($document, $calculation, 'MarginalReliefForRingFenceTrades', $this->poundPence(
                $calculationModel['marginal_relief'],
                'CompanyTaxCalculation/MarginalReliefForRingFenceTrades'
            ));
        }
        $this->element($document, $calculation, 'NetCorporationTaxChargeable', $netCorporationTax);

        $outstanding = $this->element($document, $companyReturn, 'CalculationOfTaxOutstandingOrOverpaid');
        $netLiabilityPath = 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/NetCorporationTaxLiability';
        if ($this->positive($values, $netLiabilityPath)) {
            $this->element($document, $outstanding, 'NetCorporationTaxLiability', $values[$netLiabilityPath]);
        }
        $taxChargeablePath = 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxChargeable';
        if ($this->positive($values, $taxChargeablePath)) {
            $this->element($document, $outstanding, 'TaxChargeable', $values[$taxChargeablePath]);
        }
        $this->element($document, $outstanding, 'TaxPayable', $this->mapped(
            $values,
            'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxPayable'
        ));

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
        return $document;
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

    private function element(
        \DOMDocument $document,
        \DOMElement $parent,
        string $name,
        ?string $value = null
    ): \DOMElement {
        $element = $document->createElementNS(self::CT_NAMESPACE, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
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
        $directory = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR
            . 'ct600' . DIRECTORY_SEPARATOR . $companyId . DIRECTORY_SEPARATOR . $ctPeriodId;
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('The immutable CT600 artifact directory could not be created.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'ct600-' . $hash . '.xml';
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
