<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseAccountsSchemaValidator
{
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
        [$root, $files] = $this->verifiedFiles($schemaInventory);
        $envelope = null;
        $operationSchema = null;
        foreach ($files as $file) {
            $path = $root . '/' . ltrim(str_replace('\\', '/', (string)$file['relative_path']), '/');
            if (!is_file($path) || !hash_equals(strtolower((string)$file['sha256']), strtolower((string)hash_file('sha256', $path)))) {
                throw new \RuntimeException('A verified Companies House schema file is missing or has changed.');
            }
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
        [$root, $files] = $this->verifiedFiles($schemaInventory);
        $envelope = null;
        foreach ($files as $file) {
            $path = $root . '/' . ltrim(str_replace('\\', '/', (string)$file['relative_path']), '/');
            if (!is_file($path)
                || !hash_equals(
                    strtolower((string)$file['sha256']),
                    strtolower((string)hash_file('sha256', $path))
                )) {
                throw new \RuntimeException(
                    'A verified Companies House schema file is missing or has changed.'
                );
            }
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
            $files[] = $stored;
        }
        return [$root, $files];
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
        $previous = libxml_use_internal_errors(true);
        try { $ok = $document->schemaValidate($schema, LIBXML_NONET); $errors = libxml_get_errors(); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$ok) { throw new \RuntimeException('Companies House ' . $label . ' schema validation failed: ' . $this->errorText($errors ?? [])); }
    }

    private function errorText(array $errors): string
    {
        $messages=[]; foreach(array_slice($errors,0,5) as $error){$message=trim(preg_replace('/\s+/',' ',(string)($error->message ?? '')) ?? ''); if($message!==''){$messages[]=$message;}}
        return $messages === [] ? 'unknown XML validation error' : implode('; ', $messages);
    }
}
