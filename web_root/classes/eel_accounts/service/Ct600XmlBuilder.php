<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds the CT/5 IRenvelope body for phase-one original nil/loss returns. */
final class Ct600XmlBuilder
{
    public const CT_NAMESPACE = 'http://www.govtalk.gov.uk/taxation/CT/5';
    public const RIM_VERSION = '1.994';
    public const RIM_RELEASE = 'V1.994';
    public const DEFAULT_MAX_BODY_BYTES = 25_000_000;

    public function __construct(private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES)
    {
        if ($this->maxBodyBytes <= 0) {
            throw new \InvalidArgumentException('CT600 maximum body size must be positive.');
        }
    }

    /**
     * @param null|callable(Ct600ReturnData, Ct600IxbrlArtifact, Ct600IxbrlArtifact): array $crossDocumentValidator
     * @return array{
     *   xml: string,
     *   body_xml: string,
     *   body_sha256: string,
     *   body_bytes: int,
     *   schema_version: string,
     *   rim_version: string,
     *   schema_validation: array{status: string, errors: list<string>}
     * }
     */
    public function build(
        Ct600ReturnData $return,
        Ct600IxbrlArtifact $accounts,
        Ct600IxbrlArtifact $computation,
        ?string $schemaPath = null,
        ?callable $crossDocumentValidator = null,
    ): array {
        $errors = array_merge(
            $return->scopeBlockers(),
            $this->artifactErrors($return, $accounts, $computation)
        );
        if ($crossDocumentValidator !== null) {
            $hookResult = $crossDocumentValidator($return, $accounts, $computation);
            $hookErrors = array_is_list($hookResult)
                ? $hookResult
                : (array)($hookResult['errors'] ?? []);
            foreach ($hookErrors as $hookError) {
                if (is_string($hookError) && trim($hookError) !== '') {
                    $errors[] = trim($hookError);
                }
            }
        }
        if ($errors !== []) {
            throw new \DomainException(implode(' ', array_values(array_unique($errors))));
        }

        $accountsContent = file_get_contents($accounts->path);
        $computationContent = file_get_contents($computation->path);
        if (!is_string($accountsContent) || !is_string($computationContent)) {
            throw new \RuntimeException('Unable to read the validated iXBRL filing artifacts.');
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = true;
        $root = $document->createElementNS(self::CT_NAMESPACE, 'IRenvelope');
        $document->appendChild($root);

        $this->appendHeader($document, $root, $return);
        $companyTaxReturn = $this->element($document, $root, 'CompanyTaxReturn');
        $companyTaxReturn->setAttribute('ReturnType', 'new');
        $this->appendCompanyInformation($document, $companyTaxReturn, $return);
        $this->appendReturnInformation($document, $companyTaxReturn, $return);
        $this->appendCalculation($document, $companyTaxReturn, $return);
        $this->appendAllowanceAndLossInformation($document, $companyTaxReturn, $return);
        $this->appendDeclaration($document, $companyTaxReturn, $return);
        $this->appendIxbrl($document, $companyTaxReturn, $computation, $computationContent, $accounts, $accountsContent);

        $xml = $document->saveXML();
        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException('Unable to serialise the CT/5 IRenvelope.');
        }
        $bodyBytes = strlen($xml);
        if ($bodyBytes > $this->maxBodyBytes) {
            throw new \DomainException(
                'The base64 CT600 IRenvelope is ' . $bodyBytes . ' bytes and exceeds the '
                . $this->maxBodyBytes . '-byte filing limit.'
            );
        }

        $schemaValidation = ['status' => 'not_run', 'errors' => []];
        if ($schemaPath !== null) {
            $schemaValidation = $this->validateSchema($xml, $schemaPath);
            if ($schemaValidation['status'] !== 'passed') {
                throw new \DomainException('CT600 RIM validation failed: ' . implode(' ', $schemaValidation['errors']));
            }
        }

        return [
            'xml' => $xml,
            'body_xml' => $xml,
            'body_sha256' => hash('sha256', $xml),
            'body_bytes' => $bodyBytes,
            'schema_version' => $return->schemaVersion,
            'rim_version' => self::RIM_VERSION,
            'schema_validation' => $schemaValidation,
        ];
    }

