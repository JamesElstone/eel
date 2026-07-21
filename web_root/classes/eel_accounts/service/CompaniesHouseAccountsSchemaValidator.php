<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseAccountsSchemaValidator
{
    public function validateAccountsRequest(string $xml, string $manifestSha256): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', strtolower($manifestSha256))) {
            throw new \InvalidArgumentException('A verified Companies House schema manifest is required.');
        }
        $snapshot = \InterfaceDB::fetchOne(
            'SELECT * FROM companies_house_schema_snapshots WHERE manifest_sha256 = :manifest AND profile_name = :profile AND is_active = 1 LIMIT 1',
            ['manifest' => strtolower($manifestSha256), 'profile' => CompaniesHouseAccountsSchemaService::PROFILE_NAME]
        );
        if (!is_array($snapshot)) {
            throw new \RuntimeException('The selected Companies House schema snapshot is not active.');
        }
        $files = \InterfaceDB::fetchAll('SELECT * FROM companies_house_schema_files WHERE snapshot_id = :id', ['id'=>(int)$snapshot['id']]);
        if ($files === []) {
            throw new \RuntimeException('The Companies House schema snapshot has no verified files.');
        }
        $root = rtrim((string)$snapshot['local_path'], '/\\');
        $envelope = null;
        $form = null;
        foreach ($files as $file) {
            $path = $root . '/' . ltrim(str_replace('\\', '/', (string)$file['relative_path']), '/');
            if (!is_file($path) || !hash_equals(strtolower((string)$file['sha256']), strtolower((string)hash_file('sha256', $path)))) {
                throw new \RuntimeException('A verified Companies House schema file is missing or has changed.');
            }
            if ((string)$file['file_role'] === 'envelope') { $envelope = $path; }
            if ((string)$file['schema_name'] === 'FormSubmission-v2-11.xsd') { $form = $path; }
        }
        if ($envelope === null || $form === null) {
            throw new \RuntimeException('The Companies House accounts schema profile is incomplete.');
        }
        $document = $this->loadXml($xml);
        $this->schemaValidate($document, $envelope, 'GovTalk envelope');

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('f', 'http://xmlgw.companieshouse.gov.uk/Header');
        $element = $xpath->query('//f:FormSubmission')?->item(0);
        if (!$element instanceof \DOMElement) {
            throw new \RuntimeException('The prepared request does not contain a Companies House FormSubmission.');
        }
        $subtree = new \DOMDocument('1.0', 'UTF-8');
        $subtree->appendChild($subtree->importNode($element, true));
        $this->schemaValidate($subtree, $form, 'FormSubmission');
        return ['success'=>true,'snapshot_id'=>(int)$snapshot['id'],'manifest_sha256'=>(string)$snapshot['manifest_sha256']];
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
