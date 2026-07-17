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
 * Pinned, fail-closed view of HMRC's accepted iXBRL taxonomy table.
 *
 * This service deliberately does not fetch the HMRC website during a filing
 * request. The catalog dates make the provenance visible and allow LIVE
 * enablement to require a deliberate catalog review.
 */
final class Ct600TaxonomyAcceptanceService
{
    public const CATALOG_CHECKED_AT = '2026-07-17';
    public const SOURCE_UPDATED_AT = '2026-04-17';
    public const SOURCE_URL = 'https://www.gov.uk/government/publications/taxonomies-accepted-by-hm-revenue-and-customs/taxonomies-accepted-by-hmrc';
    public const EARLIEST_PERIOD_END = '2015-01-01';

    /** @var array<int, string|null> */
    private const ACCOUNTS_ACCEPTED_THROUGH = [
        2021 => '2024-03-31',
        2022 => '2025-03-31',
        2023 => '2026-03-31',
        2024 => '2027-03-31',
        2025 => null,
        2026 => null,
    ];

    /** @var array<int, string|null> */
    private const COMPUTATION_ACCEPTED_THROUGH = [
        2021 => '2024-03-31',
        2023 => '2025-03-31',
        2024 => '2026-03-31',
        2025 => null,
    ];

    /**
     * Assess one document from persisted artifact metadata.
     *
     * At least one of taxonomy_profile, schema_ref or
     * base_taxonomy_version_date must identify the release. When more than one
     * is supplied they must all identify the same release.
     *
     * @param array<string, mixed> $metadata
     * @return array{
     *   accepted: bool,
     *   document_type: string,
     *   family: string,
     *   release: int|null,
     *   period_end: string,
     *   accepted_from: string,
     *   accepted_through: string|null,
     *   catalog_checked_at: string,
     *   source_updated_at: string,
     *   source_url: string,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    public function assessDocument(string $documentType, string $periodEnd, array $metadata): array
    {
        $type = $this->normaliseDocumentType($documentType);
        $family = $type === Ct600IxbrlArtifact::ACCOUNTS ? 'FRC' : 'HMRC computations';
        $errors = [];
        $warnings = [];

        if (!$this->isDate($periodEnd)) {
            $errors[] = ucfirst($type) . ' iXBRL period end must use a real YYYY-MM-DD date.';
        }

        $profile = trim((string)($metadata['taxonomy_profile'] ?? $metadata['taxonomyProfile'] ?? ''));
        $schemaRef = trim((string)($metadata['schema_ref'] ?? $metadata['schemaRef'] ?? ''));
        $versionDate = trim((string)(
            $metadata['base_taxonomy_version_date']
            ?? $metadata['baseTaxonomyVersionDate']
            ?? ''
        ));

        $versions = [];
        if ($profile !== '') {
            $profileVersion = $this->versionFromProfile($type, $profile);
            if ($profileVersion === null) {
                $errors[] = ucfirst($type) . ' iXBRL taxonomy profile "' . $profile
                    . '" does not identify a supported ' . $family . ' release.';
            } else {
                $versions['taxonomy profile'] = $profileVersion;
            }
        }
        if ($schemaRef !== '') {
            $schemaVersion = $this->versionFromSchemaRef($type, $schemaRef);
            if ($schemaVersion === null) {
                $errors[] = ucfirst($type) . ' iXBRL schemaRef does not identify a supported '
                    . $family . ' release.';
            } else {
                $versions['schemaRef'] = $schemaVersion;
            }
        }
        if ($versionDate !== '') {
            if (!$this->isDate($versionDate)) {
                $errors[] = ucfirst($type) . ' iXBRL base taxonomy version date must use a real YYYY-MM-DD date.';
            } else {
                $versions['base taxonomy version date'] = (int)substr($versionDate, 0, 4);
            }
        }

        if ($profile === '' && $schemaRef === '' && $versionDate === '') {
            $errors[] = ucfirst($type) . ' iXBRL taxonomy metadata is missing; provide a taxonomy profile, schemaRef, or base taxonomy version date.';
        }

        $release = null;
        $distinctVersions = array_values(array_unique(array_values($versions)));
        if (count($distinctVersions) > 1) {
            $descriptions = [];
            foreach ($versions as $source => $version) {
                $descriptions[] = $source . ' identifies ' . $family . ' ' . $version;
            }
            $errors[] = ucfirst($type) . ' iXBRL taxonomy metadata conflicts: '
                . implode(', ', $descriptions) . '.';
        } elseif ($distinctVersions !== []) {
            $release = $distinctVersions[0];
        }

        $catalog = $type === Ct600IxbrlArtifact::ACCOUNTS
            ? self::ACCOUNTS_ACCEPTED_THROUGH
            : self::COMPUTATION_ACCEPTED_THROUGH;
        $acceptedThrough = null;
        if ($release !== null && !array_key_exists($release, $catalog)) {
            $errors[] = ucfirst($type) . ' iXBRL taxonomy ' . $family . ' ' . $release
                . ' is not in the pinned HMRC acceptance catalog checked on '
                . self::CATALOG_CHECKED_AT . '.';
        } elseif ($release !== null) {
            $acceptedThrough = $catalog[$release];
            if ($this->isDate($periodEnd) && $periodEnd < self::EARLIEST_PERIOD_END) {
                $errors[] = ucfirst($type) . ' iXBRL taxonomy ' . $family . ' ' . $release
                    . ' is accepted only for period ends on or after ' . self::EARLIEST_PERIOD_END
                    . '; the document period ends ' . $periodEnd . '.';
            } elseif ($this->isDate($periodEnd)
                && $acceptedThrough !== null
                && $periodEnd > $acceptedThrough) {
                $errors[] = ucfirst($type) . ' iXBRL taxonomy ' . $family . ' ' . $release
                    . ' is accepted only for period ends from ' . self::EARLIEST_PERIOD_END
                    . ' through ' . $acceptedThrough . '; the document period ends '
                    . $periodEnd . '.';
            }
        }

        if ($errors === []) {
            $warnings[] = 'Taxonomy acceptance uses the HMRC catalog checked on '
                . self::CATALOG_CHECKED_AT . ' (source updated ' . self::SOURCE_UPDATED_AT
                . '); recheck the official table before enabling LIVE submission.';
        }

        return [
            'accepted' => $errors === [],
            'document_type' => $type,
            'family' => $family,
            'release' => $release,
            'period_end' => $periodEnd,
            'accepted_from' => self::EARLIEST_PERIOD_END,
            'accepted_through' => $acceptedThrough,
            'catalog_checked_at' => self::CATALOG_CHECKED_AT,
            'source_updated_at' => self::SOURCE_UPDATED_AT,
            'source_url' => self::SOURCE_URL,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Cross-document validation hook compatible with Ct600XmlBuilder::build().
     *
     * @return array{
     *   accepted: bool,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   accounts: array<string, mixed>,
     *   computation: array<string, mixed>,
     *   catalog_checked_at: string,
     *   source_updated_at: string,
     *   source_url: string
     * }
     */
    public function validate(
        Ct600ReturnData $return,
        Ct600IxbrlArtifact $accounts,
        Ct600IxbrlArtifact $computation,
    ): array {
        $accountsResult = $this->assessArtifact($accounts);
        $computationResult = $this->assessArtifact($computation);
        $errors = array_values(array_unique(array_merge(
            $accountsResult['errors'],
            $computationResult['errors']
        )));
        $warnings = array_values(array_unique(array_merge(
            $accountsResult['warnings'],
            $computationResult['warnings']
        )));

        return [
            'accepted' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'accounts' => $accountsResult,
            'computation' => $computationResult,
            'catalog_checked_at' => self::CATALOG_CHECKED_AT,
            'source_updated_at' => self::SOURCE_UPDATED_AT,
            'source_url' => self::SOURCE_URL,
        ];
    }

