<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class IxbrlAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));
        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);
        $changedFacts = ['ixbrl.readiness', 'ixbrl.disclosures', 'ixbrl.trial.balance', 'ixbrl.accounts.mapping', 'ixbrl.facts.preview', 'ixbrl.generation', 'page.context'];

        $contextError = $this->accountingContextError($companyId, $accountingPeriodId);
        if ($contextError !== null) {
            return $this->result(false, [$contextError], $changedFacts);
        }

        try {
            if ($intent === 'download_ixbrl_filing') {
                $this->downloadFiling($companyId, $accountingPeriodId);
            }
            if ($intent === 'save_ixbrl_disclosures') {
                $result = $this->saveDisclosures($request, $companyId, $accountingPeriodId);
                return $this->result(
                    !empty($result['success']),
                    (array)($result['errors'] ?? []),
                    $changedFacts,
                    (array)($result['messages'] ?? []),
                    (array)($result['warnings'] ?? [])
                );
            }
            if ($intent === 'save_ixbrl_core_details') {
                $result = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->saveCoreDetails(
                    $companyId,
                    $accountingPeriodId,
                    [
                        'accounting_standard' => $request->input('accounting_standard', 'FRS_105'),
                        'average_number_employees' => $request->input('average_number_employees', null),
                        'is_still_trading' => $request->input('is_still_trading', null),
                        'has_ever_traded' => $request->input('has_ever_traded', null),
                        'accounts_approval_date' => $request->input('accounts_approval_date', null),
                        'approving_director_name' => $request->input('approving_director_name', null),
                    ],
                    $this->actor($request)
                );
                return $this->result(
                    !empty($result['success']),
                    (array)($result['errors'] ?? []),
                    $changedFacts,
                    !empty($result['success']) ? ['Core accounts disclosure details saved.'] : [],
                    []
                );
            }
            if ($intent === 'save_ixbrl_disclosure_field') {
                $result = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->saveField(
                    $companyId,
                    $accountingPeriodId,
                    trim((string)$request->input('disclosure_field', '')),
                    $request->input(trim((string)$request->input('disclosure_field', '')), null),
                    $this->actor($request)
                );
                $fieldChangedFacts = ['ixbrl.readiness', 'ixbrl.disclosures'];
                return $this->result(
                    !empty($result['success']),
                    (array)($result['errors'] ?? []),
                    $fieldChangedFacts,
                    !empty($result['success']) ? ['Disclosure updated. Rebuild the iXBRL facts before generating or filing.'] : [],
                    []
                );
            }

            $readiness = (new \eel_accounts\Service\IxbrlReadinessService())->getReadiness($companyId, $accountingPeriodId);
            $result = match ($intent) {
                'build_ixbrl_facts' => !empty($readiness['can_build_facts'])
                    ? $this->buildFacts($companyId, $accountingPeriodId)
                    : ['success' => false, 'errors' => (array)($readiness['blocking_errors'] ?? ['iXBRL facts cannot be built yet.'])],
                'generate_ixbrl_preview' => !empty($readiness['can_generate'])
                    ? $this->generatePreview($companyId, $accountingPeriodId)
                    : ['success' => false, 'errors' => (array)($readiness['generation_errors'] ?? ['The iXBRL filing export cannot be generated yet.'])],
                'validate_ixbrl_external' => !empty($readiness['can_validate'])
                    ? $this->validateExternal($companyId, $accountingPeriodId)
                    : ['success' => false, 'errors' => ['Generate a current iXBRL export before running Arelle validation.']],
                default => ['success' => false, 'errors' => ['Unknown iXBRL builder action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return $this->result(
            !empty($result['success']),
            (array)($result['errors'] ?? []),
            $changedFacts,
            (array)($result['messages'] ?? []),
            (array)($result['warnings'] ?? [])
        );
    }

    private function saveDisclosures(RequestFramework $request, int $companyId, int $accountingPeriodId): array
    {
        $result = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->save(
            $companyId,
            $accountingPeriodId,
            [
                'accounting_standard' => $request->input('accounting_standard', 'FRS_105'),
                'average_number_employees' => $request->input('average_number_employees', null),
                'is_still_trading' => $request->input('is_still_trading', null),
                'has_ever_traded' => $request->input('has_ever_traded', null),
                'micro_entity_eligibility_confirmed' => $request->input('micro_entity_eligibility_confirmed', null),
                'going_concern_basis_appropriate' => $request->input('going_concern_basis_appropriate', null),
                'has_material_off_balance_sheet_arrangements' => $request->input('has_material_off_balance_sheet_arrangements', null),
                'has_director_advances_credits_or_guarantees' => $request->input('has_director_advances_credits_or_guarantees', null),
                'has_financial_commitments_guarantees_or_contingencies' => $request->input('has_financial_commitments_guarantees_or_contingencies', null),
                'accounts_approval_date' => $request->input('accounts_approval_date', null),
                'approving_director_name' => $request->input('approving_director_name', null),
                'prepared_under_small_companies_regime' => $request->input('prepared_under_small_companies_regime', null),
                'audit_exempt_section_477' => $request->input('audit_exempt_section_477', null),
                'directors_acknowledge_responsibilities' => $request->input('directors_acknowledge_responsibilities', null),
                'members_have_not_required_audit' => $request->input('members_have_not_required_audit', null),
            ],
            $this->actor($request)
        );
        if (!empty($result['success'])) {
            $result['messages'] = [!empty($result['changed'])
                ? 'Accounts disclosures saved. Rebuild the iXBRL facts before generating or filing.'
                : 'Accounts disclosures are already saved with these values.'];
        }

        return $result;
    }

    private function buildFacts(int $companyId, int $accountingPeriodId): array
    {
        $runId = (new \eel_accounts\Service\IxbrlFactBuilderService())->buildFacts($companyId, $accountingPeriodId);

        return ['success' => true, 'errors' => [], 'messages' => ['iXBRL facts built for run #' . $runId . '.']];
    }

    private function generatePreview(int $companyId, int $accountingPeriodId): array
    {
        $result = (new \eel_accounts\Service\IxbrlAccountingService())->generateFilingExport($companyId, $accountingPeriodId);
        if (empty($result['success'])) {
            return $result;
        }

        $result['messages'] = ['iXBRL filing export generated.'];
        $external = (new \eel_accounts\Service\IxbrlExternalValidationService())
            ->validateLatestRun($companyId, $accountingPeriodId);
        if ((string)($external['status'] ?? '') === 'passed') {
            $result['messages'][] = 'Arelle external validation passed for the generated file.';
        } else {
            $result['warnings'] = (array)($external['errors'] ?? [
                'The export was generated, but Arelle validation did not pass. Review the validation status before filing.',
            ]);
        }

        return $result;
    }

    private function validateExternal(int $companyId, int $accountingPeriodId): array
    {
        $result = (new \eel_accounts\Service\IxbrlExternalValidationService())->validateLatestRun($companyId, $accountingPeriodId);
        $status = (string)($result['status'] ?? 'error');
        if ($status === 'passed') {
            return ['success' => true, 'errors' => [], 'messages' => ['Arelle external validation passed.']];
        }
        if ($status === 'not_configured') {
            return ['success' => false, 'errors' => (array)($result['errors'] ?? ['Arelle is not configured.'])];
        }

        return ['success' => false, 'errors' => (array)($result['errors'] ?? ['Arelle external validation failed.'])];
    }

    private function downloadFiling(int $companyId, int $accountingPeriodId): never
    {
        $authorisedCompanyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();
        if ($companyId <= 0 || $companyId !== $authorisedCompanyId) {
            header('Content-Type: text/plain; charset=utf-8', true, 403);
            echo 'The selected company is not available in the current accounting context.';
            exit;
        }

        $artifact = (new \eel_accounts\Service\HmrcSubmissionPackageService())
            ->locateAccountsIxbrl($companyId, $accountingPeriodId);
        if (empty($artifact['ok']) || (string)($artifact['state'] ?? '') !== 'ready') {
            header('Content-Type: text/plain; charset=utf-8', true, 409);
            echo (string)(($artifact['errors'] ?? [])[0] ?? 'The filing-ready iXBRL artifact is not available.');
            exit;
        }

        $path = (string)($artifact['path'] ?? '');
        $filename = basename((string)($artifact['filename'] ?? 'accounts.xhtml'));
        if ($path === '' || !is_file($path)) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo 'The filing-ready iXBRL artifact was not found.';
            exit;
        }

        header('Content-Type: application/xhtml+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        $size = filesize($path);
        if (is_int($size)) {
            header('Content-Length: ' . $size);
        }
        readfile($path);
        exit;
    }

    private function result(
        bool $success,
        array $errors,
        array $changedFacts,
        array $messages = [],
        array $warnings = []
    ): ActionResultFramework
    {
        $flash = [];
        if ($success) {
            foreach ($messages !== [] ? $messages : ['iXBRL builder updated.'] as $message) {
                $flash[] = ['type' => 'success', 'message' => (string)$message];
            }
        } else {
            foreach ($errors !== [] ? $errors : ['iXBRL builder action failed.'] as $error) {
                $flash[] = ['type' => 'error', 'message' => (string)$error];
            }
        }
        foreach ($warnings as $warning) {
            $flash[] = ['type' => 'warning', 'message' => (string)$warning];
        }

        return new ActionResultFramework($success, $changedFacts, $flash);
    }

    private function accountingContextError(int $companyId, int $accountingPeriodId): ?string
    {
        $context = new \eel_accounts\Service\AccountingContextService();
        $authorisedCompanyId = $context->authCompanyId();
        $authorisedAccountingPeriodId = $context->authAccountingPeriodId();

        if ($authorisedCompanyId <= 0 || $authorisedAccountingPeriodId <= 0) {
            return 'Select a company and accounting period before using the iXBRL builder.';
        }
        if ($companyId !== $authorisedCompanyId || $accountingPeriodId !== $authorisedAccountingPeriodId) {
            return 'The submitted iXBRL company or accounting period does not match the authenticated accounting context.';
        }

        return null;
    }

    private function actor(RequestFramework $request): string
    {
        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId > 0) {
                return 'user:' . $userId;
            }
        } catch (Throwable) {
        }

        return 'web_app';
    }
}