    /** @return array{status: string, errors: list<string>} */
    public function validateSchema(string $xml, string $schemaPath): array
    {
        if (!is_file($schemaPath)) {
            return ['status' => 'failed', 'errors' => ['Configured CT600 RIM XSD was not found.']];
        }
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadXML($xml, \LIBXML_NONET);
            $valid = $loaded && $document->schemaValidate($schemaPath);
            $libxmlErrors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($valid) {
            return ['status' => 'passed', 'errors' => []];
        }

        $errors = [];
        foreach ($libxmlErrors as $error) {
            $message = trim($error->message);
            if ($message !== '') {
                $errors[] = 'Line ' . $error->line . ': ' . $message;
            }
        }

        return [
            'status' => 'failed',
            'errors' => $errors !== [] ? array_values(array_unique($errors)) : ['CT600 XML did not validate against the configured RIM XSD.'],
        ];
    }

    /** @return list<string> */
    private function artifactErrors(
        Ct600ReturnData $return,
        Ct600IxbrlArtifact $accounts,
        Ct600IxbrlArtifact $computation,
    ): array {
        $errors = array_merge($accounts->verificationErrors(), $computation->verificationErrors());
        if ($accounts->documentType !== Ct600IxbrlArtifact::ACCOUNTS) {
            $errors[] = 'The accounts attachment is not identified as accounts iXBRL.';
        }
        if ($computation->documentType !== Ct600IxbrlArtifact::COMPUTATION) {
            $errors[] = 'The computation attachment is not identified as computations iXBRL.';
        }
        if ($accounts->runId !== $return->accountsRunId) {
            $errors[] = 'Accounts iXBRL run does not match the frozen return data.';
        }
        if ($computation->runId !== $return->computationRunId) {
            $errors[] = 'Computations iXBRL run does not match the selected CT-period computation.';
        }
        if ($accounts->periodStart !== $return->accountingPeriodStart || $accounts->periodEnd !== $return->accountingPeriodEnd) {
            $errors[] = 'Accounts iXBRL period does not match the full accounting period.';
        }
        if ($computation->periodStart !== $return->periodStart || $computation->periodEnd !== $return->periodEnd) {
            $errors[] = 'Computations iXBRL period does not match the selected CT600 period.';
        }
        if ($accounts->registrationNumber === null) {
            $errors[] = 'Accounts iXBRL validated metadata does not contain the company registration number.';
        } elseif (!hash_equals($return->registrationNumber, $accounts->registrationNumber)) {
            $errors[] = 'Accounts iXBRL company registration number does not match the CT600.';
        }
        if ($computation->utr === null) {
            $errors[] = 'Computations iXBRL validated metadata does not contain the Corporation Tax UTR.';
        } elseif (!hash_equals($return->utr, $computation->utr)) {
            $errors[] = 'Computations iXBRL UTR does not match the CT600.';
        }
        if ($accounts->utr !== null && !hash_equals($return->utr, $accounts->utr)) {
            $errors[] = 'Accounts iXBRL UTR metadata does not match the CT600.';
        }
        if ($computation->registrationNumber !== null && !hash_equals($return->registrationNumber, $computation->registrationNumber)) {
            $errors[] = 'Computations iXBRL company registration number does not match the CT600.';
        }

        return $errors;
    }

    private function appendHeader(\DOMDocument $document, \DOMElement $root, Ct600ReturnData $return): void
    {
        $header = $this->element($document, $root, 'IRheader');
        $keys = $this->element($document, $header, 'Keys');
        $utr = $this->text($document, $keys, 'Key', $return->utr);
        $utr->setAttribute('Type', 'UTR');
        $this->text($document, $header, 'PeriodEnd', $return->periodEnd);
        $this->text($document, $header, 'DefaultCurrency', 'GBP');
        $manifest = $this->element($document, $header, 'Manifest');
        $contains = $this->element($document, $manifest, 'Contains');
        $reference = $this->element($document, $contains, 'Reference');
        $this->text($document, $reference, 'Namespace', self::CT_NAMESPACE);
        $this->text($document, $reference, 'SchemaVersion', $return->schemaVersion);
        $this->text($document, $reference, 'TopElementName', 'CompanyTaxReturn');
        $irMark = $this->text($document, $header, 'IRmark', '');
        $irMark->setAttribute('Type', 'generic');
        $this->text($document, $header, 'Sender', 'Company');
    }

    private function appendCompanyInformation(
        \DOMDocument $document,
        \DOMElement $companyTaxReturn,
        Ct600ReturnData $return,
    ): void {
        $company = $this->element($document, $companyTaxReturn, 'CompanyInformation');
        $this->text($document, $company, 'CompanyName', $return->companyName);
        $this->text($document, $company, 'RegistrationNumber', $return->registrationNumber);
        $this->text($document, $company, 'Reference', $return->utr);
        $this->text($document, $company, 'CompanyType', (string)$return->companyType);
        $period = $this->element($document, $company, 'PeriodCovered');
        $this->text($document, $period, 'From', $return->periodStart);
        $this->text($document, $period, 'To', $return->periodEnd);
    }

