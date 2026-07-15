<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class VatSupportScopeService
{
    public const UNSUPPORTED_MESSAGE = 'Tax and Year End are read only because this VAT registration was confirmed through the LIVE HMRC VAT API. VAT-registered Tax and Year End processing is not currently supported.';
    public const SCOPE_EVALUATION_ERROR_MESSAGE = 'Tax and Year End are read only because the VAT support scope could not be verified safely.';

    public function fetchForCompany(int $companyId): array
    {
        if ($companyId <= 0) {
            return $this->evaluate([]);
        }

        return $this->evaluate(
            (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId) ?? []
        );
    }

    public function evaluate(array $settings): array
    {
        $isRegistered = !empty($settings['is_vat_registered']);
        $source = strtolower(trim((string)($settings['vat_validation_source'] ?? '')));
        $mode = strtoupper(trim((string)($settings['vat_validation_mode'] ?? '')));
        $status = strtolower(trim((string)($settings['vat_validation_status'] ?? '')));
        $liveHmrcConfirmed = $isRegistered
            && $source === 'hmrc'
            && $mode === 'LIVE'
            && in_array($status, ['valid', 'mismatch_override'], true);

        return [
            'tax_year_end_read_only' => $liveHmrcConfirmed,
            'supported' => !$liveHmrcConfirmed,
            'is_vat_registered' => $isRegistered,
            'validation_source' => $source,
            'validation_mode' => $mode,
            'validation_status' => $status,
            'message' => $liveHmrcConfirmed ? self::UNSUPPORTED_MESSAGE : '',
        ];
    }

    public function isTaxAndYearEndReadOnly(int $companyId): bool
    {
        return !empty($this->fetchForCompany($companyId)['tax_year_end_read_only']);
    }

    public function assertTaxAndYearEndSupported(int $companyId, string $actionLabel = 'perform this Tax or Year End action'): void
    {
        $block = $this->mutationBlockResult($companyId, $actionLabel);
        if ($block === null) {
            return;
        }

        throw new \RuntimeException((string)($block['errors'][0] ?? self::UNSUPPORTED_MESSAGE));
    }

    /** @return array<string, mixed>|null */
    public function mutationBlockResult(
        int $companyId,
        string $actionLabel = 'perform this Tax or Year End action'
    ): ?array {
        $scope = $this->fetchForCompany($companyId);
        if (empty($scope['tax_year_end_read_only'])) {
            return null;
        }

        return [
            'success' => false,
            'status' => 403,
            'errors' => [self::UNSUPPORTED_MESSAGE . ' You cannot ' . trim($actionLabel) . '.'],
            'vat_support_scope' => $scope,
        ];
    }
}
