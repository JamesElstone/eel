<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds an immediate-use CT600 GovTalk submission request and applies IRmark. */
final class GovTalkEnvelopeBuilder
{
    public const ENVELOPE_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';

    private readonly IrmarkService $irMarkService;

    public function __construct(?IrmarkService $irMarkService = null)
    {
        $this->irMarkService = $irMarkService ?? new IrmarkService();
    }

    /**
     * The returned envelope contains clear Government Gateway credentials and
     * must be transmitted immediately; only ir_envelope_xml is artifact-safe.
     *
     * @return array{
     *   xml: string,
     *   envelope_xml: string,
     *   ir_envelope_xml: string,
     *   irmark: string,
     *   irmark_display: string,
     *   canonical_body_sha256: string,
     *   environment: string,
     *   class: string,
     *   transaction_id: string,
     *   envelope_schema_validation: array{status: string, errors: list<string>}
     * }
     */
    public function buildSubmission(
        string $irEnvelopeXml,
        string $environment,
        string $transactionId,
        string $senderId,
        string $password,
        string $utr,
        string $vendorId,
        string $product,
        string $productVersion,
        ?string $envelopeSchemaPath = null,
    ): array {
        $environment = strtoupper(trim($environment));
        if (!in_array($environment, ['TEST', 'TIL', 'LIVE'], true)) {
            throw new \InvalidArgumentException('CT600 environment must be TEST, TIL or LIVE.');
        }
        if (!preg_match('/^[0-9A-F]{1,32}$/D', $transactionId)) {
            throw new \InvalidArgumentException('GovTalk transaction ID must contain 1 to 32 uppercase hexadecimal characters.');
        }
        if ($senderId === '' || $password === '') {
            throw new \InvalidArgumentException('Government Gateway Sender ID and password are required.');
        }
        if (!preg_match('/^[0-9]{10}$/D', $utr)) {
            throw new \InvalidArgumentException('Corporation Tax UTR must contain exactly 10 digits.');
        }
        if (!preg_match('/^[0-9]{4}$/D', $vendorId)) {
            throw new \InvalidArgumentException('HMRC Software Developers Support Team Vendor ID must contain four digits.');
        }
        if (trim($product) === '' || trim($productVersion) === '') {
            throw new \InvalidArgumentException('GovTalk ChannelRouting product and version are required.');
        }

        $irDocument = $this->parse($irEnvelopeXml, 'CT/5 IRenvelope');
        $irRoot = $irDocument->documentElement;
        if (
            !$irRoot instanceof \DOMElement
            || $irRoot->localName !== 'IRenvelope'
            || $irRoot->namespaceURI !== Ct600XmlBuilder::CT_NAMESPACE
        ) {
            throw new \InvalidArgumentException('CT600 body must contain one CT/5 IRenvelope document element.');
        }
        $this->assertBodyUtr($irDocument, $utr);

        $class = $environment === 'TIL' ? 'HMRC-CT-CT600-TIL' : 'HMRC-CT-CT600';
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = true;
        $root = $document->createElementNS(self::ENVELOPE_NAMESPACE, 'GovTalkMessage');
        $document->appendChild($root);
        $this->text($document, $root, 'EnvelopeVersion', '2.0');

        $header = $this->element($document, $root, 'Header');
        $details = $this->element($document, $header, 'MessageDetails');
        $this->text($document, $details, 'Class', $class);
        $this->text($document, $details, 'Qualifier', 'request');
        $this->text($document, $details, 'Function', 'submit');
        $this->text($document, $details, 'TransactionID', $transactionId);
        $this->text($document, $details, 'CorrelationID', '');
        $this->text($document, $details, 'Transformation', 'XML');
        $this->text($document, $details, 'GatewayTest', $environment === 'TEST' ? '1' : '0');

        $sender = $this->element($document, $header, 'SenderDetails');
        $authentication = $this->element($document, $sender, 'IDAuthentication');
        $this->text($document, $authentication, 'SenderID', $senderId);
        $credential = $this->element($document, $authentication, 'Authentication');
        $this->text($document, $credential, 'Method', 'clear');
        $this->text($document, $credential, 'Role', 'Principal');
        $this->text($document, $credential, 'Value', $password);

        $govTalkDetails = $this->element($document, $root, 'GovTalkDetails');
        $keys = $this->element($document, $govTalkDetails, 'Keys');
        $key = $this->text($document, $keys, 'Key', $utr);
        $key->setAttribute('Type', 'UTR');
        $target = $this->element($document, $govTalkDetails, 'TargetDetails');
        $this->text($document, $target, 'Organisation', 'HMRC');
        $channelRouting = $this->element($document, $govTalkDetails, 'ChannelRouting');
        $channel = $this->element($document, $channelRouting, 'Channel');
        $this->text($document, $channel, 'URI', $vendorId);
        $this->text($document, $channel, 'Product', trim($product));
        $this->text($document, $channel, 'Version', trim($productVersion));

        $body = $this->element($document, $root, 'Body');
        $body->appendChild($document->importNode($irRoot, true));

        $unsignedXml = $document->saveXML();
        if (!is_string($unsignedXml) || $unsignedXml === '') {
            throw new \RuntimeException('Unable to serialise the GovTalk CT600 submission request.');
        }
        $result = $this->irMarkService->applyToGovTalkXml($unsignedXml);

        $schemaValidation = ['status' => 'not_run', 'errors' => []];
        if ($envelopeSchemaPath !== null) {
            $schemaValidation = $this->validateEnvelopeSchema($result['xml'], $envelopeSchemaPath);
            if ($schemaValidation['status'] !== 'passed') {
                throw new \DomainException(
                    'GovTalk envelope validation failed: ' . implode(' ', $schemaValidation['errors'])
                );
            }
        }

        return $result + [
            'environment' => $environment,
            'class' => $class,
            'transaction_id' => $transactionId,
            'envelope_schema_validation' => $schemaValidation,
        ];
    }

    /** @return array{status: string, errors: list<string>} */
    public function validateEnvelopeSchema(string $xml, string $schemaPath): array
    {
        if (!is_file($schemaPath)) {
            return ['status' => 'failed', 'errors' => ['Configured GovTalk 2.0 envelope XSD was not found.']];
        }
        $document = new \DOMDocument();
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
            'errors' => $errors !== [] ? array_values(array_unique($errors)) : ['GovTalk XML did not validate against the configured envelope XSD.'],
        ];
    }

    private function assertBodyUtr(\DOMDocument $document, string $utr): void
    {
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(
            '/*[local-name()="IRenvelope" and namespace-uri()="' . Ct600XmlBuilder::CT_NAMESPACE . '"]'
            . '/*[local-name()="IRheader"]/*[local-name()="Keys"]/*[local-name()="Key" and @Type="UTR"]'
        );
        if ($nodes === false || $nodes->length !== 1 || trim((string)$nodes->item(0)?->textContent) !== $utr) {
            throw new \DomainException('GovTalk UTR does not match the CT/5 IRheader UTR.');
        }
    }

    private function parse(string $xml, string $label): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadXML($xml, \LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            $message = isset($errors[0]) ? trim($errors[0]->message) : 'unknown XML parse error';
            throw new \InvalidArgumentException($label . ' XML is not well formed: ' . $message);
        }
        return $document;
    }

    private function element(\DOMDocument $document, \DOMElement $parent, string $name): \DOMElement
    {
        $element = $document->createElementNS(self::ENVELOPE_NAMESPACE, $name);
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
