<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds the immutable narrative input for a Companies House Directors' Report. */
final class IxbrlDirectorsReportContentService
{
    /** @return array<string,mixed> */
    public function fetch(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->unavailable('Select a company and accounting period.');
        }
        if (!\InterfaceDB::tableExists('year_end_reviews')) {
            return $this->unavailable('Year End review notes are unavailable.');
        }

        $review = \InterfaceDB::fetchOne(
            'SELECT id, review_notes, is_locked, locked_at
             FROM year_end_reviews
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($review)) {
            return $this->unavailable('The Year End review could not be found.');
        }

        $reviewNotes = trim((string)($review['review_notes'] ?? ''));
        $acknowledgements = [];
        if (\InterfaceDB::tableExists('year_end_review_acknowledgements')) {
            foreach (\InterfaceDB::fetchAll(
                'SELECT check_code, acknowledged_at, acknowledged_by, note,
                        basis_version, basis_hash
                 FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND note IS NOT NULL
                   AND TRIM(note) <> \'\'
                 ORDER BY acknowledged_at ASC, check_code ASC',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            ) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $note = trim((string)($row['note'] ?? ''));
                if ($note === '') {
                    continue;
                }
                $acknowledgements[] = [
                    'check_code' => trim((string)($row['check_code'] ?? '')),
                    'acknowledged_at' => (string)($row['acknowledged_at'] ?? ''),
                    'acknowledged_by' => (string)($row['acknowledged_by'] ?? ''),
                    'basis_version' => (string)($row['basis_version'] ?? ''),
                    'basis_hash' => strtolower(trim((string)($row['basis_hash'] ?? ''))),
                    'note' => $note,
                    'sentences' => $this->sentences($note),
                ];
            }
        }

        $sentences = [];
        foreach ($acknowledgements as $acknowledgement) {
            foreach ((array)$acknowledgement['sentences'] as $sentence) {
                $sentence = trim((string)$sentence);
                if ($sentence !== '') {
                    $sentences[] = $sentence;
                }
            }
        }

        return [
            'available' => true,
            'errors' => [],
            'year_end_review_id' => (int)($review['id'] ?? 0),
            'year_end_locked' => !empty($review['is_locked']),
            'year_end_locked_at' => (string)($review['locked_at'] ?? ''),
            'review_notes' => $reviewNotes,
            'review_notes_hash' => hash('sha256', $reviewNotes),
            'review_notes_blank' => $reviewNotes === '',
            'confirmation_sentences' => $sentences,
            'source_acknowledgements' => $acknowledgements,
            'content_hash' => hash('sha256', $this->canonicalJson([
                'review_notes' => $reviewNotes,
                'source_acknowledgements' => $acknowledgements,
            ])),
        ];
    }

    /** @return list<string> */
    private function sentences(string $note): array
    {
        $parts = preg_split('/(?<=[.!?])\s+|\R+/u', trim($note));
        if (!is_array($parts)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $part): string => trim((string)preg_replace('/\s+/u', ' ', $part)),
            $parts
        ), static fn(string $part): bool => $part !== ''));
    }

    /** @return array<string,mixed> */
    private function unavailable(string $message): array
    {
        return [
            'available' => false,
            'errors' => [$message],
            'review_notes' => '',
            'review_notes_hash' => hash('sha256', ''),
            'review_notes_blank' => true,
            'confirmation_sentences' => [],
            'source_acknowledgements' => [],
            'content_hash' => '',
        ];
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalise($nested);
            }
            return $item;
        };
        return \eel_accounts\Support\Utf8::json(
            $normalise($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
