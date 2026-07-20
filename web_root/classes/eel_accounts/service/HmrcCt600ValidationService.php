<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Reproducible local XSD and HMRC compiled-Schematron validation. */
final class HmrcCt600ValidationService
{
    public const VALIDATOR_VERSION = 'hmrc-ct600-local-v1';
    private const ENVELOPE_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';
    private const ERROR_NAMESPACE = 'http://www.govtalk.gov.uk/CM/errorresponse';

    /** @return array<string,mixed> */
    public function resolveArtifacts(array $rim): array
    {
        $packageId = (int)($rim['package_id'] ?? $rim['id'] ?? 0);
        if ($packageId <= 0 || !\InterfaceDB::tableExists('hmrc_ct_rim_packages')
            || !\InterfaceDB::tableExists('hmrc_ct_rim_files')) {
            return $this->failure('The selected RIM package is not catalogued locally.');
        }
        $package = \InterfaceDB::fetchOne(
            'SELECT * FROM hmrc_ct_rim_packages WHERE id = :id LIMIT 1',
            ['id' => $packageId]
        );
        if (!is_array($package) || !in_array((string)$package['package_state'], ['verified', 'stale'], true)) {
            return $this->failure('The selected RIM package is not verified.');
        }
        $packagePath = trim((string)($package['local_path'] ?? ''));
        $packageHash = strtolower(trim((string)($package['sha256'] ?? '')));
        if ($packagePath === '' || !is_file($packagePath) || preg_match('/^[a-f0-9]{64}$/', $packageHash) !== 1
            || !hash_equals($packageHash, strtolower((string)(hash_file('sha256', $packagePath) ?: '')))) {
            return $this->failure('The selected RIM package archive is missing or has changed.');
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM hmrc_ct_rim_files
             WHERE package_id = :package_id
               AND file_role IN (:primary, :envelope, :transform, :schematron)
             ORDER BY id',
            [
                'package_id' => $packageId,
                'primary' => 'primary_schema',
                'envelope' => 'envelope_schema',
                'transform' => 'transform',
                'schematron' => 'schematron',
            ]
        );
        $byRole = [];
        foreach ($rows as $row) {
            $role = (string)$row['file_role'];
            $byRole[$role][] = $row;
        }
        $errors = [];
        $artifacts = [];
        foreach (['primary_schema', 'envelope_schema', 'transform', 'schematron'] as $role) {
            if (count($byRole[$role] ?? []) !== 1) {
                $errors[] = 'The RIM package must contain exactly one catalogued ' . str_replace('_', ' ', $role) . '.';
                continue;
            }
            $row = $byRole[$role][0];
            $path = trim((string)($row['extracted_path'] ?? ''));
            $expected = strtolower(trim((string)($row['sha256'] ?? '')));
            $actual = $path !== '' && is_file($path) ? strtolower((string)(hash_file('sha256', $path) ?: '')) : '';
            if ($path === '' || $actual === '' || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1
                || !hash_equals($expected, $actual)) {
                $errors[] = 'The catalogued ' . str_replace('_', ' ', $role) . ' is missing or has changed.';
                continue;
            }
            $artifacts[$role] = [
                'id' => (int)$row['id'],
                'path' => $path,
                'sha256' => $expected,
                'archive_path' => (string)$row['archive_path'],
            ];
        }
        if ($errors !== []) {
            return $this->failure('The selected RIM validation artifacts are incomplete.', $errors);
        }
        return [
            'ok' => true,
            'errors' => [],
            'warnings' => [],
            'package_id' => $packageId,
            'package_sha256' => $packageHash,
            'artifacts' => $artifacts,
        ];
    }

    /** Validate the inner CT IRenvelope before attachments/IRmark are applied. */
    public function validateIrEnvelope(string $xml, array $rim): array
    {
        $artifacts = $this->resolveArtifacts($rim);
        if (empty($artifacts['ok'])) {
            return $artifacts;
        }
        $parsed = $this->document($xml);
        if (!$parsed['ok']) {
            return $this->validationResult('ct_xsd', $xml, $artifacts, (array)$parsed['diagnostics']);
        }
        /** @var \DOMDocument $document */
        $document = $parsed['document'];
        $root = $document->documentElement;
        $diagnostics = [];
        if (!$root instanceof \DOMElement || $root->localName !== 'IRenvelope'
            || $root->namespaceURI !== Ct600BuilderService::CT_NAMESPACE) {
            $diagnostics[] = $this->diagnostic('ct_xsd', 'root', 'The CT filing body must be the V3 IRenvelope element.', '/');
        } else {
            $diagnostics = $this->schemaDiagnostics(
                $document,
                (string)$artifacts['artifacts']['primary_schema']['path'],
                'ct_xsd'
            );
        }
        return $this->validationResult('ct_xsd', $xml, $artifacts, $diagnostics);
    }

