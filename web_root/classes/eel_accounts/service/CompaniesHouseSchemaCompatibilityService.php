<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseSchemaCompatibilityService
{
    public const PROFILE = 'libxml-v1';

    /** @var array<string,array{sha256:string,transform:string,count:int}> */
    private const DEFAULT_TRANSFORMS = [
        'v1-0/schema/baseTypes-v3-4.xsd' => [
            'sha256' => 'c6c987305e9b3d70522b7ad127b18b778f6d8cc90c03ac7c8b766740ff95c79a',
            'transform' => 'large_decimal',
            'count' => 3,
        ],
        'v1-0/schema/baseTypes-v3-7.xsd' => [
            'sha256' => '0161f0795d80c71153779489c8d8d895d258f3f888a98d672c55a09adc150d3a',
            'transform' => 'large_decimal',
            'count' => 3,
        ],
        'v1-0/schema/forms/GetDocument-v1-1.xsd' => [
            'sha256' => 'f1bde9f608359bef0c8a528736f05c1d5208ac9f5542773a9d284e0781590540',
            'transform' => 'entity_enumeration',
            'count' => 2,
        ],
    ];

    /** @var array<string,array{sha256:string,transform:string,count:int}> */
    private array $transforms;
    private CompaniesHouseXmlSchemaValidationService $schemaValidator;

    /** @param array<string,array{sha256:string,transform:string,count:int}>|null $transforms */
    public function __construct(
        ?array $transforms = null,
        ?CompaniesHouseXmlSchemaValidationService $schemaValidator = null
    )
    {
        $this->transforms = $transforms ?? self::DEFAULT_TRANSFORMS;
        $this->schemaValidator = $schemaValidator
            ?? new CompaniesHouseXmlSchemaValidationService();
    }

    /**
     * @param array<string,array<string,mixed>> $files
     * @param array<string,string> $roots
     * @return array<string,array<string,mixed>>
     */
    public function prepareAndCompile(
        string $officialRoot,
        string $validationRoot,
        array $files,
        array $roots
    ): array {
        $officialRoot = rtrim($officialRoot, '/\\');
        $validationRoot = rtrim($validationRoot, '/\\');
        $this->ensureDirectory($validationRoot);
        $verifiedAt = gmdate('Y-m-d H:i:s');

        foreach ($files as $url => $file) {
            $relativePath = $this->relativePath((string)($file['relative_path'] ?? ''));
            $source = $officialRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $body = is_file($source) ? file_get_contents($source) : false;
            if (!is_string($body)) {
                throw new \RuntimeException(
                    'A staged Companies House schema is missing from the compatibility build: '
                    . $relativePath . '.'
                );
            }
            $officialHash = strtolower((string)($file['sha256'] ?? ''));
            if (preg_match('/^[a-f0-9]{64}$/D', $officialHash) !== 1
                || !hash_equals($officialHash, hash('sha256', $body))) {
                throw new \RuntimeException(
                    'A staged Companies House schema changed before compatibility validation.'
                );
            }
            $compatibleBody = $this->compatibleBody($relativePath, $officialHash, $body);
            $target = $validationRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $this->ensureDirectory(dirname($target));
            if (file_put_contents($target, $compatibleBody, LOCK_EX) !== strlen($compatibleBody)) {
                throw new \RuntimeException(
                    'A Companies House compatibility schema could not be written: '
                    . $relativePath . '.'
                );
            }
            $files[$url]['validation_profile'] = self::PROFILE;
            $files[$url]['validation_relative_path'] = $relativePath;
            $files[$url]['validation_sha256'] = hash('sha256', $compatibleBody);
            $files[$url]['validation_verified_at'] = $verifiedAt;
        }

        $byUrl = [];
        foreach ($files as $url => $file) {
            $byUrl[$this->canonicalUrl($url)] = $file;
        }
        foreach ($roots as $operation => $url) {
            $file = $byUrl[$this->canonicalUrl($url)] ?? null;
            if (!is_array($file)) {
                throw new \RuntimeException(
                    'The compatibility build is missing the pinned ' . $operation . ' schema.'
                );
            }
            [$rootName, $namespace] = $this->operationDocument($operation);
            $schema = $validationRoot . DIRECTORY_SEPARATOR . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                (string)$file['validation_relative_path']
            );
            $this->schemaValidator->assertCompiles(
                $schema,
                $rootName,
                $namespace,
                $operation
            );
        }

        return $files;
    }

    private function compatibleBody(string $relativePath, string $sha256, string $body): string
    {
        $profile = $this->transforms[$relativePath] ?? null;
        if (!is_array($profile)) {
            return $body;
        }
        if (!hash_equals(strtolower($profile['sha256']), $sha256)) {
            if ($this->containsKnownIncompatibility($profile['transform'], $body)) {
                throw new \RuntimeException(
                    'The Companies House schema ' . $relativePath
                    . ' requires a reviewed compatibility-profile update.'
                );
            }
            return $body;
        }

        return match ($profile['transform']) {
            'large_decimal' => $this->removeLargeDecimalFacets(
                $relativePath,
                $body,
                $profile['count']
            ),
            'entity_enumeration' => $this->normaliseEntityEnumerations(
                $relativePath,
                $body,
                $profile['count']
            ),
            default => throw new \RuntimeException(
                'The Companies House schema compatibility profile is invalid.'
            ),
        };
    }

    private function containsKnownIncompatibility(string $transform, string $body): bool
    {
        return match ($transform) {
            'large_decimal' => str_contains(
                $body,
                'maxInclusive value="99999999999999999999.999999"'
            ),
            'entity_enumeration' => str_contains($body, 'base="xs:ENTITY"'),
            default => true,
        };
    }

    private function removeLargeDecimalFacets(
        string $relativePath,
        string $body,
        int $expectedCount
    ): string {
        $document = $this->loadSchema($body, $relativePath);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $nodes = [];
        foreach ($xpath->query(
            '//xs:maxInclusive[@value="99999999999999999999.999999"]'
        ) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $nodes[] = $node;
            }
        }
        if (count($nodes) !== $expectedCount) {
            throw new \RuntimeException(
                'The reviewed Companies House decimal compatibility profile no longer matches '
                . $relativePath . '.'
            );
        }
        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }
        $result = $document->saveXML();
        if (!is_string($result)) {
            throw new \RuntimeException('The Companies House compatibility schema could not be serialised.');
        }
        return $result;
    }

    private function normaliseEntityEnumerations(
        string $relativePath,
        string $body,
        int $expectedCount
    ): string {
        $document = $this->loadSchema($body, $relativePath);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $nodes = [];
        foreach ($xpath->query('//xs:restriction[@base="xs:ENTITY"]') ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $nodes[] = $node;
            }
        }
        if (count($nodes) !== $expectedCount) {
            throw new \RuntimeException(
                'The reviewed Companies House ENTITY compatibility profile no longer matches '
                . $relativePath . '.'
            );
        }
        foreach ($nodes as $node) {
            $node->setAttribute('base', 'xs:string');
        }
        $result = $document->saveXML();
        if (!is_string($result)) {
            throw new \RuntimeException('The Companies House compatibility schema could not be serialised.');
        }
        return $result;
    }

    private function loadSchema(string $body, string $relativePath): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $document->loadXML($body, LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$ok) {
            throw new \RuntimeException(
                'The Companies House compatibility source is invalid XML: '
                . $relativePath . '.'
            );
        }
        return $document;
    }

    /** @return array{0:string,1:string} */
    private function operationDocument(string $operation): array
    {
        return match ($operation) {
            'envelope' => ['GovTalkMessage', 'http://www.govtalk.gov.uk/CM/envelope'],
            'form_submission' => ['FormSubmission', 'http://xmlgw.companieshouse.gov.uk/Header'],
            'submission_status' => ['GetSubmissionStatus', 'http://xmlgw.companieshouse.gov.uk'],
            'status_ack' => ['StatusAck', 'http://xmlgw.companieshouse.gov.uk'],
            'company_data' => ['CompanyDataRequest', 'http://xmlgw.companieshouse.gov.uk'],
            'get_document' => ['GetDocument', 'http://xmlgw.companieshouse.gov.uk'],
            default => throw new \RuntimeException(
                'The Companies House schema operation has no compilation probe.'
            ),
        };
    }

    private function canonicalUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $path = (string)($parts['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('The Companies House schema source URL is invalid.');
        }
        return 'https://xmlgw.companieshouse.gov.uk' . $path;
    }

    private function relativePath(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($relativePath === ''
            || str_contains($relativePath, '../')
            || str_contains($relativePath, "\0")) {
            throw new \RuntimeException('The Companies House schema relative path is unsafe.');
        }
        return $relativePath;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
            throw new \RuntimeException(
                'The Companies House schema validation directory could not be created.'
            );
        }
    }
}
