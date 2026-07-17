<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Immutable, credential-free provenance manifest for a finalized CT600 body. */
final readonly class Ct600PackageManifest implements \JsonSerializable
{
    /** @param array<string, mixed> $accounts */
    /** @param array<string, mixed> $computation */
    public function __construct(
        public int $manifestVersion,
        public int $companyId,
        public int $accountingPeriodId,
        public int $ctPeriodId,
        public int $ctPeriodSequence,
        public string $registrationNumber,
        public string $periodStart,
        public string $periodEnd,
        public string $returnType,
        public string $rimVersion,
        public string $schemaVersion,
        public array $accounts,
        public array $computation,
        public string $ct600BodySha256,
        public int $ct600BodyBytes,
        public string $canonicalGovTalkBodySha256,
        public string $irMark,
        public string $irMarkDisplay,
        public string $createdAt,
    ) {
        if ($this->manifestVersion !== 1) {
            throw new \InvalidArgumentException('Unsupported CT600 package manifest version.');
        }
        foreach ([$this->companyId, $this->accountingPeriodId, $this->ctPeriodId, $this->ctPeriodSequence] as $id) {
            if ($id <= 0) {
                throw new \InvalidArgumentException('CT600 manifest IDs and sequence must be positive.');
            }
        }
        if ($this->returnType !== 'new') {
            throw new \InvalidArgumentException('Phase-one CT600 manifest must describe an original return.');
        }
        foreach ([$this->ct600BodySha256, $this->canonicalGovTalkBodySha256] as $hash) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new \InvalidArgumentException('CT600 manifest contains an invalid SHA-256 value.');
            }
        }
        if ($this->ct600BodyBytes <= 0) {
            throw new \InvalidArgumentException('CT600 manifest body byte count must be positive.');
        }
        $decodedIrMark = base64_decode($this->irMark, true);
        if (!is_string($decodedIrMark) || strlen($decodedIrMark) !== 20) {
            throw new \InvalidArgumentException('CT600 manifest contains an invalid SHA-1/Base64 IRmark.');
        }
        if (!preg_match('/^[A-Z2-7]{32}$/D', $this->irMarkDisplay)) {
            throw new \InvalidArgumentException('CT600 manifest contains an invalid Base32 IRmark display value.');
        }
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $this->createdAt);
        if (!$date || $date->format(\DateTimeInterface::ATOM) !== $this->createdAt) {
            throw new \InvalidArgumentException('CT600 manifest creation timestamp must use RFC 3339 format.');
        }
        $this->assertArtifact($this->accounts, Ct600IxbrlArtifact::ACCOUNTS);
        $this->assertArtifact($this->computation, Ct600IxbrlArtifact::COMPUTATION);
    }

    /**
     * @param array{ir_envelope_xml: string, irmark: string, irmark_display: string, canonical_body_sha256: string} $finalized
     */
    public static function fromFinalizedPackage(
        Ct600ReturnData $return,
        Ct600IxbrlArtifact $accounts,
        Ct600IxbrlArtifact $computation,
        array $finalized,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        $bodyXml = (string)($finalized['ir_envelope_xml'] ?? '');
        if ($bodyXml === '') {
            throw new \InvalidArgumentException('Finalized CT/5 IRenvelope XML is required for the package manifest.');
        }
        $irMark = (string)($finalized['irmark'] ?? '');
        self::assertBodyIrMark($bodyXml, $irMark);
        $createdAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new self(
            manifestVersion: 1,
            companyId: $return->companyId,
            accountingPeriodId: $return->accountingPeriodId,
            ctPeriodId: $return->ctPeriodId,
            ctPeriodSequence: $return->ctPeriodSequence,
            registrationNumber: $return->registrationNumber,
            periodStart: $return->periodStart,
            periodEnd: $return->periodEnd,
            returnType: 'new',
            rimVersion: Ct600XmlBuilder::RIM_VERSION,
            schemaVersion: $return->schemaVersion,
            accounts: self::artifactArray($accounts),
            computation: self::artifactArray($computation),
            ct600BodySha256: hash('sha256', $bodyXml),
            ct600BodyBytes: strlen($bodyXml),
            canonicalGovTalkBodySha256: (string)($finalized['canonical_body_sha256'] ?? ''),
            irMark: $irMark,
            irMarkDisplay: (string)($finalized['irmark_display'] ?? ''),
            createdAt: $createdAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'manifest_version' => $this->manifestVersion,
            'company_id' => $this->companyId,
            'accounting_period_id' => $this->accountingPeriodId,
            'ct_period_id' => $this->ctPeriodId,
            'ct_period_sequence' => $this->ctPeriodSequence,
            'registration_number' => $this->registrationNumber,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'return_type' => $this->returnType,
            'rim_version' => $this->rimVersion,
            'schema_version' => $this->schemaVersion,
            'accounts' => $this->accounts,
            'computation' => $this->computation,
            'ct600_body_sha256' => $this->ct600BodySha256,
            'ct600_body_bytes' => $this->ct600BodyBytes,
            'canonical_govtalk_body_sha256' => $this->canonicalGovTalkBodySha256,
            'irmark' => $this->irMark,
            'irmark_display' => $this->irMarkDisplay,
            'created_at' => $this->createdAt,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) . "\n";
    }

    public function sha256(): string
    {
        return hash('sha256', $this->toJson());
    }

    /** @return array<string, mixed> */
    private static function artifactArray(Ct600IxbrlArtifact $artifact): array
    {
        return [
            'type' => $artifact->documentType,
            'run_id' => $artifact->runId,
            'filename' => $artifact->filename,
            'sha256' => $artifact->outputSha256,
            'validated_sha256' => $artifact->validatedSha256,
            'period_start' => $artifact->periodStart,
            'period_end' => $artifact->periodEnd,
            'taxonomy_profile' => $artifact->taxonomyProfile,
            'base_taxonomy_version_date' => $artifact->baseTaxonomyVersionDate,
        ];
    }

    /** @param array<string, mixed> $artifact */
    private function assertArtifact(array $artifact, string $expectedType): void
    {
        if ((string)($artifact['type'] ?? '') !== $expectedType || (int)($artifact['run_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('CT600 manifest contains invalid ' . $expectedType . ' artifact metadata.');
        }
        foreach (['sha256', 'validated_sha256'] as $field) {
            if (!preg_match('/^[a-f0-9]{64}$/D', (string)($artifact[$field] ?? ''))) {
                throw new \InvalidArgumentException('CT600 manifest contains an invalid ' . $expectedType . ' fingerprint.');
            }
        }
    }

    private static function assertBodyIrMark(string $bodyXml, string $expected): void
    {
        $document = new \DOMDocument();
        if (!$document->loadXML($bodyXml, \LIBXML_NONET)) {
            throw new \InvalidArgumentException('Finalized CT/5 body is not well-formed XML.');
        }
        $nodes = (new \DOMXPath($document))->query(
            '//*[local-name()="IRmark" and namespace-uri()="' . Ct600XmlBuilder::CT_NAMESPACE . '"]'
        );
        if ($nodes === false || $nodes->length !== 1 || (string)$nodes->item(0)?->textContent !== $expected) {
            throw new \InvalidArgumentException('Finalized CT/5 body IRmark does not match the manifest IRmark.');
        }
    }
}