    private function appendReturnInformation(
        \DOMDocument $document,
        \DOMElement $companyTaxReturn,
        Ct600ReturnData $return,
    ): void {
        $summary = $this->element($document, $companyTaxReturn, 'ReturnInfoSummary');
        $this->text($document, $summary, 'ThisPeriod', 'yes');
        if ($return->multipleReturns) {
            $this->text($document, $summary, 'MultipleReturns', 'yes');
        }
        $accounts = $this->element($document, $summary, 'Accounts');
        if (
            $return->accountingPeriodStart === $return->periodStart
            && $return->accountingPeriodEnd === $return->periodEnd
        ) {
            $this->text($document, $accounts, 'ThisPeriodAccounts', 'yes');
        } else {
            // A long accounting period supplies one accounts iXBRL document
            // with each period-specific CT600.  CT/5 distinguishes that from
            // accounts covering only this return's Corporation Tax period.
            $this->text($document, $accounts, 'DifferentPeriod', 'yes');
        }
        $computations = $this->element($document, $summary, 'Computations');
        $this->text($document, $computations, 'ThisPeriodComputations', 'yes');

        $turnover = $this->element($document, $companyTaxReturn, 'Turnover');
        $this->text($document, $turnover, 'Total', $this->wholePounds($return->amount(Ct600ReturnData::TURNOVER)));
    }

    private function appendCalculation(
        \DOMDocument $document,
        \DOMElement $companyTaxReturn,
        Ct600ReturnData $return,
    ): void {
        $calculation = $this->element($document, $companyTaxReturn, 'CompanyTaxCalculation');
        $income = $this->element($document, $calculation, 'Income');
        $trading = $this->element($document, $income, 'Trading');
        $this->text($document, $trading, 'Profits', $this->wholePounds($return->amount(Ct600ReturnData::TRADING_PROFITS)));
        $this->text($document, $trading, 'LossesBroughtForward', $this->wholePounds($return->amount(Ct600ReturnData::LOSSES_BROUGHT_FORWARD)));
        $this->text($document, $trading, 'NetProfits', $this->wholePounds($return->amount(Ct600ReturnData::NET_TRADING_PROFITS)));
        $this->text(
            $document,
            $calculation,
            'ProfitsBeforeOtherDeductions',
            $this->wholePounds($return->amount(Ct600ReturnData::PROFITS_BEFORE_OTHER_DEDUCTIONS))
        );

        $deductionValues = [
            'CapitalAllowances' => $return->amount(Ct600ReturnData::CAPITAL_ALLOWANCES),
            'TradingLosses' => $return->amount(Ct600ReturnData::TRADING_LOSSES),
            'TradingLossesCarriedForward' => $return->amount(Ct600ReturnData::TRADING_LOSSES_CARRIED_FORWARD),
        ];
        if (array_sum($deductionValues) > 0) {
            $deductions = $this->element($document, $calculation, 'DeductionsAndReliefs');
            foreach ($deductionValues as $name => $value) {
                if ($value > 0) {
                    $this->text($document, $deductions, $name, $this->wholePounds($value));
                }
            }
            $this->text($document, $deductions, 'Total', $this->wholePounds(array_sum($deductionValues)));
        }

        $charges = $this->element($document, $calculation, 'ChargesAndReliefs');
        $this->text(
            $document,
            $charges,
            'ProfitsBeforeDonationsAndGroupRelief',
            $this->wholePounds($return->amount(Ct600ReturnData::PROFITS_BEFORE_DONATIONS_AND_GROUP_RELIEF))
        );
        $this->text($document, $calculation, 'ChargeableProfits', $this->wholePounds($return->amount(Ct600ReturnData::CHARGEABLE_PROFITS)));
        $this->text($document, $calculation, 'CorporationTax', $this->poundPence($return->amount(Ct600ReturnData::CORPORATION_TAX)));
        $this->text(
            $document,
            $calculation,
            'NetCorporationTaxChargeable',
            $this->poundPence($return->amount(Ct600ReturnData::NET_CORPORATION_TAX))
        );
        $taxReliefs = $this->element($document, $calculation, 'TaxReliefsAndDeductions');
        $this->text(
            $document,
            $taxReliefs,
            'TotalReliefsAndDeductions',
            $this->poundPence($return->amount(Ct600ReturnData::TOTAL_RELIEFS_AND_DEDUCTIONS))
        );

        $outstanding = $this->element($document, $companyTaxReturn, 'CalculationOfTaxOutstandingOrOverpaid');
        $this->text(
            $document,
            $outstanding,
            'NetCorporationTaxLiability',
            $this->poundPence($return->amount(Ct600ReturnData::NET_CORPORATION_TAX))
        );
        $this->text($document, $outstanding, 'TaxChargeable', $this->poundPence($return->amount(Ct600ReturnData::CORPORATION_TAX)));
        $this->text($document, $outstanding, 'TaxPayable', $this->poundPence($return->amount(Ct600ReturnData::TAX_PAYABLE)));
    }

