<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Immutable hand-off contract for an iXBRL file already validated by Arelle. */
final readonly class Ct600IxbrlArtifact
{
    public const ACCOUNTS = 'accounts';
    public const COMPUTATION = 'computation';

    public function __construct(
        public string $documentType,
        public int $runId,
        public string $path,
        public string $filename,
        public string $outputSha256,
        public string $validatedSha256,
        public bool $externalValidationPassed,
        public string $periodStart,
        public string $periodEnd,
        public string $taxonomyProfile,
        public string $baseTaxonomyVersionDate,
        public ?string $registrationNumber = null,
        public ?string $utr = null,
    ) {
        if (!in_array($this->documentType, [self::ACCOUNTS, self::COMPUTATION], true)) {
            throw new \InvalidArgumentException('iXBRL document type must be accounts or computation.');
        }
        if ($this->runId <= 0) {
            throw new \InvalidArgumentException('iXBRL run ID must be a positive integer.');
        }
        if ($this->path === '' || $this->filename === '' || basename($this->filename) !== $this->filename) {
            throw new \InvalidArgumentException('iXBRL path and a basename-only filename are required.');
        }
        if (preg_match('/[£$#~|€\/\\:*"<>]/u', $this->filename)) {
            throw new \InvalidArgumentException('iXBRL filename contains a character excluded by the CT600 RIM.');
        }
        foreach ([$this->outputSha256, $this->validatedSha256] as $hash) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new \InvalidArgumentException('iXBRL fingerprints must be lowercase SHA-256 values.');
            }
        }
        foreach ([$this->periodStart, $this->periodEnd, $this->baseTaxonomyVersionDate] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('iXBRL dates must use YYYY-MM-DD.');
            }
        }
        if ($this->periodStart > $this->periodEnd) {
            throw new \InvalidArgumentException('iXBRL period start must not be after its end.');
        }
        if (trim($this->taxonomyProfile) === '') {
            throw new \InvalidArgumentException('iXBRL taxonomy profile is required.');
        }
        if ($this->registrationNumber !== null && !preg_match('/^[A-Z0-9]{2,8}$/D', $this->registrationNumber)) {
            throw new \InvalidArgumentException('iXBRL company registration number is invalid.');
        }
        if ($this->utr !== null && !preg_match('/^[0-9]{10}$/D', $this->utr)) {
            throw new \InvalidArgumentException('iXBRL Corporation Tax UTR is invalid.');
        }
    }

    /** @return list<string> */
    public function verificationErrors(): array
    {
        $errors = [];
        if (!$this->externalValidationPassed) {
            $errors[] = ucfirst($this->documentType) . ' iXBRL has not passed external Arelle validation.';
        }
        if (!hash_equals($this->outputSha256, $this->validatedSha256)) {
            $errors[] = ucfirst($this->documentType) . ' iXBRL generation and validated fingerprints differ.';
        }
        if (!is_file($this->path)) {
            $errors[] = ucfirst($this->documentType) . ' iXBRL file was not found.';
            return $errors;
        }
        $actual = hash_file('sha256', $this->path);
        if (!is_string($actual) || !hash_equals($this->outputSha256, strtolower($actual))) {
            $errors[] = ucfirst($this->documentType) . ' iXBRL file has changed since validation.';
        }

        return $errors;
    }
}