    /** Validate the exact final GovTalk message, including HMRC business rules. */
    public function validateGovTalkEnvelope(string $xml, array $rim): array
    {
        $artifacts = $this->resolveArtifacts($rim);
        if (empty($artifacts['ok'])) {
            return $artifacts;
        }
        $parsed = $this->document($xml);
        if (!$parsed['ok']) {
            return $this->validationResult('final_package', $xml, $artifacts, (array)$parsed['diagnostics']);
        }
        /** @var \DOMDocument $document */
        $document = $parsed['document'];
        $diagnostics = $this->envelopeSchemaDiagnostics(
            $document,
            (string)$artifacts['artifacts']['envelope_schema']['path']
        );

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('hd', self::ENVELOPE_NAMESPACE);
        $xpath->registerNamespace('ct', Ct600BuilderService::CT_NAMESPACE);
        $nodes = $xpath->query('/hd:GovTalkMessage/hd:Body/ct:IRenvelope');
        if (!$nodes instanceof \DOMNodeList || $nodes->length !== 1 || !$nodes->item(0) instanceof \DOMElement) {
            $diagnostics[] = $this->diagnostic(
                'ct_xsd',
                'body',
                'The GovTalk Body must contain exactly one CT IRenvelope.',
                '/GovTalkMessage/Body'
            );
        } else {
            $inner = new \DOMDocument('1.0', 'UTF-8');
            $inner->appendChild($inner->importNode($nodes->item(0), true));
            $diagnostics = array_merge($diagnostics, $this->schemaDiagnostics(
                $inner,
                (string)$artifacts['artifacts']['primary_schema']['path'],
                'ct_xsd'
            ));
        }
        $diagnostics = array_merge($diagnostics, $this->schematronDiagnostics(
            $document,
            (string)$artifacts['artifacts']['transform']['path']
        ));
        return $this->validationResult('final_package', $xml, $artifacts, $diagnostics);
    }

