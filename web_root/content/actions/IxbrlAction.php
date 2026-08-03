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
        // CT600 construction performs external-schema validation and may take
        // longer than PHP's normal request limit.  This must be set before the
        // first database or filesystem check for the generation action.
        if (in_array($intent, [
            'generate_all_filing_ixbrl',
            'generate_ct600_xml',
            'revalidate_arelle',
            'approve_ixbrl_accounts_and_ct',
        ], true)) {
            $this->allowUnlimitedGenerationRuntime();
        }
        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);
        $ctPeriodId = (int)$request->input('ct_period_id', 0);
        $runId = (int)$request->input('run_id', 0);
        $changedFacts = ['ixbrl.readiness', 'ixbrl.disclosures', 'ixbrl.trial.balance', 'ixbrl.accounts.mapping', 'ixbrl.facts.preview', 'ixbrl.generation', 'ct.filing', 'hmrc.ct600.submissions', 'page.context'];

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
            if ($intent === 'download_ct600_xml') {
                $this->downloadCt600($companyId, $accountingPeriodId, $ctPeriodId);
            }
            if ($intent === 'download_arelle_log') {
                $this->downloadArelleLog(
                    $companyId,
                    $accountingPeriodId,
                    trim((string)$request->input('arelle_scope', '')),
                    $runId,
                    $ctPeriodId,
                    (int)$request->input('submission_id', 0)
                );
            }
            if ($intent === 'edit_ixbrl_approval_draft') {
                $input = $this->approvalFormInput($request);
                $input['ixbrl_approval_editing'] = '1';
                return ActionResultFramework::success(
                    ['ixbrl.disclosures'],
                    [],
                    [],
                    ['ixbrl_approval_form_input' => $input]
                );
            }
            if ($intent === 'cancel_ixbrl_approval_edit') {
                return ActionResultFramework::success(['ixbrl.disclosures']);
            }
            if ($intent === 'save_ixbrl_approval_draft') {
                $input = $this->approvalFormInput($request);
                try {
                    $result = (new \eel_accounts\Service\IxbrlFilingApprovalWorkflowService())->saveDraft(
                        $companyId,
                        $accountingPeriodId,
                        $input,
                        $this->actor($request),
                        trim((string)$request->input('state_token', ''))
                    );
                    return $this->result(
                        true,
                        [],
                        $changedFacts,
                        (array)($result['messages'] ?? [
                            'Filing approval draft saved. No statutory accounts or Corporation Tax filing approval was created.',
                        ]),
                        (array)($result['warnings'] ?? [])
                    );
                } catch (Throwable $exception) {
                    return $this->result(
                        false,
                        [$exception->getMessage()],
                        $changedFacts,
                        [],
                        [],
                        ['ixbrl_approval_form_input' => $input]
                    );
                }
            }
            if ($intent === 'approve_ixbrl_accounts_and_ct') {
                $input = $this->approvalFormInput($request);
                $progress = $services->actionProgress();
                $progress->report('Validating the combined accounts and Corporation Tax approval…', 0);
                try {
                    $result = (new \eel_accounts\Service\IxbrlFilingApprovalWorkflowService())->approveAll(
                        $companyId,
                        $accountingPeriodId,
                        $input,
                        $this->actor($request),
                        trim((string)$request->input('approval_note', '')),
                        trim((string)$request->input('state_token', '')),
                        static function (string $message, int $percent) use ($progress): void {
                            $progress->report($message, $percent);
                        }
                    );
                    return $this->result(
                        true,
                        [],
                        $changedFacts,
                        (array)($result['messages'] ?? [
                            'The statutory accounts and Corporation Tax return are approved. No information was transmitted.',
                        ]),
                        (array)($result['warnings'] ?? [])
                    );
                } catch (Throwable $exception) {
                    return $this->result(
                        false,
                        [$exception->getMessage()],
                        $changedFacts,
                        [],
                        [],
                        ['ixbrl_approval_form_input' => $input]
                    );
                }
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
                        'principal_activity_sic_code' => $request->input('principal_activity_sic_code', null),
                        'is_still_trading' => $request->input('is_still_trading', null),
                        'has_ever_traded' => $request->input('has_ever_traded', null),
                        'accounts_approval_date' => $request->input('accounts_approval_date', null),
                        'approving_director_id' => $request->input('approving_director_id', null),
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
                if (!empty($result['success'])) {
                    (new \eel_accounts\Service\YearEndSectionApprovalService())->invalidate(
                        $companyId,
                        $accountingPeriodId,
                        'tax_readiness_acknowledgement',
                        'Corporation Tax filing-scope answer changed'
                    );
                }
                return $this->result(
                    !empty($result['success']),
                    (array)($result['errors'] ?? []),
                    !empty($result['success'])
                        // Scope answers do not alter the tax calculation or
                        // checklist. The scope gate is synchronised from the
                        // selected radios by project.js, while the server
                        // rebuilds and validates the signed approval basis
                        // when approval is submitted. Avoid rebuilding the
                        // expensive tax-readiness card after every answer.
                        ? ['corporation.tax.filing.scope']
                        : [],
                    !empty($result['success']) ? ['Corporation Tax filing scope updated. Approve the Corporation Tax return again before generating or filing.'] : [],
                    []
                );
            }
            if ($intent === 'save_ct600_return_authorisation') {
                return $this->result(
                    false,
                    ['This approval form is out of date. Reload the page and use Approve Accounts and Corporation Tax Return.'],
                    $changedFacts
                );
            }
            if ($intent === 'approve_ixbrl_accounts_filing_basis') {
                return $this->result(
                    false,
                    ['This approval form is out of date. Reload the page and use Approve Accounts and Corporation Tax Return.'],
                    $changedFacts
                );
            }
            if ($intent === 'rebuild_ixbrl_facts_from_current_approval') {
                $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
                $approvalService = new \eel_accounts\Service\IxbrlAccountsFilingApprovalService();
                $approvalStatus = $approvalService->status($companyId, $accountingPeriodId);
                $legacyUpgrade = !empty($approvalStatus['current'])
                    && (string)($approvalStatus['approval_source'] ?? '') === 'legacy_combined';
                if (!$developerOptions && !$legacyUpgrade) {
                    return $this->result(
                        false,
                        [
                            'Developer options must be enabled to rebuild an approved iXBRL fact snapshot, '
                            . 'except when upgrading a verified pre-split approval to the neutral report.',
                        ],
                        $changedFacts
                    );
                }
                if (!$developerOptions && $legacyUpgrade) {
                    $latestRun = (new \eel_accounts\Service\IxbrlFactBuilderService())->getLatestRun(
                        $companyId,
                        $accountingPeriodId
                    );
                    if (is_array($latestRun)
                        && (string)(($latestRun['run_freshness'] ?? [])['state'] ?? '') === 'current') {
                        return $this->result(
                            false,
                            ['The authority-neutral approved fact snapshot is already current.'],
                            $changedFacts
                        );
                    }
                }
                $runId = $approvalService->rebuildFactsFromCurrentApproval(
                    $companyId,
                    $accountingPeriodId
                );
                return $this->result(
                    true,
                    [],
                    $changedFacts,
                    [
                        ($legacyUpgrade
                            ? 'Authority-neutral facts rebuilt from the verified pre-split approval as run #'
                            : 'Approved iXBRL fact snapshot rebuilt as run #')
                        . $runId . '.',
                    ],
                    []
                );
            }
            if ($intent === 'cleanup_untransmitted_ixbrl_history') {
                if (!(bool)AppConfigurationStore::get('developer_options', false)) {
                    return $this->result(
                        false,
                        ['Developer options must be enabled to clean untransmitted iXBRL history.'],
                        $changedFacts
                    );
                }
                $cleanup = (new \eel_accounts\Service\IxbrlUntransmittedHistoryCleanupService())
                    ->clean($companyId, $accountingPeriodId);
                return $this->result(
                    true,
                    [],
                    $changedFacts,
                    [
                        (int)($cleanup['deleted_approvals'] ?? 0) . ' untransmitted filing approval(s), '
                        . (int)$cleanup['deleted_runs'] . ' unlinked iXBRL run(s) and '
                        . ((int)$cleanup['deleted_companies_house_drafts'] + (int)$cleanup['deleted_hmrc_drafts'])
                        . ' untransmitted submission draft(s) removed; '
                        . (int)($cleanup['cleared_ct600_outputs'] ?? 0) . ' untransmitted CT600 iXBRL output(s) cleared. '
                        . (int)($cleanup['deleted_bundles'] ?? 0) . ' unused evidence bundle(s) and '
                        . (int)($cleanup['deleted_tax_audit_snapshots'] ?? 0) . ' obsolete Tax Audit snapshot(s) removed. '
                        . 'Generated files were retained.',
                    ],
                    []
                );
            }

            if ($intent === 'generate_all_filing_ixbrl') {
                $result = (new \eel_accounts\Service\IxbrlFilingSetGenerationService())->generate(
                    $companyId,
                    $accountingPeriodId,
                    $this->actor($request),
                    $services->actionProgress()
                );
            } elseif ($intent === 'sync_missing_ixbrl_runs') {
                if (!(bool)AppConfigurationStore::get('developer_options', false)) {
                    return $this->result(false, ['Developer options must be enabled to synchronise iXBRL run records.'], $changedFacts);
                }
                $cleanup = (new \eel_accounts\Service\IxbrlGenerationRunCleanupService())
                    ->removeMissingArtifacts($companyId, $accountingPeriodId);
                $result = [
                    'success' => !empty($cleanup['success']),
                    'errors' => (array)($cleanup['errors'] ?? []),
                    'messages' => [
                        (int)($cleanup['deleted_count'] ?? 0) . ' empty missing-file run record(s) removed; '
                        . (int)($cleanup['reset_count'] ?? 0) . ' approved fact snapshot(s) retained for regeneration; '
                        . (int)($cleanup['deleted_draft_count'] ?? 0) . ' unsent Companies House draft(s) removed; '
                        . (int)($cleanup['present_count'] ?? 0) . ' artifact-backed run(s) retained.',
                    ],
                    'warnings' => !empty($cleanup['skipped_count'])
                        ? [(int)$cleanup['skipped_count'] . ' missing-file run(s) retained because they are referenced by transmitted or in-flight Companies House filings.']
                        : [],
                ];
            } elseif ($intent === 'sync_missing_ct600_xml_artifacts') {
                if (!(bool)AppConfigurationStore::get('developer_options', false)) {
                    return $this->result(false, ['Developer options must be enabled to synchronise CT600 XML artifact records.'], $changedFacts);
                }
                $cleanup = (new \eel_accounts\Service\Ct600GenerationArtifactCleanupService())
                    ->removeMissingArtifacts($companyId, $accountingPeriodId);
                $result = [
                    'success' => !empty($cleanup['success']),
                    'errors' => (array)($cleanup['errors'] ?? []),
                    'messages' => [
                        (int)($cleanup['deleted_count'] ?? 0) . ' missing-file CT600 XML artifact record(s) removed; '
                        . (int)($cleanup['present_count'] ?? 0) . ' artifact-backed record(s) retained.',
                    ],
                    'warnings' => !empty($cleanup['skipped_count'])
                        ? [(int)$cleanup['skipped_count'] . ' missing-file CT600 XML artifact record(s) retained because they are referenced by an in-flight or completed HMRC submission.']
                        : [],
                ];
            } elseif ($intent === 'revalidate_arelle') {
                if (!(bool)AppConfigurationStore::get('developer_options', false)) {
                    return $this->result(false, ['Developer options must be enabled to revalidate iXBRL with Arelle.'], $changedFacts);
                }
                $progress = $services->actionProgress();
                $scope = trim((string)$request->input('arelle_scope', ''));
                $progress->report('Preparing the selected iXBRL artifact for Arelle revalidation…', 0);
                $result = $this->withFilingLock(
                    $companyId,
                    $accountingPeriodId,
                    function () use ($scope, $companyId, $accountingPeriodId, $ctPeriodId, $runId, $request, $progress): array {
                        $progress->report('Exclusive filing-validation lock acquired.', 5);
                        $progress->report('Running Arelle validation for the selected iXBRL artifact…', 15);
                        $result = match ($scope) {
                            'accounts' => $this->validateExternalRun($runId),
                            'computation' => $this->validateComputation($companyId, $accountingPeriodId, $ctPeriodId),
                            'companies_house' => (new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService())
                                ->revalidatePreparedArtifact(
                                    $companyId,
                                    $accountingPeriodId,
                                    (int)$request->input('submission_id', 0),
                                    $progress
                                ),
                            default => ['success' => false, 'errors' => ['Select a valid Arelle validation result to revalidate.']],
                        };
                        $progress->report(
                            !empty($result['success'])
                                ? 'Arelle revalidation completed successfully.'
                                : 'Arelle revalidation completed with errors.',
                            100
                        );
                        return $result;
                    }
                );
            } elseif (in_array($intent, ['generate_computation_ixbrl', 'validate_computation_ixbrl', 'generate_ct600_xml'], true)) {
                if ($intent === 'generate_ct600_xml') {
                    $progress = $services->actionProgress();
                    $progress->report('Unlimited generation timeout enabled; preparing Corporation Tax CT600 XML generation…', 0);
                    $result = $this->withFilingLock($companyId, $accountingPeriodId,
                        function () use ($companyId, $accountingPeriodId, $ctPeriodId, $progress): array {
                            $progress->report('Exclusive filing-generation lock acquired.', 2);
                            return (new \eel_accounts\Service\Ct600GenerationService())->generate(
                                $companyId,
                                $accountingPeriodId,
                                $ctPeriodId,
                                static function (string $message, int $percent) use ($progress): void {
                                    $progress->report($message, $percent);
                                }
                            );
                        }
                    );
                } elseif ($intent === 'generate_computation_ixbrl') {
                    $progress = $services->actionProgress();
                    @set_time_limit(0);
                    $progress->report('Generating the Corporation Tax period iXBRL…', 0);
                    $result = $this->withFilingLock(
                        $companyId,
                        $accountingPeriodId,
                        fn(): array => $this->generateComputation(
                            $companyId,
                            $accountingPeriodId,
                            $ctPeriodId,
                            static function () use ($progress): void {
                                $progress->report('Running Arelle validation for the Corporation Tax period iXBRL…', 70);
                            }
                        )
                    );
                } else {
                    $result = $this->withFilingLock(
                        $companyId,
                        $accountingPeriodId,
                        fn(): array => $this->validateComputation(
                            $companyId,
                            $accountingPeriodId,
                            $ctPeriodId
                        )
                    );
                }
            } else {
                $readiness = (new \eel_accounts\Service\IxbrlReadinessService())->getReadiness($companyId, $accountingPeriodId);
                $result = match ($intent) {
                    'build_ixbrl_facts' => !empty($readiness['can_build_facts'])
                        ? $this->withFilingLock(
                            $companyId,
                            $accountingPeriodId,
                            fn(): array => $this->buildFacts($companyId, $accountingPeriodId)
                        )
                        : ['success' => false, 'errors' => (array)($readiness['blocking_errors'] ?? ['iXBRL facts cannot be built yet.'])],
                    'generate_ixbrl_preview' => !empty($readiness['can_generate'])
                        ? $this->withFilingLock(
                            $companyId,
                            $accountingPeriodId,
                            fn(): array => $this->generatePreview(
                                $companyId,
                                $accountingPeriodId,
                                $services->actionProgress(),
                                0,
                                70
                            )
                        )
                        : ['success' => false, 'errors' => (array)($readiness['generation_errors'] ?? ['The iXBRL filing export cannot be generated yet.'])],
                    'validate_ixbrl_external' => !empty($readiness['can_validate'])
                        ? $this->withFilingLock(
                            $companyId,
                            $accountingPeriodId,
                            fn(): array => $this->validateExternal($companyId, $accountingPeriodId)
                        )
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
                'principal_activity_sic_code' => $request->input('principal_activity_sic_code', null),
                'is_still_trading' => $request->input('is_still_trading', null),
                'has_ever_traded' => $request->input('has_ever_traded', null),
                'micro_entity_eligibility_confirmed' => $request->input('micro_entity_eligibility_confirmed', null),
                'going_concern_basis_appropriate' => $request->input('going_concern_basis_appropriate', null),
                'has_material_off_balance_sheet_arrangements' => $request->input('has_material_off_balance_sheet_arrangements', null),
                'has_director_advances_credits_or_guarantees' => $request->input('has_director_advances_credits_or_guarantees', null),
                'has_financial_commitments_guarantees_or_contingencies' => $request->input('has_financial_commitments_guarantees_or_contingencies', null),
                'accounts_approval_date' => $request->input('accounts_approval_date', null),
                'approving_director_id' => $request->input('approving_director_id', null),
                'prepared_under_small_companies_regime' => $request->input('prepared_under_small_companies_regime', null),
                'audit_exempt_section_477' => $request->input('audit_exempt_section_477', null),
                'directors_acknowledge_responsibilities' => $request->input('directors_acknowledge_responsibilities', null),
                'members_have_not_required_audit' => $request->input('members_have_not_required_audit', null),
                'companies_house_revised_accounts_public_register_confirmed' => $request->input('companies_house_revised_accounts_public_register_confirmed', null),
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

    /** @return array<string,mixed> */
    private function approvalFormInput(RequestFramework $request): array
    {
        return [
            'accounting_standard' => $request->input('accounting_standard', 'FRS_105'),
            'average_number_employees' => $request->input('average_number_employees', null),
            'principal_activity_sic_code' => $request->input('principal_activity_sic_code', null),
            'is_still_trading' => $request->input('is_still_trading', null),
            'has_ever_traded' => $request->input('has_ever_traded', null),
            'micro_entity_eligibility_confirmed' => $request->input('micro_entity_eligibility_confirmed', null),
            'going_concern_basis_appropriate' => $request->input('going_concern_basis_appropriate', null),
            'has_material_off_balance_sheet_arrangements' => $request->input('has_material_off_balance_sheet_arrangements', null),
            'has_director_advances_credits_or_guarantees' => $request->input('has_director_advances_credits_or_guarantees', null),
            'has_financial_commitments_guarantees_or_contingencies' => $request->input('has_financial_commitments_guarantees_or_contingencies', null),
            'accounts_approval_date' => $request->input('accounts_approval_date', null),
            'approving_director_id' => $request->input('approving_director_id', null),
            'prepared_under_small_companies_regime' => $request->input('prepared_under_small_companies_regime', null),
            'audit_exempt_section_477' => $request->input('audit_exempt_section_477', null),
            'directors_acknowledge_responsibilities' => $request->input('directors_acknowledge_responsibilities', null),
            'members_have_not_required_audit' => $request->input('members_have_not_required_audit', null),
            'companies_house_revised_accounts_public_register_confirmed' => $request->input(
                'companies_house_revised_accounts_public_register_confirmed',
                null
            ),
            'declarant_authority' => $request->input('declarant_authority', ''),
            'original_unfiled_confirmed' => $request->input('original_unfiled_confirmed', null),
            'authority_confirmed' => $request->input('authority_confirmed', null),
            'declaration_confirmed' => $request->input('declaration_confirmed', null),
            'approval_note' => $request->input('approval_note', ''),
            'ixbrl_approval_editing' => $request->input('ixbrl_approval_editing', '0'),
        ];
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
        if ($progress !== null) {
            @set_time_limit(0);
        }
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
            $errors = (array)($external['errors'] ?? [
                'The export was generated, but Arelle validation did not pass.',
            ]);
            $progress?->report(
                'HMRC Accounting iXBRL failed Arelle validation. HMRC CT600 generation is blocked; Companies House generation remains independent.',
                $validationPercent ?? 0
            );
            return [
                'success' => false,
                'errors' => $errors,
                'warnings' => [],
                'messages' => ['The HMRC Accounting iXBRL was generated but did not pass Arelle validation.'],
            ];
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

    private function validateExternalRun(int $runId): array
    {
        if ($runId <= 0) {
            return ['success' => false, 'errors' => ['The selected iXBRL generation run is unavailable.']];
        }
        $result = (new \eel_accounts\Service\IxbrlExternalValidationService())->validateRun($runId);
        if ((string)($result['status'] ?? '') === 'passed') {
            return ['success' => true, 'errors' => [], 'messages' => ['Arelle external validation passed.']];
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

        $artifact = (new \eel_accounts\Service\IxbrlArtifactDownloadService())
            ->accounts($companyId, $accountingPeriodId);
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

    private function validateComputation(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $result = (new \eel_accounts\Service\IxbrlTaxComputationService())
            ->validateFilingExport($companyId, $accountingPeriodId, $ctPeriodId);
        if (!empty($result['success'])) {
            $result['messages'] = ['Computations iXBRL external validation passed for CT period #' . $ctPeriodId . '.'];
        }
        return $result;
    }

    private function allowUnlimitedGenerationRuntime(): void
    {
        // set_time_limit() resets the per-script timer on SAPIs that support
        // it; ini_set covers CGI/FastCGI configurations which read the value
        // directly.  Both are deliberately best-effort for compatibility.
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        @ignore_user_abort(true);
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
        $artifact = (new \eel_accounts\Service\IxbrlArtifactDownloadService())
            ->computation($companyId, $accountingPeriodId, $ctPeriodId);
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

    private function downloadCt600(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): never {
        $context = new \eel_accounts\Service\AccountingContextService();
        if ($companyId <= 0 || $companyId !== $context->authCompanyId()
            || $accountingPeriodId <= 0
            || $accountingPeriodId !== $context->authAccountingPeriodId()) {
            header('Content-Type: text/plain; charset=utf-8', true, 403);
            echo 'The submitted CT600 XML does not match the authenticated accounting context.';
            exit;
        }
        $result = (new \eel_accounts\Service\Ct600GenerationService())->downloadArtifact(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId
        );
        if (empty($result['success'])) {
            header('Content-Type: text/plain; charset=utf-8', true, 409);
            echo (string)(($result['errors'] ?? [])[0]
                ?? 'The current validated CT600 XML artifact is unavailable.');
            exit;
        }
        $artifact = (array)$result['artifact'];
        $path = (string)($artifact['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo 'The current validated CT600 XML artifact was not found.';
            exit;
        }
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="'
            . str_replace('"', '', basename((string)$artifact['filename'])) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        $size = filesize($path);
        if (is_int($size)) {
            header('Content-Length: ' . $size);
        }
        readfile($path);
        exit;
    }

    private function downloadArelleLog(
        int $companyId,
        int $accountingPeriodId,
        string $scope,
        int $runId,
        int $ctPeriodId,
        int $submissionId
    ): never {
        $log = (new \eel_accounts\Service\IxbrlArelleLogDownloadService())->resolve(
            $companyId,
            $accountingPeriodId,
            $scope,
            $runId,
            $ctPeriodId,
            $submissionId
        );
        if (empty($log['ok'])) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo (string)(($log['errors'] ?? [])[0] ?? 'The Arelle diagnostic log is unavailable.');
            exit;
        }

        $path = (string)$log['path'];
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="'
            . str_replace('"', '', basename((string)$log['filename'])) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
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
        array $warnings = [],
        array $context = []
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

        return new ActionResultFramework($success, $changedFacts, $flash, [], $context);
    }

    private function withFilingLock(
        int $companyId,
        int $accountingPeriodId,
        callable $operation
    ): array {
        try {
            return (array)(new \eel_accounts\Service\IxbrlFilingOperationLockService())->execute(
                $companyId,
                $accountingPeriodId,
                $operation
            );
        } catch (Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
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
