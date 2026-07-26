<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Applies the published collector date windows to the configured FRC accounts
 * taxonomy and verifies the identity of an installed taxonomy package.
 *
 * Passing this check establishes compatibility with published policy. It does
 * not represent a Companies House Gateway validation or acceptance response.
 */
final class IxbrlTaxonomyCompatibilityService
{
    public const POLICY_VERSION = 'frc-accounts-taxonomy-compatibility-v1';
    /**
     * SHA-256 of the official FRC 2026 Taxonomy Suite v1.0.0 ZIP downloaded
     * from the published FRC suite page. Package metadata is self-asserted, so
     * manifest checks alone are not an integrity boundary.
     */
    public const TRUSTED_PACKAGE_SHA256 = 'ae80ae12d9d747ac531150b0051bcd67e7c9acf44313da19105b4cc013566462';
    public const FRC_SUITE_URL = 'https://www.frc.org.uk/library/standards-codes-policy/accounting-and-reporting/frc-taxonomies/current-frc-taxonomy-suites/2026-frc-taxonomy-suite/';
    public const FRC_DESIGN_URL = 'https://media.frc.org.uk/documents/Accounts_Taxonomies_Design_2026.pdf';
    public const HMRC_ACCEPTANCE_URL = 'https://www.gov.uk/government/publications/taxonomies-accepted-by-hm-revenue-and-customs/taxonomies-accepted-by-hmrc';
    public const COMPANIES_HOUSE_GATEWAY_NOTICE_URL = 'https://xmlforum.companieshouse.gov.uk/t/2026-frc-taxonomies-update/1903';
    public const COMPANIES_HOUSE_VALIDATOR_URL = 'https://ewf.companieshouse.gov.uk/help/en/stdwf/xbrl_validator.html';

    private const DEFAULT_POLICY = [
        'taxonomy_version' => '2026',
        'package_identifier' => 'https://xbrl.frc.org.uk/fr/2026-01-01/v1-0-0',
        'package_version' => '1.0.0',
        'entry_point_name' => 'FRS-102',
        'schema_ref' => 'https://xbrl.frc.org.uk/FRS-102/2026-01-01/FRS-102-2026-01-01.xsd',
        'accounting_standard' => 'FRS_105',
        'reporting_period_start_from' => '2015-04-01',
        'reporting_period_end_to' => null,
        'companies_house_gateway_available_from' => '2026-04-01',
        'companies_house_gateway_available_to' => null,
    ];

    /** @var array<string, mixed> */
    private array $overrides;
    private string $trustedPackageSha256;

    /**
     * The optional trust anchor is dependency-injection support for isolated
     * package-parser tests. Production callers omit it and use the pinned FRC
     * digest above; application configuration cannot replace that digest.
     *
     * @param null|array<string, mixed> $overrides
     */
    public function __construct(
        ?array $overrides = null,
        ?string $trustedPackageSha256 = null
    )
    {
        $configured = $overrides;
        if ($configured === null) {
            $value = \AppConfigurationStore::get('ixbrl.accounts_taxonomy_compatibility', []);
            $configured = is_array($value) ? $value : [];
        }
        $this->overrides = $configured;
        $trusted = strtolower(trim(
            $trustedPackageSha256 ?? self::TRUSTED_PACKAGE_SHA256
        ));
        if (preg_match('/^[a-f0-9]{64}$/D', $trusted) !== 1) {
            throw new \InvalidArgumentException(
                'The trusted FRC taxonomy package SHA-256 is invalid.'
            );
        }
        $this->trustedPackageSha256 = $trusted;
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return array_replace(self::DEFAULT_POLICY, $this->overrides);
    }