    /** @return array<string, mixed> */
    public function assessArtifact(Ct600IxbrlArtifact $artifact): array
    {
        return $this->assessDocument(
            $artifact->documentType,
            $artifact->periodEnd,
            [
                'taxonomy_profile' => $artifact->taxonomyProfile,
                'base_taxonomy_version_date' => $artifact->baseTaxonomyVersionDate,
            ]
        );
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        return [
            'checked_at' => self::CATALOG_CHECKED_AT,
            'source_updated_at' => self::SOURCE_UPDATED_AT,
            'source_url' => self::SOURCE_URL,
            'accepted_from' => self::EARLIEST_PERIOD_END,
            'accounts' => self::ACCOUNTS_ACCEPTED_THROUGH,
            'computation' => self::COMPUTATION_ACCEPTED_THROUGH,
        ];
    }

    private function normaliseDocumentType(string $documentType): string
    {
        $normalised = strtolower(trim($documentType));
        return match ($normalised) {
            'account', 'accounts' => Ct600IxbrlArtifact::ACCOUNTS,
            'computation', 'computations' => Ct600IxbrlArtifact::COMPUTATION,
            default => throw new \InvalidArgumentException('Taxonomy document type must be accounts or computation.'),
        };
    }

    private function versionFromProfile(string $type, string $profile): ?int
    {
        $pattern = $type === Ct600IxbrlArtifact::ACCOUNTS
            ? '/(?:^|[^a-z0-9])frc[-_. ]*(20\d{2})(?:[^0-9]|$)/i'
            : '/(?:^|[^a-z0-9])(?:ct[-_. ]*)?computations?[-_. ]*(20\d{2})(?:[^0-9]|$)/i';
        if (preg_match($pattern, $profile, $matches) !== 1) {
            return null;
        }

        return (int)$matches[1];
    }

    private function versionFromSchemaRef(string $type, string $schemaRef): ?int
    {
        if (filter_var($schemaRef, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $familyPattern = $type === Ct600IxbrlArtifact::ACCOUNTS
            ? '/(?:xbrl\.frc\.org\.uk|(?:^|[\/_-])frc(?:[\/_-]|$)|FRS[-_]?10[25])/i'
            : '/(?:hmrc|govtalk|computations?|ct[-_]?comp|[\/_-]CT[\/_-])/i';
        if (preg_match($familyPattern, $schemaRef) !== 1) {
            return null;
        }
        if (preg_match('/(?<!\d)(20\d{2})(?:-\d{2}-\d{2})?(?!\d)/', $schemaRef, $matches) !== 1) {
            return null;
        }

        return (int)$matches[1];
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
