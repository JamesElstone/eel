<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class VatRegistrationAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', ''));

        return match ($intent) {
            'save_vat' => $this->saveVat($request),
            'validate_vat' => $this->validateVat($request),
            'clear_vat_validation' => $this->clearVatValidation($request),
            'accept_vat_mismatch' => $this->acceptVatMismatch($request),
            default => new ActionResultFramework(false, ['vat_registration'], [[
                'type' => 'error',
                'message' => 'Unknown VAT registration action.',
            ]]),
        };
    }

    private function saveVat(RequestFramework $request): ActionResultFramework
    {
        return $this->withSettings($request, function (array $settings, \eel_accounts\Service\VatRegistrationService $vatService) use ($request): ActionResultFramework {
            $previousSettings = $settings;
            $settings = $this->submittedVatSettings($request, $settings, $vatService);

            if (empty($settings['is_vat_registered'])) {
                $settings = $this->clearVatDetails($settings);
            } else {
                $settings = $vatService->applyManualSaveRules($settings, $previousSettings);
            }

            $this->saveSettings($settings);

            return ActionResultFramework::success(['vat_registration', 'vat_readiness', 'page.context'], [[
                'type' => 'success',
                'message' => 'VAT registration settings saved.',
            ]]);
        });
    }

    private function validateVat(RequestFramework $request): ActionResultFramework
    {
        return $this->withSettings($request, function (array $settings, \eel_accounts\Service\VatRegistrationService $vatService) use ($request): ActionResultFramework {
            $settings = $this->submittedVatSettings($request, $settings, $vatService);

            if (empty($settings['is_vat_registered'])) {
                return new ActionResultFramework(false, ['vat_registration'], [[
                    'type' => 'error',
                    'message' => 'Mark the company as VAT registered before checking its VAT number.',
                ]]);
            }

            if ((string)$settings['vat_country_code'] === '' || (string)$settings['vat_number'] === '') {
                return new ActionResultFramework(false, ['vat_registration'], [[
                    'type' => 'error',
                    'message' => 'Enter a VAT country/prefix and registration number before checking VAT details.',
                ]]);
            }

            $result = $vatService->validate($settings);
            $settings = $vatService->updateSettingsFromResult($settings, $result);
            $warnings = $vatService->compareHmrcAndCompaniesHouse($settings, $result);

            if ($result->status === 'valid' && $warnings !== []) {
                $settings['vat_validation_status'] = 'mismatch_pending';
            }

            $this->saveSettings($settings);

            return new ActionResultFramework(
                $result->status === 'valid',
                ['vat_registration', 'vat_readiness', 'page.context'],
                $this->validationMessages($result, $warnings)
            );
        });
    }

    private function clearVatValidation(RequestFramework $request): ActionResultFramework
    {
        return $this->withSettings($request, function (array $settings, \eel_accounts\Service\VatRegistrationService $vatService) use ($request): ActionResultFramework {
            $settings = $this->submittedVatSettings($request, $settings, $vatService);
            $settings = $vatService->resetValidationState($settings);
            $this->saveSettings($settings);

            return ActionResultFramework::success(['vat_registration', 'vat_readiness', 'page.context'], [[
                'type' => 'success',
                'message' => 'VAT validation state reset.',
            ]]);
        });
    }

    private function acceptVatMismatch(RequestFramework $request): ActionResultFramework
    {
        return $this->withSettings($request, function (array $settings, \eel_accounts\Service\VatRegistrationService $vatService) use ($request): ActionResultFramework {
            $settings = $this->submittedVatSettings($request, $settings, $vatService);

            if ((string)($settings['vat_validation_status'] ?? '') !== 'mismatch_pending') {
                return new ActionResultFramework(false, ['vat_registration'], [[
                    'type' => 'error',
                    'message' => 'There is no VAT validation mismatch waiting to be accepted.',
                ]]);
            }

            $settings['vat_validation_status'] = 'mismatch_override';
            $settings['vat_last_error'] = '';
            $this->saveSettings($settings);

            return ActionResultFramework::success(['vat_registration', 'vat_readiness', 'page.context'], [[
                'type' => 'success',
                'message' => 'VAT validation mismatch accepted.',
            ]]);
        });
    }

    private function withSettings(RequestFramework $request, callable $callback): ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();
        if ($companyId <= 0) {
            $companyId = max(0, (int)$request->input('company_id', 0));
        }

        if ($companyId <= 0) {
            return new ActionResultFramework(false, ['vat_registration'], [[
                'type' => 'error',
                'message' => 'Select a company before updating VAT registration.',
            ]]);
        }

        $repository = new \eel_accounts\Repository\CompanyRepository();
        $company = $repository->fetchCompanyDetails($companyId);
        if ($company === null) {
            return new ActionResultFramework(false, ['vat_registration'], [[
                'type' => 'error',
                'message' => 'The selected company could not be loaded.',
            ]]);
        }

        try {
            $settings = (new \eel_accounts\Service\CompanySettingsService())->loadFromDatabase(
                new \eel_accounts\Store\CompanySettingsStore($companyId),
                $companyId,
                0
            );

            return $callback($settings, \eel_accounts\Service\VatRegistrationFactoryService::createFromConfig());
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['vat_registration'], [[
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]]);
        }
    }

    private function submittedVatSettings(
        RequestFramework $request,
        array $settings,
        \eel_accounts\Service\VatRegistrationService $vatService
    ): array {
        $isVatRegistered = (string)$request->input('is_vat_registered', '0') === '1';

        $settings['is_vat_registered'] = $isVatRegistered;
        $settings['vat_country_code'] = $isVatRegistered
            ? strtoupper(trim((string)$request->input('vat_country_code', '')))
            : '';
        $settings['vat_number'] = $isVatRegistered
            ? $vatService->normaliseVatNumber((string)$request->input('vat_number', ''))
            : '';

        return $settings;
    }

    private function clearVatDetails(array $settings): array
    {
        foreach ([
            'vat_country_code',
            'vat_number',
            'vat_validation_status',
            'vat_validated_at',
            'vat_validation_source',
            'vat_validation_name',
            'vat_validation_address_line1',
            'vat_validation_postcode',
            'vat_validation_country_code',
            'vat_last_error',
        ] as $key) {
            $settings[$key] = '';
        }

        return $settings;
    }

    private function saveSettings(array $settings): void
    {
        $companyId = (int)($settings['company_id'] ?? 0);
        (new \eel_accounts\Service\CompanySettingsService())->saveCompanySection(
            new \eel_accounts\Store\CompanySettingsStore($companyId),
            $settings
        );
    }

    private function validationMessages(\eel_accounts\Service\VatValidationResultService $result, array $warnings): array
    {
        if ($result->status === 'valid' && $warnings === []) {
            return [[
                'type' => 'success',
                'message' => 'VAT number validated.',
            ]];
        }

        if ($result->status === 'valid') {
            return array_merge([[
                'type' => 'warning',
                'message' => 'VAT number validated, but the returned details do not fully match the Companies House record.',
            ]], array_map(
                static fn(string $warning): array => ['type' => 'warning', 'message' => $warning],
                $warnings
            ));
        }

        if ($result->status === 'invalid') {
            return [[
                'type' => 'error',
                'message' => 'VAT number validation returned invalid.',
            ]];
        }

        return [[
            'type' => 'error',
            'message' => trim((string)$result->error) !== '' ? trim((string)$result->error) : 'VAT validation failed.',
        ]];
    }
}