    /**
     * @return array{
     *   compatible:bool,
     *   reporting_compatible:bool,
     *   gateway_date_compatible:?bool,
     *   gateway_response_confirmed:bool,
     *   errors:list<string>,
     *   warnings:list<string>,
     *   policy:array<string,mixed>,
     *   evidence:array<string,string>
     * }
     */
    public function assess(
        string $accountingStandard,
        string $periodStart,
        string $periodEnd,
        ?string $intendedFilingDate = null,
        string $schemaRef = IxbrlTaxonomyProfileService::SCHEMA_REF
    ): array {
        $policy = $this->policy();
        $reportingErrors = [];
        $gatewayErrors = [];
        $warnings = [];

        $configuredSchemaRef = trim((string)($policy['schema_ref'] ?? ''));
        if ($configuredSchemaRef === '') {
            $reportingErrors[] = 'The accounts-taxonomy compatibility policy has no schema reference.';
        } elseif (!hash_equals($configuredSchemaRef, trim($schemaRef))) {
            $reportingErrors[] = 'The generated accounts taxonomy entry point is not the configured collector-compatible entry point.';
        }

        $configuredStandard = strtoupper(trim((string)($policy['accounting_standard'] ?? '')));
        if ($configuredStandard === '') {
            $reportingErrors[] = 'The accounts-taxonomy compatibility policy has no accounting standard.';
        } elseif (!hash_equals($configuredStandard, strtoupper(trim($accountingStandard)))) {
            $reportingErrors[] = 'The FRC 2026 FRS-102 entry point is configured for the EEL FRS 105 accounts profile only.';
        }

        $start = $this->date($periodStart);
        $end = $this->date($periodEnd);
        if ($start === null) {
            $reportingErrors[] = 'The accounts reporting-period start date must use valid YYYY-MM-DD format.';
        }
        if ($end === null) {
            $reportingErrors[] = 'The accounts reporting-period end date must use valid YYYY-MM-DD format.';
        }
        if ($start !== null && $end !== null && $start > $end) {
            $reportingErrors[] = 'The accounts reporting-period start date must not be after its end date.';
        }

        $periodStartFrom = $this->policyDate(
            $policy['reporting_period_start_from'] ?? null,
            'reporting-period start boundary',
            $reportingErrors
        );
        $periodEndTo = $this->policyDate(
            $policy['reporting_period_end_to'] ?? null,
            'reporting-period end boundary',
            $reportingErrors,
            true
        );
        if ($start !== null && $periodStartFrom !== null && $start < $periodStartFrom) {
            $reportingErrors[] = 'The FRC 2026 accounts taxonomy is configured only for accounting periods starting on or after '
                . $periodStartFrom->format('Y-m-d') . '.';
        }
        if ($end !== null && $periodEndTo !== null && $end > $periodEndTo) {
            $reportingErrors[] = 'The FRC 2026 accounts taxonomy is configured only for accounting periods ending on or before '
                . $periodEndTo->format('Y-m-d') . '.';
        }

        $gatewayDateCompatible = null;
        $filingDateValue = $intendedFilingDate === null ? '' : trim($intendedFilingDate);
        if ($filingDateValue === '') {
            $warnings[] = 'No intended filing date was supplied, so Companies House Gateway availability was not date-checked.';
        } else {
            $filingDate = $this->date($filingDateValue);
            if ($filingDate === null) {
                $gatewayErrors[] = 'The intended Companies House filing date must use valid YYYY-MM-DD format.';
            } else {
                $gatewayFrom = $this->policyDate(
                    $policy['companies_house_gateway_available_from'] ?? null,
                    'Companies House Gateway availability start',
                    $gatewayErrors
                );
                $gatewayTo = $this->policyDate(
                    $policy['companies_house_gateway_available_to'] ?? null,
                    'Companies House Gateway availability end',
                    $gatewayErrors,
                    true
                );
                if ($gatewayFrom !== null && $filingDate < $gatewayFrom) {
                    $gatewayErrors[] = 'Companies House had not announced Gateway acceptance of the FRC 2026 taxonomy for the intended filing date; configured availability begins '
                        . $gatewayFrom->format('Y-m-d') . '.';
                }
                if ($gatewayTo !== null && $filingDate > $gatewayTo) {
                    $gatewayErrors[] = 'The intended filing date is after the configured Companies House acceptance window for the FRC 2026 taxonomy.';
                }
            }
            $gatewayDateCompatible = $gatewayErrors === [];
        }

        $errors = array_values(array_unique(array_merge($reportingErrors, $gatewayErrors)));
        return [
            'compatible' => $errors === [],
            'reporting_compatible' => $reportingErrors === [],
            'gateway_date_compatible' => $gatewayDateCompatible,
            'gateway_response_confirmed' => false,
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'policy' => array_replace($policy, ['policy_version' => self::POLICY_VERSION]),
            'evidence' => $this->evidence(),
        ];
    }