    private function appendAllowanceAndLossInformation(
        \DOMDocument $document,
        \DOMElement $companyTaxReturn,
        Ct600ReturnData $return,
    ): void {
        $aia = $return->amount(Ct600ReturnData::AIA);
        if ($aia > 0) {
            $allowances = $this->element($document, $companyTaxReturn, 'AllowancesAndCharges');
            $this->text($document, $allowances, 'AIACapitalAllowancesInc', $this->wholePounds($aia));
        }
        $loss = $return->amount(Ct600ReturnData::LOSS_ARISING);
        if ($loss > 0) {
            $losses = $this->element($document, $companyTaxReturn, 'LossesDeficitsAndExcess');
            $amountArising = $this->element($document, $losses, 'AmountArising');
            $ukTradeLosses = $this->element($document, $amountArising, 'LossesOfTradesUK');
            $this->text($document, $ukTradeLosses, 'Arising', $this->wholePounds($loss));
        }
    }

    private function appendDeclaration(
        \DOMDocument $document,
        \DOMElement $companyTaxReturn,
        Ct600ReturnData $return,
    ): void {
        $declaration = $this->element($document, $companyTaxReturn, 'Declaration');
        $this->text($document, $declaration, 'AcceptDeclaration', 'yes');
        $this->text($document, $declaration, 'Name', $return->declarationName);
        $this->text($document, $declaration, 'Status', $return->declarationStatus);
    }

    private function appendIxbrl(
        \DOMDocument $document,
        \DOMElement $companyTaxReturn,
        Ct600IxbrlArtifact $computation,
        string $computationContent,
        Ct600IxbrlArtifact $accounts,
        string $accountsContent,
    ): void {
        $attachedFiles = $this->element($document, $companyTaxReturn, 'AttachedFiles');
        $xbrlSubmission = $this->element($document, $attachedFiles, 'XBRLsubmission');

        // When both are present the RIM choice requires Computation before Accounts.
        $this->appendEncodedIxbrl($document, $xbrlSubmission, 'Computation', $computation, $computationContent);
        $this->appendEncodedIxbrl($document, $xbrlSubmission, 'Accounts', $accounts, $accountsContent);
    }

    private function appendEncodedIxbrl(
        \DOMDocument $document,
        \DOMElement $xbrlSubmission,
        string $containerName,
        Ct600IxbrlArtifact $artifact,
        string $content,
    ): void {
        $container = $this->element($document, $xbrlSubmission, $containerName);
        $instance = $this->element($document, $container, 'Instance');
        $instance->setAttribute('Filename', $artifact->filename);
        $instance->setAttribute('BaseTaxonomyVersionDate', $artifact->baseTaxonomyVersionDate);
        $encoded = $this->text($document, $instance, 'EncodedInlineXBRLDocument', base64_encode($content));
        $encoded->setAttribute('Filename', $artifact->filename);
        $encoded->setAttribute('entryPoint', 'yes');
    }

    private function wholePounds(int $value): string
    {
        return $value . '.00';
    }

    private function poundPence(int $value): string
    {
        return intdiv($value, 100) . '.' . str_pad((string)($value % 100), 2, '0', \STR_PAD_LEFT);
    }

    private function element(\DOMDocument $document, \DOMElement $parent, string $name): \DOMElement
    {
        $element = $document->createElementNS(self::CT_NAMESPACE, $name);
        $parent->appendChild($element);
        return $element;
    }

    private function text(\DOMDocument $document, \DOMElement $parent, string $name, string $value): \DOMElement
    {
        $element = $this->element($document, $parent, $name);
        if ($value !== '') {
            $element->appendChild($document->createTextNode($value));
        }
        return $element;
    }
}
