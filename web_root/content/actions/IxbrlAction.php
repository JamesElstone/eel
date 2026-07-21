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
        $ctPeriodId = (int)$request->input('ct_period_id', 0);
        $changedFacts = ['ixbrl.readiness', 'ixbrl.disclosures', 'ixbrl.trial.balance', 'ixbrl.accounts.mapping', 'ixbrl.facts.preview', 'ixbrl.generation', 'ct.filing', 'page.context'];

        $contextError = $this->accountingContextError($companyId, $accountingPeriodId);
        if ($contextError !== null) {
            return $this->result(false, [$contextError], $changedFacts);
        }

        try {
            if ($intent === 'download_ixbrl_filing') {
                $this->downloadFiling($companyId, $accountingPeriodId);
            }
            if ($intent === 'download_computation_ixbrl') {
                $this->downloadComputation($companyId, $accountingPeriodId, $ctPeriodId);
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
                return $this->result(
                    !empty($result['success']),
                    (array)($result['errors'] ?? []),
                    $changedFacts,
                    !empty($result['success']) ? ['Disclosure updated. Approve the revised filing basis before generating or filing.'] : [],
                    []
                );
            }
            if ($intent === 'save_ct_filing_scope_answer') {
                $result = (new \eel_accounts\Service\CorporationTaxFilingScopeService())->saveAnswer(
                    $companyId,
                    $accountingPeriodId,
                    trim((string)$request->input('scope_field', '')),
                    trim((string)$request->input('scope_answer', '')),
                    $this->actor($request)
                );
                return $this->result(
                    !empty($result['success']),
                    (array)($result['errors'] ?? []),
                    $changedFacts,
                    !empty($result['success']) ? ['Corporation Tax filing scope updated. Approve the revised filing basis before generating or filing.'] : [],
                    []
                );
            }
            if ($intent === 'approve_ixbrl_accounts_filing_basis') {
                $progress = $services->actionProgress();
                $progress->report('Validating the approved accounts filing basis…', 0);
                $approved = (new \eel_accounts\Service\IxbrlAccountsFilingApprovalService())->approveAndBuildFacts(
                    $companyId,
                    $accountingPeriodId,
                    $this->actor($request),
                    trim((string)$request->input('approval_note', '')),
                    static function (string $message, int $percent) use ($progress): void {
                        $progress->report($message, $percent);
                    }
                );
                return $this->result(
                    true,
                    [],
                    $changedFacts,
                    [
                        'Business Disclosures recorded and Statement of Facts updated.',
                    ],
                    []
                );
            }

            if ($intent === 'generate_all_filing_ixbrl') {
                $result = $this->generateAllFilingIxbrl(
                    $companyId,
                    $accountingPeriodId,
                    $services->actionProgress()
                );
            } elseif (in_array($intent, ['generate_computation_ixbrl', 'validate_computation_ixbrl'], true)) {
                if ($intent === 'generate_computation_ixbrl') {
                    $progress = $services->actionProgress();
                    $progress->report('Generating the Corporation Tax period iXBRL…', 0);
                    $result = $this->generateComputation(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        static function () use ($progress): void {
                            $progress->report('Running Arelle validation for the Corporation Tax period iXBRL…', 70);
                        }
                    );
                } else {
                    $result = $this->validateComputation($companyId, $accountingPeriodId, $ctPeriodId);
                }
            } else {
                $readiness = (new \eel_accounts\Service\IxbrlReadinessService())->getReadiness($companyId, $accountingPeriodId);
                $result = match ($intent) {
                    'build_ixbrl_facts' => !empty($readiness['can_build_facts'])
                        ? $this->buildFacts($companyId, $accountingPeriodId)
                        : ['success' => false, 'errors' => (array)($readiness['blocking_errors'] ?? ['iXBRL facts cannot be built yet.'])],
                    'generate_ixbrl_preview' => !empty($readiness['can_generate'])
                        ? $this->generatePreview($companyId, $accountingPeriodId, $services->actionProgress(), 0, 70)
                        : ['success' => false, 'errors' => (array)($readiness['generation_errors'] ?? ['The iXBRL filing export cannot be generated yet.'])],
                    'validate_ixbrl_external' => !empty($readiness['can_validate'])
                        ? $this->validateExternal($companyId, $accountingPeriodId)
                        : ['success' => false, 'errors' => ['Generate a current iXBRL export before running Arelle validation.']],
                    default => ['success' => false, 'errors' => ['Unknown iXBRL builder action.']],
                };
            }
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

    private function generatePreview(
        int $companyId,
        int $accountingPeriodId,
        ?ActionProgressFramework $progress = null,
        ?int $generationPercent = null,
        ?int $validationPercent = null
    ): array
    {
        if ($generationPercent !== null) {
            $progress?->report('Generating the accounts iXBRL…', $generationPercent);
        }
        $result = (new \eel_accounts\Service\IxbrlAccountingService())->generateFilingExport($companyId, $accountingPeriodId);
        if (empty($result['success'])) {
            return $result;
        }

        $result['messages'] = ['iXBRL filing export generated.'];
        if ($validationPercent !== null) {
            $progress?->report('Running Arelle validation for the accounts iXBRL…', $validationPercent);
        }
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

    private function generateComputation(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        ?\Closure $beforeExternalValidation = null
    ): array {
        $result = (new \eel_accounts\Service\IxbrlTaxComputationService())
            ->generateFilingExport($companyId, $accountingPeriodId, $ctPeriodId, $beforeExternalValidation);
        if (!empty($result['success'])) {
            $result['messages'] = ['Computations iXBRL generated and externally validated for CT period #' . $ctPeriodId . '.'];
        }
        return $result;
    }

    private function generateAllFilingIxbrl(
        int $companyId,
        int $accountingPeriodId,
        ActionProgressFramework $progress
    ): array
    {
        $progress->report('Checking accounts iXBRL filing readiness…', 0);
        $readiness = (new \eel_accounts\Service\IxbrlReadinessService())
            ->getReadiness($companyId, $accountingPeriodId);
        if (empty($readiness['can_generate'])) {
            return [
                'success' => false,
                'errors' => (array)($readiness['generation_errors'] ?? ['The accounts iXBRL is not ready to generate.']),
            ];
        }

        $progress->report('Checking Corporation Tax period readiness…', 15);
        $projection = (new \eel_accounts\Service\CorporationTaxPeriodService())
            ->projectForAccountingPeriod($companyId, $accountingPeriodId);
        $periods = array_values(array_filter(
            (array)($projection['periods'] ?? []),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));
        if ($periods === []) {
            return ['success' => false, 'errors' => ['No current CT periods are available for computations generation.']];
        }

        $errors = [];
        $periodIds = [];
        $computationService = new \eel_accounts\Service\IxbrlTaxComputationService();
        foreach ($periods as $period) {
            $ctPeriodId = (int)($period['ct_period_id'] ?? $period['id'] ?? 0);
            if ($ctPeriodId <= 0) {
                $errors[] = 'A projected CT period has no valid identifier.';
                continue;
            }

            $status = $computationService->status($companyId, $accountingPeriodId, $ctPeriodId);
            if (empty($status['ready'])) {
                $periodErrors = array_values(array_unique(array_merge(
                    (array)($status['errors'] ?? []),
                    (array)($status['artifact_errors'] ?? [])
                )));
                $errors[] = 'CT period #' . $ctPeriodId . ': '
                    . (string)($periodErrors[0] ?? 'the computation iXBRL is not ready to generate.');
                continue;
            }
            $periodIds[] = $ctPeriodId;
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => array_values(array_unique($errors))];
        }

        $accounts = $this->generatePreview($companyId, $accountingPeriodId, $progress, 30, 42);
        if (empty($accounts['success'])) {
            return $accounts;
        }

        $warnings = (array)($accounts['warnings'] ?? []);
        $messages = [$warnings === []
            ? 'Accounts iXBRL generated and validated.'
            : 'Accounts iXBRL generated; review its external-validation warning.'];
        $generatedPeriods = 0;
        $periodCount = count($periodIds);
        foreach ($periodIds as $periodIndex => $ctPeriodId) {
            $generationPercent = 45 + (int)floor(($periodIndex / $periodCount) * 50);
            $validationPercent = 45 + (int)floor((($periodIndex + 0.5) / $periodCount) * 50);
            $progress->report(
                'Generating iXBRL for Corporation Tax period '
                . ($periodIndex + 1) . ' of ' . $periodCount . '…',
                $generationPercent
            );
            $computation = $this->generateComputation(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                static function () use ($progress, $periodIndex, $periodCount, $validationPercent): void {
                    $progress->report(
                        'Running Arelle validation for Corporation Tax period '
                        . ($periodIndex + 1) . ' of ' . $periodCount . '…',
                        $validationPercent
                    );
                }
            );
            if (empty($computation['success'])) {
                foreach ((array)($computation['errors'] ?? ['Computations iXBRL generation failed.']) as $error) {
                    $errors[] = 'CT period #' . $ctPeriodId . ': ' . (string)$error;
                }
                continue;
            }
            $generatedPeriods++;
            $warnings = array_merge($warnings, (array)($computation['warnings'] ?? []));
        }

        if ($errors !== []) {
            $warnings[] = 'Some filing artifacts were generated successfully; resolve the errors and use this action again.';
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        $progress->report('Finalising the filing iXBRL set…', 98);
        $messages[] = $generatedPeriods === 1
            ? 'The computation iXBRL for 1 CT period was generated and validated.'
            : 'The computation iXBRLs for ' . $generatedPeriods . ' CT periods were generated and validated.';

        return [
            'success' => true,
            'errors' => [],
            'messages' => $messages,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function validateComputation(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $result = (new \eel_accounts\Service\IxbrlTaxComputationService())
            ->validateFilingExport($companyId, $accountingPeriodId, $ctPeriodId);
        if (!empty($result['success'])) {
            $result['messages'] = ['Computations iXBRL external validation passed for CT period #' . $ctPeriodId . '.'];
        }
        return $result;
    }

    private function downloadComputation(int $companyId, int $accountingPeriodId, int $ctPeriodId): never
    {
        $context = new \eel_accounts\Service\AccountingContextService();
        if ($companyId <= 0 || $companyId !== $context->authCompanyId()
            || $accountingPeriodId <= 0 || $accountingPeriodId !== $context->authAccountingPeriodId()) {
            header('Content-Type: text/plain; charset=utf-8', true, 403);
            echo 'The submitted computation does not match the authenticated accounting context.';
            exit;
        }
        $artifact = (new \eel_accounts\Service\HmrcSubmissionPackageService())
            ->locateComputationsIxbrlForCtPeriod($companyId, $ctPeriodId);
        if (empty($artifact['ok']) || (string)($artifact['state'] ?? '') !== 'ready') {
            header('Content-Type: text/plain; charset=utf-8', true, 409);
            echo (string)(($artifact['errors'] ?? [])[0] ?? 'The filing-ready computations iXBRL artifact is unavailable.');
            exit;
        }
        $path = (string)$artifact['path'];
        if (!is_file($path)) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo 'The filing-ready computations iXBRL artifact was not found.';
            exit;
        }
        header('Content-Type: application/xhtml+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename((string)$artifact['filename'])) . '"');
        $size = filesize($path);
        if (is_int($size)) { header('Content-Length: ' . $size); }
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
