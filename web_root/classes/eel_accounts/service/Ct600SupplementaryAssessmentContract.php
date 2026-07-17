<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Canonical contract for the phase-one CT600 supplementary-scope matrix. */
final class Ct600SupplementaryAssessmentContract
{
    public const VERSION = 'ct600-supplement-assessment-v1';
    public const REQUIRED = 'required';
    public const NOT_REQUIRED = 'not_required';
    public const UNKNOWN = 'unknown';

    /** @return list<array{contract_key:string,page:?string,label:string}> */
    public static function definitions(): array
    {
        return [
            ['contract_key' => 'ct600a', 'page' => 'CT600A', 'label' => 'Loans to participators'],
            ['contract_key' => 'ct600b', 'page' => 'CT600B', 'label' => 'Controlled foreign companies'],
            ['contract_key' => 'ct600c', 'page' => 'CT600C', 'label' => 'Group and consortium relief'],
            ['contract_key' => 'ct600d', 'page' => 'CT600D', 'label' => 'Insurance'],
            ['contract_key' => 'ct600e', 'page' => 'CT600E', 'label' => 'Charities and community amateur sports clubs'],
            ['contract_key' => 'ct600f', 'page' => 'CT600F', 'label' => 'Tonnage tax'],
            ['contract_key' => 'ct600g', 'page' => 'CT600G', 'label' => 'Northern Ireland'],
            ['contract_key' => 'ct600h', 'page' => 'CT600H', 'label' => 'Cross-border royalties'],
            ['contract_key' => 'ct600i', 'page' => 'CT600I', 'label' => 'Ring-fence supplementary charge'],
            ['contract_key' => 'ct600j', 'page' => 'CT600J', 'label' => 'Disclosure of tax-avoidance schemes'],
            ['contract_key' => 'ct600k', 'page' => 'CT600K', 'label' => 'Restitution tax'],
            ['contract_key' => 'ct600l', 'page' => 'CT600L', 'label' => 'Research and development'],
            ['contract_key' => 'ct600m', 'page' => 'CT600M', 'label' => 'Freeports and investment zones'],
            ['contract_key' => 'ct600n', 'page' => 'CT600N', 'label' => 'Residential property developer tax'],
            ['contract_key' => 'ct600p', 'page' => 'CT600P', 'label' => 'Energy profits levy'],
            ['contract_key' => 'unsupported_claims', 'page' => null, 'label' => 'Unsupported claims'],
            ['contract_key' => 'unsupported_elections', 'page' => null, 'label' => 'Unsupported elections'],
            ['contract_key' => 'repayment_request', 'page' => null, 'label' => 'Repayment request'],
            ['contract_key' => 'additional_attachments', 'page' => null, 'label' => 'Additional attachments'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function unknownRows(): array
    {
        $rows = [];
        foreach (self::definitions() as $index => $definition) {
            $rows[] = $definition + [
                'row_order' => $index + 1,
                'status' => self::UNKNOWN,
                'evidence_source' => '',
                'evidence_ref' => '',
                'detail' => '',
            ];
        }

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function normaliseRows(array $rows, bool $requireEveryRow = true): array
    {
        $provided = [];
        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Every supplementary assessment row must be an array.');
            }
            $contractKey = strtolower(trim((string)($row['contract_key'] ?? (is_string($key) ? $key : ''))));
            if ($contractKey === '') {
                throw new \InvalidArgumentException('Every supplementary assessment row needs a contract key.');
            }
            if (isset($provided[$contractKey])) {
                throw new \InvalidArgumentException('Duplicate supplementary assessment row: ' . $contractKey . '.');
            }
            $provided[$contractKey] = $row;
        }

        $normalised = [];
        $known = [];
        foreach (self::definitions() as $index => $definition) {
            $contractKey = $definition['contract_key'];
            $known[$contractKey] = true;
            if (!isset($provided[$contractKey])) {
                if ($requireEveryRow) {
                    throw new \InvalidArgumentException('Missing supplementary assessment row: ' . $contractKey . '.');
                }
                $row = [];
            } else {
                $row = $provided[$contractKey];
            }

            if (array_key_exists('row_order', $row) && (int)$row['row_order'] !== $index + 1) {
                throw new \InvalidArgumentException('Invalid row order for supplementary assessment row ' . $contractKey . '.');
            }
            if (array_key_exists('page', $row)) {
                $providedPage = $row['page'] === null || trim((string)$row['page']) === ''
                    ? null
                    : strtoupper(trim((string)$row['page']));
                if ($providedPage !== $definition['page']) {
                    throw new \InvalidArgumentException('Invalid page for supplementary assessment row ' . $contractKey . '.');
                }
            }
            if (array_key_exists('label', $row) && trim((string)$row['label']) !== $definition['label']) {
                throw new \InvalidArgumentException('Invalid label for supplementary assessment row ' . $contractKey . '.');
            }

            $status = strtolower(trim((string)($row['status'] ?? self::UNKNOWN)));
            if (!in_array($status, [self::REQUIRED, self::NOT_REQUIRED, self::UNKNOWN], true)) {
                throw new \InvalidArgumentException('Invalid status for supplementary assessment row ' . $contractKey . '.');
            }
            $evidenceSource = self::boundedText((string)($row['evidence_source'] ?? ''), 100, 'Evidence source');
            $evidenceRef = self::boundedText((string)($row['evidence_ref'] ?? ''), 1000, 'Evidence reference');
            $detail = self::boundedText((string)($row['detail'] ?? ''), 8000, 'Assessment detail');
            if ($status !== self::UNKNOWN && ($evidenceSource === '' || $detail === '')) {
                throw new \InvalidArgumentException(
                    $definition['label'] . ' needs an evidence source and detail when assessed.'
                );
            }

            $normalised[] = [
                'row_order' => $index + 1,
                'contract_key' => $contractKey,
                'page' => $definition['page'],
                'label' => $definition['label'],
                'status' => $status,
                'evidence_source' => $evidenceSource,
                'evidence_ref' => $evidenceRef,
                'detail' => $detail,
            ];
        }

        $unknownKeys = array_values(array_diff(array_keys($provided), array_keys($known)));
        if ($unknownKeys !== []) {
            throw new \InvalidArgumentException(
                'Unknown supplementary assessment row: ' . implode(', ', $unknownKeys) . '.'
            );
        }

        return $normalised;
    }

    /**
     * @param array<string, mixed> $binding
     * @param list<array<string, mixed>> $rows
     */
    public static function hash(array $binding, array $rows, string $approvedBy, string $approvedAt): string
    {
        $payload = [
            'version' => self::VERSION,
            'binding' => [
                'company_id' => (int)($binding['company_id'] ?? 0),
                'accounting_period_id' => (int)($binding['accounting_period_id'] ?? 0),
                'ct_period_id' => (int)($binding['ct_period_id'] ?? 0),
                'computation_run_id' => (int)($binding['computation_run_id'] ?? 0),
                'year_end_locked_at' => trim((string)($binding['year_end_locked_at'] ?? '')),
            ],
            'approved_by' => trim($approvedBy),
            'approved_at' => trim($approvedAt),
            'rows' => array_map(
                static fn(array $row): array => [
                    'row_order' => (int)$row['row_order'],
                    'contract_key' => (string)$row['contract_key'],
                    'page' => $row['page'] === null ? null : (string)$row['page'],
                    'label' => (string)$row['label'],
                    'status' => (string)$row['status'],
                    'evidence_source' => (string)$row['evidence_source'],
                    'evidence_ref' => (string)$row['evidence_ref'],
                    'detail' => (string)$row['detail'],
                ],
                self::normaliseRows($rows)
            ),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }

    private static function boundedText(string $value, int $maximum, string $label): string
    {
        $value = trim($value);
        if (strlen($value) > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException($label . ' is invalid.');
        }

        return $value;
    }
}
