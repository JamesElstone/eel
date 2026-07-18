<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class NonAssetReviewService
{
    public const CHECK_CODE = 'fixed_asset_review_placeholder';

    public function __construct(
        private readonly ?\eel_accounts\Service\AssetService $assetService = null,
        private readonly ?\eel_accounts\Service\AccountingPeriodAccessService $accessService = null,
        private readonly ?\eel_accounts\Service\YearEndAcknowledgementService $acknowledgementService = null,
    ) {
    }

    public function fetchContext(
        int $companyId,
        int $accountingPeriodId,
        int|string $toolsSmallEquipmentNominalId,
        int|string $threshold
    ): array {
        $normalisedThreshold = \eel_accounts\Service\AssetService::normalisePotentialAssetThreshold($threshold);
        $candidates = ($this->assetService ?? new \eel_accounts\Service\AssetService())->fetchNonAssetCandidates(
            $companyId,
            $accountingPeriodId,
            $toolsSmallEquipmentNominalId,
            $normalisedThreshold
        );
        $dataEntry = ($this->accessService ?? new \eel_accounts\Service\AccountingPeriodAccessService())
            ->fetchDataEntryState($companyId, $accountingPeriodId);
        $acknowledgements = $this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService();
        $storedAcknowledgement = $acknowledgements->fetch($companyId, $accountingPeriodId, self::CHECK_CODE);
        $evaluation = $acknowledgements->evaluate(
            $storedAcknowledgement,
            $this->buildAcknowledgementBasis(
                $candidates,
                $normalisedThreshold,
                $toolsSmallEquipmentNominalId
            )
        );
        $acknowledgement = $evaluation['acknowledgement'] ?? null;
        if (is_array($acknowledgement)) {
            $acknowledgement['state'] = (string)($evaluation['state'] ?? 'absent');
            $acknowledgement['current'] = !empty($evaluation['current']);
        }

        return [
            'candidates' => $candidates,
            'data_entry' => $dataEntry,
            'acknowledgement' => is_array($acknowledgement) ? $acknowledgement : null,
        ];
    }

    public function buildAcknowledgementBasis(
        array $candidateData,
        int|string $threshold,
        int|string $toolsSmallEquipmentNominalId
    ): array {
        $rows = array_values(array_filter(
            (array)($candidateData['rows'] ?? []),
            static fn(mixed $candidate): bool => is_array($candidate)
        ));

        return ($this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService())
            ->buildBasis(self::CHECK_CODE, [
                'candidate_count' => (int)($candidateData['count'] ?? count($rows)),
                'threshold' => number_format(
                    \eel_accounts\Service\AssetService::normalisePotentialAssetThreshold($threshold),
                    2,
                    '.',
                    ''
                ),
                'tools_nominal_id' => max(0, (int)$toolsSmallEquipmentNominalId),
                'candidates' => array_map(static fn(array $candidate): array => [
                    'source' => (string)($candidate['source'] ?? ''),
                    'source_id' => (int)($candidate['source_id'] ?? 0),
                    'date' => (string)($candidate['date'] ?? ''),
                    'amount' => number_format((float)($candidate['amount'] ?? 0), 2, '.', ''),
                    'nominal_account_id' => (int)($candidate['nominal_account_id'] ?? 0),
                ], $rows),
            ]);
    }
}