    /**
     * Verifies the taxonomy-package identity and exact FRS-102 entry point.
     *
     * @return array<string, mixed>
     */
    public function inspectPackage(string $path): array
    {
        $errors = [];
        $policy = $this->policy();
        $path = trim($path);
        if ($path === '' || !is_file($path)) {
            return [
                'compatible' => false,
                'errors' => ['The configured FRC taxonomy package archive does not exist.'],
                'path' => $path,
                'sha256' => null,
            ];
        }
        $manifestName = 'META-INF/taxonomyPackage.xml';
        $entryPointArchiveName = 'FRS-102/2026-01-01/FRS-102-2026-01-01.xsd';
        try {
            $files = (new HmrcCtRimZipService())->readEntries(
                $path,
                [$manifestName, $entryPointArchiveName]
            );
        } catch (\Throwable $exception) {
            return [
                'compatible' => false,
                'errors' => ['The configured FRC taxonomy package is not a readable ZIP archive: '
                    . $exception->getMessage()],
                'path' => $path,
                'sha256' => null,
            ];
        }

        $manifest = $files[$manifestName] ?? false;
        $entryPoint = $files[$entryPointArchiveName] ?? false;

        if (!is_string($manifest) || $manifest === '') {
            $errors[] = 'The FRC taxonomy package has no taxonomyPackage.xml manifest.';
        }
        if (!is_string($entryPoint) || $entryPoint === '') {
            $errors[] = 'The FRC taxonomy package does not contain the exact 2026 FRS-102 entry-point schema.';
        }

        $packageIdentifier = '';
        $packageVersion = '';
        $manifestSchemaRef = '';
        if (is_string($manifest) && $manifest !== '') {
            $document = $this->xml($manifest);
            if ($document === null) {
                $errors[] = 'The FRC taxonomy-package manifest is not well-formed XML.';
            } else {
                $xpath = new \DOMXPath($document);
                $xpath->registerNamespace('tp', 'http://xbrl.org/2016/taxonomy-package');
                $packageIdentifier = trim((string)$xpath->evaluate('string(/tp:taxonomyPackage/tp:identifier)'));
                $packageVersion = trim((string)$xpath->evaluate('string(/tp:taxonomyPackage/tp:version)'));
                $entryPointName = trim((string)($policy['entry_point_name'] ?? ''));
                $manifestSchemaRef = trim((string)$xpath->evaluate(
                    'string(/tp:taxonomyPackage/tp:entryPoints/tp:entryPoint[tp:name="'
                    . $this->xpathLiteralValue($entryPointName)
                    . '"]/tp:entryPointDocument/@href)'
                ));
            }
        }

        if ($packageIdentifier !== trim((string)($policy['package_identifier'] ?? ''))) {
            $errors[] = 'The installed taxonomy package identifier is not the configured FRC 2026 v1.0.0 identity.';
        }
        if ($packageVersion !== trim((string)($policy['package_version'] ?? ''))) {
            $errors[] = 'The installed taxonomy package version is not the configured FRC package version.';
        }
        if ($manifestSchemaRef !== trim((string)($policy['schema_ref'] ?? ''))) {
            $errors[] = 'The taxonomy-package manifest does not map the FRS-102 entry point to the configured schema reference.';
        }

        if (is_string($entryPoint) && $entryPoint !== '') {
            $schema = $this->xml($entryPoint);
            $expectedNamespace = 'http://xbrl.frc.org.uk/FRS-102/2026-01-01';
            if ($schema === null
                || trim((string)$schema->documentElement?->getAttribute('targetNamespace')) !== $expectedNamespace) {
                $errors[] = 'The bundled FRS-102 schema does not expose the expected 2026 target namespace.';
            }
        }

        $sha256 = @hash_file('sha256', $path);
        $normalisedSha256 = is_string($sha256) ? strtolower($sha256) : '';
        if ($normalisedSha256 === ''
            || !hash_equals($this->trustedPackageSha256, $normalisedSha256)) {
            $errors[] = 'The FRC taxonomy package SHA-256 does not match the trusted official 2026 v1.0.0 archive.';
        }
        return [
            'compatible' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'path' => $path,
            'sha256' => $normalisedSha256 !== '' ? $normalisedSha256 : null,
            'trusted_sha256' => $this->trustedPackageSha256,
            'package_identifier' => $packageIdentifier,
            'package_version' => $packageVersion,
            'entry_point_name' => (string)($policy['entry_point_name'] ?? ''),
            'schema_ref' => $manifestSchemaRef,
        ];
    }

    /** @return array<string, string> */
    public function evidence(): array
    {
        return [
            'frc_suite' => self::FRC_SUITE_URL,
            'frc_entry_point_design' => self::FRC_DESIGN_URL,
            'hmrc_acceptance_window' => self::HMRC_ACCEPTANCE_URL,
            'companies_house_gateway_notice' => self::COMPANIES_HOUSE_GATEWAY_NOTICE_URL,
            'companies_house_validator' => self::COMPANIES_HOUSE_VALIDATOR_URL,
        ];
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
            || ($dateErrors !== false
                && ((int)$dateErrors['warning_count'] > 0 || (int)$dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            return null;
        }
        return $date;
    }

    /**
     * @param list<string> $errors
     */
    private function policyDate(
        mixed $value,
        string $label,
        array &$errors,
        bool $nullable = false
    ): ?\DateTimeImmutable {
        $normalised = trim((string)($value ?? ''));
        if ($normalised === '' && $nullable) {
            return null;
        }
        $date = $this->date($normalised);
        if ($date === null) {
            $errors[] = 'The configured ' . $label . ' must use valid YYYY-MM-DD format.';
        }
        return $date;
    }

    private function xml(string $xml): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return null;
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * The entry-point name is configuration-controlled and restricted to the
     * simple XML name used by the official package manifest.
     */
    private function xpathLiteralValue(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $value) ?? '';
    }
}
