<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseAccountsSchemaValidator
{
    private const VALIDATION_PROFILE = 'libxml-v1';

    public function validateAccountsRequest(string $xml, array $schemaInventory): array
    {
        return $this->validateOperationRequest(
            $xml,
            $schemaInventory,
            'FormSubmission-v2-11.xsd',
            'FormSubmission',
            'http://xmlgw.companieshouse.gov.uk/Header'
        );
    }

    public function validateOperationRequest(
        string $xml,
        array $schemaInventory,
        string $schemaName,
        string $elementName,
        string $namespace
    ): array {
        [, $validationRoot, $files] = $this->verifiedFiles($schemaInventory);
        $envelope = null;
        $operationSchema = null;
        foreach ($files as $file) {
            $path = $validationRoot . '/' . ltrim(str_replace(
                '\\',
                '/',
                (string)$file['validation_relative_path']
            ), '/');
            if ((string)$file['file_role'] === 'envelope') { $envelope = $path; }
            if ((string)$file['schema_name'] === $schemaName) { $operationSchema = $path; }
        }
        if ($envelope === null || $operationSchema === null) {
            throw new \RuntimeException('The Companies House protocol schema profile is incomplete.');
        }
        $document = $this->loadXml($xml);
        $this->schemaValidate($document, $envelope, 'GovTalk envelope');

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('operation', $namespace);
        $element = $xpath->query('//operation:' . $elementName)?->item(0);
        if (!$element instanceof \DOMElement) {
            throw new \RuntimeException(
                'The prepared request does not contain Companies House ' . $elementName . '.'
            );
        }
        $subtree = new \DOMDocument('1.0', 'UTF-8');
        $subtree->appendChild($subtree->importNode($element, true));
        $this->schemaValidate($subtree, $operationSchema, $elementName);
        return ['success'=>true,'files'=>$schemaInventory['files'] ?? $schemaInventory];
    }

    public function validateEnvelopeResponse(string $xml, array $schemaInventory): array
    {
        [, $validationRoot, $files] = $this->verifiedFiles($schemaInventory);
        $envelope = null;
        foreach ($files as $file) {
            $path = $validationRoot . '/' . ltrim(str_replace(
                '\\',
                '/',
                (string)$file['validation_relative_path']
            ), '/');
            if ((string)$file['file_role'] === 'envelope') {
                $envelope = $path;
            }
        }
        if ($envelope === null) {
            throw new \RuntimeException('The Companies House envelope schema is unavailable.');
        }
        $this->schemaValidate($this->loadXml($xml), $envelope, 'GovTalk response envelope');
        return [
            'success' => true,
            'files' => $schemaInventory['files'] ?? $schemaInventory,
        ];
    }

    private function verifiedFiles(array $schemaInventory): array
    {
        $evidenceFiles = array_is_list($schemaInventory)
            ? $schemaInventory
            : (array)($schemaInventory['files'] ?? []);
        if ($evidenceFiles === []) {
            throw new \InvalidArgumentException('A verified Companies House schema file inventory is required.');
        }
        $root = rtrim(
            (string)($schemaInventory['root_path']
                ?? dirname(__DIR__, 4) . '/third_party/companies_house/assets'),
            '/\\'
        );
        $validationProfile = trim((string)($schemaInventory['validation_profile']
            ?? self::VALIDATION_PROFILE));
        if ($validationProfile !== self::VALIDATION_PROFILE) {
            throw new \RuntimeException(
                'The Companies House schema validation profile is not supported.'
            );
        }
        $validationRoot = rtrim(
            (string)($schemaInventory['validation_root_path']
                ?? dirname(__DIR__, 4) . '/third_party/companies_house/validation/'
                    . $validationProfile),
            '/\\'
        );
        $files = [];
        foreach ($evidenceFiles as $evidence) {
            $url = trim((string)($evidence['source_url'] ?? ''));
            $relativePath = ltrim(str_replace('\\', '/', (string)($evidence['relative_path'] ?? '')), '/');
            $expectedHash = strtolower(trim((string)($evidence['sha256'] ?? '')));
            if ($url === '' || $relativePath === '' || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
                throw new \RuntimeException('Companies House schema validation evidence is incomplete.');
            }
            $stored = \InterfaceDB::fetchOne(
                'SELECT * FROM companies_house_schema_files
                 WHERE source_url = :url AND relative_path = :path AND sha256 = :sha LIMIT 1',
                ['url' => $url, 'path' => $relativePath, 'sha' => $expectedHash]
            );
            if (!is_array($stored)) {
                throw new \RuntimeException('A recorded Companies House schema file is no longer installed.');
            }
            $officialPath = $root . '/' . ltrim(str_replace(
                '\\',
                '/',
                (string)$stored['relative_path']
            ), '/');
            if (!is_file($officialPath)
                || !hash_equals(
                    strtolower((string)$stored['sha256']),
                    strtolower((string)hash_file('sha256', $officialPath))
                )) {
                throw new \RuntimeException(
                    'A verified Companies House schema file is missing or has changed.'
                );
            }
            $storedValidationProfile = trim((string)(
                $stored['validation_profile'] ?? ''
            ));
            $validationRelativePath = ltrim(str_replace(
                '\\',
                '/',
                trim((string)($stored['validation_relative_path'] ?? ''))
            ), '/');
            $validationHash = strtolower(trim((string)(
                $stored['validation_sha256'] ?? ''
            )));
            $validationPath = $validationRoot . '/' . $validationRelativePath;
            if ($storedValidationProfile !== $validationProfile
                || $validationRelativePath === ''
                || preg_match('/^[a-f0-9]{64}$/D', $validationHash) !== 1
                || !is_file($validationPath)
                || !hash_equals(
                    $validationHash,
                    strtolower((string)hash_file('sha256', $validationPath))
                )) {
                throw new \RuntimeException(
                    'A verified Companies House validation schema is missing or has changed. '
                    . 'Refresh it from Artefacts before filing.'
                );
            }
            $files[] = $stored;
        }
        return [$root, $validationRoot, $files];
    }

    private function loadXml(string $xml): \DOMDocument
    {
        if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \RuntimeException('The prepared Companies House request is empty or unsafe.');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $ok = $document->loadXML($xml, LIBXML_NONET); $errors = libxml_get_errors(); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$ok) { throw new \RuntimeException('The prepared Companies House request is not valid XML: ' . $this->errorText($errors ?? [])); }
        return $document;
    }

    private function schemaValidate(\DOMDocument $document, string $schema, string $label): void
    {
        (new CompaniesHouseXmlSchemaValidationService())
            ->validateDocument($document, $schema, $label);
    }

    private function errorText(array $errors): string
    {
        $messages=[]; foreach(array_slice($errors,0,5) as $error){$message=trim(preg_replace('/\s+/',' ',(string)($error->message ?? '')) ?? ''); if($message!==''){$messages[]=$message;}}
        return $messages === [] ? 'unknown XML validation error' : implode('; ', $messages);
    }
}