    /** @return array{ok:bool,document?:\DOMDocument,diagnostics:list<array<string,mixed>>} */
    private function document(string $xml): array
    {
        if (trim($xml) === '') {
            return ['ok' => false, 'diagnostics' => [$this->diagnostic('xml', 'empty', 'The XML document is empty.', '/')]];
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new \DOMDocument();
        $ok = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        $diagnostics = $this->libxmlDiagnostics('xml');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok
            ? ['ok' => true, 'document' => $document, 'diagnostics' => []]
            : ['ok' => false, 'diagnostics' => $diagnostics !== [] ? $diagnostics : [
                $this->diagnostic('xml', 'parse', 'The XML document could not be parsed.', '/'),
            ]];
    }

    /** @return list<array<string,mixed>> */
    private function schemaDiagnostics(\DOMDocument $document, string $schemaPath, string $stage): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $document->schemaValidate($schemaPath, LIBXML_NONET);
        $diagnostics = $this->libxmlDiagnostics($stage);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? [] : ($diagnostics !== [] ? $diagnostics : [
            $this->diagnostic($stage, 'validation', 'The XML failed schema validation.', '/'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function envelopeSchemaDiagnostics(\DOMDocument $document, string $schemaPath): array
    {
        $dsigPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'xmldsig-core-schema.xsd';
        if (!is_file($dsigPath)) {
            return [$this->diagnostic('envelope_xsd', 'dependency', 'The pinned local XML signature declaration is missing.', '/')];
        }
        $previousLoader = libxml_set_external_entity_loader(static function (
            ?string $public,
            ?string $system,
            array $context
        ) use ($dsigPath, $schemaPath) {
            $system = (string)$system;
            if ($system === 'http://www.w3.org/TR/2001/PR-xmldsig-core-20010820/xmldsig-core-schema.xsd') {
                return fopen($dsigPath, 'rb');
            }
            $resolved = $system;
            if ($resolved !== '' && !preg_match('~^[A-Za-z]:[\\\\/]~', $resolved) && !str_starts_with($resolved, '/')) {
                $resolved = dirname($schemaPath) . DIRECTORY_SEPARATOR . $resolved;
            }
            $real = $resolved !== '' ? realpath($resolved) : false;
            $allowedRoot = realpath(dirname($schemaPath));
            if ($real !== false && $allowedRoot !== false
                && ($real === $schemaPath || str_starts_with($real, $allowedRoot . DIRECTORY_SEPARATOR))) {
                return fopen($real, 'rb');
            }
            return null;
        });
        try {
            return $this->schemaDiagnostics($document, $schemaPath, 'envelope_xsd');
        } finally {
            libxml_set_external_entity_loader(is_callable($previousLoader) ? $previousLoader : null);
        }
    }

    /** @return list<array<string,mixed>> */
    private function schematronDiagnostics(\DOMDocument $document, string $transformPath): array
    {
        if (!class_exists(\XSLTProcessor::class)) {
            return [$this->diagnostic('schematron', 'extension', 'PHP XSL support is required for HMRC business-rule validation.', '/')];
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $stylesheet = new \DOMDocument();
        if (!$stylesheet->load($transformPath, LIBXML_NONET | LIBXML_NOBLANKS)) {
            $diagnostics = $this->libxmlDiagnostics('schematron');
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return $diagnostics;
        }
        $processor = new \XSLTProcessor();
        if (method_exists($processor, 'setSecurityPrefs')) {
            $processor->setSecurityPrefs(
                XSL_SECPREF_WRITE_FILE | XSL_SECPREF_CREATE_DIRECTORY
                | XSL_SECPREF_READ_NETWORK | XSL_SECPREF_WRITE_NETWORK
            );
        }
        if (!$processor->importStylesheet($stylesheet)) {
            $diagnostics = $this->libxmlDiagnostics('schematron');
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return $diagnostics;
        }
        $result = $processor->transformToDoc($document);
        $libraryDiagnostics = $this->libxmlDiagnostics('schematron');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$result instanceof \DOMDocument) {
            return $libraryDiagnostics !== [] ? $libraryDiagnostics : [
                $this->diagnostic('schematron', 'transform', 'The HMRC business-rule transform did not return a result.', '/'),
            ];
        }

        $xpath = new \DOMXPath($result);
        $xpath->registerNamespace('err', self::ERROR_NAMESPACE);
        $diagnostics = [];
        foreach ($xpath->query('//err:Error') ?: [] as $error) {
            if (!$error instanceof \DOMElement) {
                continue;
            }
            $diagnostics[] = $this->diagnostic(
                'schematron',
                trim((string)$xpath->evaluate('string(err:Number)', $error)) ?: 'unknown',
                trim((string)$xpath->evaluate('string(err:Text)', $error)) ?: 'HMRC returned an unnamed business-rule diagnostic.',
                trim((string)$xpath->evaluate('string(err:Location)', $error)) ?: '/',
                trim((string)$xpath->evaluate('string(err:Type)', $error)) ?: 'business'
            );
        }
        return array_merge($libraryDiagnostics, $diagnostics);
    }

    /** @return list<array<string,mixed>> */
    private function libxmlDiagnostics(string $stage): array
    {
        $diagnostics = [];
        foreach (libxml_get_errors() as $error) {
            $diagnostics[] = $this->diagnostic(
                $stage,
                'libxml_' . (int)$error->code,
                trim((string)$error->message),
                'line ' . (int)$error->line . ', column ' . (int)$error->column,
                match ((int)$error->level) {
                    LIBXML_ERR_WARNING => 'warning',
                    LIBXML_ERR_FATAL => 'fatal',
                    default => 'error',
                }
            );
        }
        return $diagnostics;
    }

    /** @return array<string,mixed> */
    private function diagnostic(
        string $stage,
        string $code,
        string $message,
        string $location,
        string $type = 'error'
    ): array {
        return [
            'stage' => $stage,
            'code' => $code,
            'type' => $type,
            'message' => $message,
            'location' => $location,
        ];
    }

    /** @return array<string,mixed> */
    private function validationResult(string $stage, string $xml, array $artifacts, array $diagnostics): array
    {
        $artifactHashes = [];
        foreach ((array)$artifacts['artifacts'] as $role => $artifact) {
            $artifactHashes[(string)$role] = (string)$artifact['sha256'];
        }
        ksort($artifactHashes, SORT_STRING);
        $basis = [
            'validator_version' => self::VALIDATOR_VERSION,
            'stage' => $stage,
            'document_sha256' => hash('sha256', $xml),
            'package_id' => (int)$artifacts['package_id'],
            'package_sha256' => (string)$artifacts['package_sha256'],
            'artifact_hashes' => $artifactHashes,
        ];
        return [
            'ok' => $diagnostics === [],
            'status' => $diagnostics === [] ? 'passed' : 'failed',
            'stage' => $stage,
            'validator_version' => self::VALIDATOR_VERSION,
            'document_sha256' => $basis['document_sha256'],
            'validation_basis' => $basis,
            'validation_sha256' => hash('sha256', json_encode($basis, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'diagnostics' => array_values($diagnostics),
            'errors' => array_values(array_map(
                static fn(array $item): string => (string)$item['message'],
                $diagnostics
            )),
            'warnings' => [],
            'artifacts' => $artifacts,
        ];
    }

    /** @return array<string,mixed> */
    private function failure(string $message, array $details = []): array
    {
        return [
            'ok' => false,
            'status' => 'failed',
            'diagnostics' => [],
            'warnings' => [],
            'errors' => array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge([$message], $details)
            ), static fn(string $item): bool => trim($item) !== ''))),
        ];
    }
}
