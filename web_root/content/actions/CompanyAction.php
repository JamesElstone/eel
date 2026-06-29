<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CompanyAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));

        return match ($intent) {
            'search_company' => $this->searchCompany($request),
            'add_company' => $this->addCompany($request),
            'refresh_company' => $this->refreshCompany($request),
            'refresh_sic' => $this->refreshSic($request),
            'save_company' => $this->saveCompany($request),
            'clear_imported_accounting_data' => $this->clearImportedAccountingData($request),
            'delete_orphaned_transferred_files' => $this->deleteOrphanedTransferredFiles($request),
            'delete_company' => $this->deleteCompany($request),
            'add_accounting_period' => $this->saveAccountingPeriod($request, true),
            'update_accounting_period' => $this->saveAccountingPeriod($request, false),
            'save_import_review' => $this->saveImportReview($request),
            default => ActionResultFramework::none(),
        };
    }

    private function searchCompany(RequestFramework $request): ActionResultFramework
    {
        $searchTerm = $this->normaliseSearchTerm((string)$request->input('company_search_term', ''));

        if ($searchTerm === '') {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'Enter a company name or number before searching Companies House.',
                ]],
                [],
                [
                    'company_search_term' => '',
                    'company_search_results' => [],
                ]
            );
        }

        try {
            $searchResponse = $this->searchCompanies($searchTerm);
            $results = $searchResponse['results'];
            $meta = $searchResponse['meta'];
            $flashMessages = [];

            if (($meta['mode'] ?? '') === 'number' && $results === []) {
                $flashMessages[] = 'No exact company profile was found for that number, so the Companies House search endpoint was checked as a fallback.';
            }

            if ($results === []) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => 'No Companies House matches were found for that search using ' . ($meta['environment'] ?? 'TEST') . ' credentials.',
                ];
            } else {
                $flashMessages[] = 'Companies House returned ' . count($results) . ' match' . (count($results) === 1 ? '' : 'es') . '.';
            }

            return ActionResultFramework::success(
                ['page.context'],
                $flashMessages,
                [],
                [
                    'company_search_term' => $searchTerm,
                    'company_search_results' => $results,
                    'company_search_meta' => $meta,
                ]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]],
                [],
                [
                    'company_search_term' => $searchTerm,
                    'company_search_results' => [],
                    'company_search_meta' => [
                        'searched' => true,
                        'environment' => $this->companiesHouseEnvironment(),
                    ],
                ]
            );
        }
    }

    private function addCompany(RequestFramework $request): ActionResultFramework
    {
        $companyName = trim((string)$request->input('company_name', ''));
        $companyNumber = trim((string)$request->input('selected_company_number', ''));
        $incorporationDate = trim((string)$request->input('selected_incorporation_date', ''));
        $companiesHouseProfile = $this->decodeProfilePayload((string)$request->input('selected_company_profile_payload', ''));
        $environment = $this->companiesHouseEnvironment();

        if ($companyName === '') {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'Select a Companies House result before adding a company.',
                ]]
            );
        }

        try {
            if ($companiesHouseProfile === null && $companyNumber !== '') {
                $profile = (new \eel_accounts\Service\CompaniesHouseService($environment))->fetchProfileByNumber($companyNumber);
                $companiesHouseProfile = $profile !== [] ? $profile : null;
            }

            $this->ensureSicLookupDataForProfile($companiesHouseProfile);

            if ($incorporationDate === '' && is_array($companiesHouseProfile)) {
                $incorporationDate = trim((string)($companiesHouseProfile['date_of_creation'] ?? ''));
            }

            $repository = new \eel_accounts\Repository\CompanyRepository();
            $existingCompanyId = $repository->findExistingCompanyId($companyName, $companyNumber !== '' ? $companyNumber : null);
            $companyId = $repository->createCompany(
                $companyName,
                $companyNumber !== '' ? $companyNumber : null,
                $incorporationDate !== '' ? $incorporationDate : null,
                $companiesHouseProfile,
                $environment
            );

            $flashMessages = [[
                'type' => 'success',
                'message' => $existingCompanyId > 0
                    ? 'That company already exists in the database, so ' . $companyName . ' has been selected instead.'
                    : 'Company added successfully: ' . $companyName . '.',
            ]];

            if (!(new \eel_accounts\Service\FileCheckService())->ensureCompanyUploadDirectories($companyId)) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => 'The company record was saved, but the upload folders could not be prepared on disk.',
                ];
            }

            if ($companyNumber !== '') {
                try {
                    $ingestionResult = (new \eel_accounts\Service\CompaniesHouseAccountsIngestionService(environment: $environment))
                        ->ingestForCompany($companyId, $companyNumber);

                    if ((int)($ingestionResult['candidate_count'] ?? 0) > 0) {
                        $flashMessages[] = 'Imported '
                            . (int)($ingestionResult['stored_document_count'] ?? 0)
                            . ' Companies House filings including iXBRL information.';

                        $filedPeriodImportResult = (new \eel_accounts\Service\AccountingGuidanceService())
                            ->createPeriodsFromCompaniesHouseFiledPeriods($companyId, $companyNumber);

                        if ((int)($filedPeriodImportResult['created_count'] ?? 0) > 0) {
                            $flashMessages[] = 'Added '
                                . (int)$filedPeriodImportResult['created_count']
                                . ' accounting period'
                                . ((int)$filedPeriodImportResult['created_count'] === 1 ? '' : 's')
                                . ' from imported Companies House filed accounts.';
                        }

                        if ((int)($filedPeriodImportResult['skipped_overlap_count'] ?? 0) > 0) {
                            $flashMessages[] = [
                                'type' => 'error',
                                'message' => (int)$filedPeriodImportResult['skipped_overlap_count']
                                    . ' filed accounting period'
                                    . ((int)$filedPeriodImportResult['skipped_overlap_count'] === 1 ? '' : 's')
                                    . ' were not added because they overlap existing accounting periods.',
                            ];
                        }
                    } else {
                        $flashMessages[] = 'No digital Companies House accounts filings were available to ingest for that company.';
                    }

                    if ((int)($ingestionResult['failed_document_count'] ?? 0) > 0) {
                        $flashMessages[] = [
                            'type' => 'error',
                            'message' => (int)$ingestionResult['failed_document_count']
                                . ' Companies House filing(s) could not be fully ingested. The company was still added or selected successfully.',
                        ];
                    }
                } catch (Throwable $exception) {
                    $flashMessages[] = [
                        'type' => 'error',
                        'message' => 'The company was added or selected successfully, but Companies House filed accounts ingestion failed: '
                            . $exception->getMessage(),
                    ];
                }
            }

            $selectedCompany = $repository->fetchCompanyDetails($companyId);
            if ($selectedCompany === null) {
                throw new RuntimeException('The added company could not be selected.');
            }

            $accountingPeriods = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriods($companyId);
            $accountingPeriodId = (int)($accountingPeriods[0]['id'] ?? 0);
            $selectedCompanyName = trim((string)($selectedCompany['company_name'] ?? $companyName));
            $selectedCompanyNumber = trim((string)($selectedCompany['company_number'] ?? $companyNumber));

            (new \eel_accounts\Service\AccountingContextService())->setPageContext(
                $companyId,
                $selectedCompanyName,
                $selectedCompanyNumber,
                $accountingPeriodId
            );

            return ActionResultFramework::success(
                ['page.context', SiteContextCoordinatorFramework::UI_INVALIDATION_FACT, 'layout.sidebar'],
                $flashMessages,
                ['company_id' => $companyId],
                [
                    'company_id' => $companyId,
                    'company_name' => $selectedCompanyName !== '' ? $selectedCompanyName : null,
                    'company_number' => $selectedCompanyNumber !== '' ? $selectedCompanyNumber : null,
                    'accounting_period_id' => $accountingPeriodId > 0 ? $accountingPeriodId : null,
                    'company_search_results' => [],
                    'company_search_term' => '',
                ]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]],
                [],
                [
                    'company_search_term' => trim((string)$request->input('company_search_term', '')),
                ]
            );
        }
    }

    private function refreshCompany(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'Select a company before refreshing Companies House details.',
                ]]
            );
        }

        try {
            $repository = new \eel_accounts\Repository\CompanyRepository();
            $company = $repository->fetchCompanyDetails($companyId);

            if ($company === null) {
                throw new RuntimeException('The selected company could not be loaded.');
            }

            $companyNumber = strtoupper(trim((string)($company['company_number'] ?? '')));

            if ($companyNumber === '') {
                return new ActionResultFramework(
                    false,
                    ['page.context'],
                    [[
                        'type' => 'error',
                        'message' => 'This company does not have a Companies House number to refresh.',
                    ]]
                );
            }

            $environment = $this->companiesHouseEnvironment();
            $documentRepository = new \eel_accounts\Repository\CompaniesHouseDocumentRepository();
            $storedDocumentIdsBeforeRefresh = $documentRepository->fetchStoredDocumentIds($companyId, $companyNumber);
            $profile = (new \eel_accounts\Service\CompaniesHouseService($environment))->fetchProfileByNumber($companyNumber);

            if ($profile === []) {
                return new ActionResultFramework(
                    false,
                    ['page.context'],
                    [[
                        'type' => 'error',
                        'message' => 'Companies House did not return a company profile for that number.',
                    ]]
                );
            }

            $this->ensureSicLookupDataForProfile($profile);

            $repository->createCompany(
                trim((string)($profile['company_name'] ?? $company['company_name'] ?? '')),
                $companyNumber,
                trim((string)($profile['date_of_creation'] ?? $company['incorporation_date'] ?? '')) ?: null,
                $profile,
                $environment
            );

            $flashMessages = [[
                'type' => 'success',
                'message' => 'Stored Companies House profile refreshed successfully.',
            ]];

            try {
                $ingestionResult = (new \eel_accounts\Service\CompaniesHouseAccountsIngestionService(environment: $environment))
                    ->ingestForCompany($companyId, $companyNumber);
                $storedDocumentIdsAfterRefresh = $documentRepository->fetchStoredDocumentIds($companyId, $companyNumber);
                $newDocumentCount = count(array_diff($storedDocumentIdsAfterRefresh, $storedDocumentIdsBeforeRefresh));

                if ((int)($ingestionResult['candidate_count'] ?? 0) > 0) {
                    $refreshMessage = 'Checked Companies House filing history and refreshed '
                        . (int)($ingestionResult['stored_document_count'] ?? 0)
                        . ' digital accounts filing'
                        . ((int)($ingestionResult['stored_document_count'] ?? 0) === 1 ? '' : 's')
                        . '.';

                    if ($newDocumentCount > 0) {
                        $refreshMessage .= ' ' . $newDocumentCount
                            . ' new filing'
                            . ($newDocumentCount === 1 ? ' was' : 's were')
                            . ' added.';
                    } else {
                        $refreshMessage .= ' No new digital accounts filings were found.';
                    }

                    $flashMessages[] = $refreshMessage;

                    $filedPeriodImportResult = (new \eel_accounts\Service\AccountingGuidanceService())
                        ->createPeriodsFromCompaniesHouseFiledPeriods($companyId, $companyNumber);

                    if ((int)($filedPeriodImportResult['created_count'] ?? 0) > 0) {
                        $flashMessages[] = 'Added '
                            . (int)$filedPeriodImportResult['created_count']
                            . ' accounting period'
                            . ((int)$filedPeriodImportResult['created_count'] === 1 ? '' : 's')
                            . ' from imported Companies House filed accounts.';
                    }

                    if ((int)($filedPeriodImportResult['skipped_overlap_count'] ?? 0) > 0) {
                        $flashMessages[] = [
                            'type' => 'error',
                            'message' => (int)$filedPeriodImportResult['skipped_overlap_count']
                                . ' filed accounting period'
                                . ((int)$filedPeriodImportResult['skipped_overlap_count'] === 1 ? '' : 's')
                                . ' were not added because they overlap existing accounting periods.',
                        ];
                    }
                } else {
                    $flashMessages[] = 'Checked Companies House filing history; no digital accounts filings were available for this company.';
                }

                if ((int)($ingestionResult['failed_document_count'] ?? 0) > 0) {
                    $flashMessages[] = [
                        'type' => 'error',
                        'message' => (int)($ingestionResult['failed_document_count'] ?? 0)
                            . ' Companies House filing(s) could not be fully refreshed. Stored company details were still updated successfully.',
                    ];
                }
            } catch (Throwable $exception) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => 'The stored Companies House profile was refreshed, but filing history refresh failed: '
                        . $exception->getMessage(),
                ];
            }

            return ActionResultFramework::success(
                ['page.context', SiteContextCoordinatorFramework::UI_INVALIDATION_FACT],
                $flashMessages,
                ['company_id' => $companyId],
                ['company_id' => $companyId]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]
            );
        }
    }

    private function refreshSic(RequestFramework $request): ActionResultFramework
    {
        try {
            $result = (new \eel_accounts\Service\CompaniesHouseSICService())->refreshLookupData();

            return ActionResultFramework::success(
                ['page.context'],
                [[
                    'type' => 'success',
                    'message' => 'SIC lookup data refreshed successfully: '
                        . (int)($result['section_count'] ?? 0)
                        . ' section'
                        . ((int)($result['section_count'] ?? 0) === 1 ? '' : 's')
                        . ' and '
                        . (int)($result['sic_code_count'] ?? 0)
                        . ' SIC code'
                        . ((int)($result['sic_code_count'] ?? 0) === 1 ? '' : 's')
                        . '.',
                ]]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'SIC lookup refresh failed: ' . $exception->getMessage(),
                ]]
            );
        }
    }

    private function saveCompany(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'Select a company before saving company settings.',
                ]]
            );
        }

        try {
            $repository = new \eel_accounts\Repository\CompanyRepository();
            $company = $repository->fetchCompanyDetails($companyId);

            if ($company === null) {
                throw new RuntimeException('The selected company could not be loaded.');
            }

            $settingsStore = new \eel_accounts\Store\CompanySettingsStore($companyId);
            $settingsService = new \eel_accounts\Service\CompanySettingsService();

            $settingsService->saveCompanySection($settingsStore, [
                'company_id' => (string)$companyId,
                'company_name' => (string)($company['company_name'] ?? ''),
                'companies_house_number' => (string)($company['company_number'] ?? ''),
                'utr' => trim((string)$request->post('utr', '')),
                'associated_company_count' => trim((string)$request->post('associated_company_count', '0')),
                'default_currency' => trim((string)$request->post('default_currency', 'GBP')),
                'date_format' => trim((string)$request->post('date_format', 'd/m/Y')),
                'is_vat_registered' => !empty($company['is_vat_registered']),
                'vat_country_code' => (string)($company['vat_country_code'] ?? ''),
                'vat_number' => (string)($company['vat_number'] ?? ''),
                'vat_validation_status' => (string)($company['vat_validation_status'] ?? ''),
                'vat_validated_at' => (string)($company['vat_validated_at'] ?? ''),
                'vat_validation_source' => (string)($company['vat_validation_source'] ?? ''),
                'vat_validation_name' => (string)($company['vat_validation_name'] ?? ''),
                'vat_validation_address_line1' => (string)($company['vat_validation_address_line1'] ?? ''),
                'vat_validation_postcode' => (string)($company['vat_validation_postcode'] ?? ''),
                'vat_validation_country_code' => (string)($company['vat_validation_country_code'] ?? ''),
                'vat_last_error' => (string)($company['vat_last_error'] ?? ''),
            ]);

            return ActionResultFramework::success(
                ['page.context'],
                [[
                    'type' => 'success',
                    'message' => 'Company settings saved.',
                ]]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]
            );
        }
    }

    private function saveImportReview(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'Select a company before saving import and review settings.',
                ]]
            );
        }

        try {
            $settingsStore = new \eel_accounts\Store\CompanySettingsStore($companyId);
            $settingsService = new \eel_accounts\Service\CompanySettingsService();

            $settingsService->saveImportReviewSection($settingsStore, [
                'enable_duplicate_file_check' => $this->checkboxValue($request, 'enable_duplicate_file_check'),
                'enable_duplicate_row_check' => $this->checkboxValue($request, 'enable_duplicate_row_check'),
                'auto_create_rule_prompt' => $this->checkboxValue($request, 'auto_create_rule_prompt'),
                'lock_posted_periods' => $this->checkboxValue($request, 'lock_posted_periods'),
            ]);

            return ActionResultFramework::success(
                ['page.context'],
                [[
                    'type' => 'success',
                    'message' => 'Import and review settings saved.',
                ]]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]
            );
        }
    }

    private function clearImportedAccountingData(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'A company must be selected before imported accounting data can be cleared.',
                ]]
            );
        }

        try {
            $company = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId);
            if ($company === null) {
                throw new RuntimeException('The selected company could not be loaded.');
            }

            $result = (new \eel_accounts\Service\CompanyDataResetService())->clearImportedAccountingData(
                $companyId,
                trim((string)$request->post('company_clear_confirmation', '')),
                'companies_page'
            );

            $flashMessages = array_map(
                static fn(string $message): array => ['type' => 'error', 'message' => $message],
                array_map(static fn(mixed $message): string => (string)$message, (array)($result['errors'] ?? []))
            );

            if (!empty($result['success'])) {
                $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
                $flashMessages[] = [
                    'type' => 'success',
                    'message' => 'Imported accounting data cleared for company ' . (string)($company['company_number'] ?? '') . '.',
                ];
                $flashMessages[] = [
                    'type' => 'success',
                    'message' => sprintf(
                        'Deleted %d statement field mappings, %d expense claim lines, %d expense claim payment links, %d expense claimants, %d expense claims, %d journal lines, %d journals, %d statement import rows, %d statement uploads, %d transaction category audit rows, and %d transactions.',
                        (int)($counts['statement_field_mappings'] ?? 0),
                        (int)($counts['expense_claim_lines'] ?? 0),
                        (int)($counts['expense_claim_payment_links'] ?? 0),
                        (int)($counts['expense_claimants'] ?? 0),
                        (int)($counts['expense_claims'] ?? 0),
                        (int)($counts['journal_lines'] ?? 0),
                        (int)($counts['journals'] ?? 0),
                        (int)($counts['statement_import_rows'] ?? 0),
                        (int)($counts['statement_uploads'] ?? 0),
                        (int)($counts['transaction_category_audit'] ?? 0),
                        (int)($counts['transactions'] ?? 0)
                    ),
                ];
            }

            return new ActionResultFramework(
                !empty($result['success']),
                ['page.context'],
                $flashMessages
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]
            );
        }
    }

    private function deleteOrphanedTransferredFiles(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'A company must be selected before orphaned transferred files can be deleted.',
                ]]
            );
        }

        try {
            $company = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId);
            if ($company === null) {
                throw new RuntimeException('The selected company could not be loaded.');
            }

            $cleanupService = new \eel_accounts\Service\CompanyOrphanedFileCleanupService();
            $result = $cleanupService->deleteOrphanedTransferredFiles($companyId, 'companies_page');
            $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
            $deletedCount = (int)($counts['statement_files_deleted'] ?? 0)
                + (int)($counts['transaction_receipts_deleted'] ?? 0)
                + (int)($counts['expense_receipts_deleted'] ?? 0);

            $flashMessages = array_map(
                static fn(string $message): array => ['type' => 'error', 'message' => $message],
                array_map(static fn(mixed $message): string => (string)$message, (array)($result['errors'] ?? []))
            );

            if ($deletedCount > 0) {
                $flashMessages[] = [
                    'type' => 'success',
                    'message' => sprintf(
                        'Deleted %d orphaned transferred file%s for company %s.',
                        $deletedCount,
                        $deletedCount === 1 ? '' : 's',
                        (string)($company['company_number'] ?? '')
                    ),
                ];
            } elseif (($result['errors'] ?? []) === []) {
                $flashMessages[] = [
                    'type' => 'success',
                    'message' => 'No orphaned transferred files were found for the selected company.',
                ];
            }

            if ($deletedCount > 0 || ($result['errors'] ?? []) !== []) {
                $flashMessages[] = [
                    'type' => empty($result['errors']) ? 'success' : 'error',
                    'message' => sprintf(
                        'Statement CSVs deleted: %d. Downloaded transaction receipts deleted: %d. Expense receipt uploads deleted: %d.',
                        (int)($counts['statement_files_deleted'] ?? 0),
                        (int)($counts['transaction_receipts_deleted'] ?? 0),
                        (int)($counts['expense_receipts_deleted'] ?? 0)
                    ),
                ];
            }

            return new ActionResultFramework(
                empty($result['errors']),
                ['page.context'],
                $flashMessages
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]
            );
        }
    }

    private function deleteCompany(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();
        $deleteConfirmSwitch = $this->checkboxValue($request, 'delete_company_confirm');
        $deleteConfirmValue = trim((string)$request->post('delete_company_confirm_value', ''));

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'A company must be selected before deletion.',
                ]]
            );
        }

        try {
            $repository = new \eel_accounts\Repository\CompanyRepository();
            $company = $repository->fetchCompanyDetails($companyId);
            if ($company === null) {
                throw new RuntimeException('The selected company could not be loaded.');
            }

            $expectedDeleteValue = trim((string)($company['company_number'] ?? ''));
            $flashMessages = [];

            if (!$deleteConfirmSwitch) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => 'Tick the delete confirmation switch before deleting the company.',
                ];
            }

            if ($expectedDeleteValue === '' || $deleteConfirmValue !== $expectedDeleteValue) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => 'Enter the exact Companies House number to confirm company deletion.',
                ];
            }

            if ($flashMessages !== []) {
                return new ActionResultFramework(false, ['page.context'], $flashMessages);
            }

            $deletedCompanyName = trim((string)($company['company_name'] ?? ''));
            $deletedCompanyNumber = (string)($company['company_number'] ?? '');
            $deleteResult = $repository->deleteCompany($companyId);
            $autoNominalCleanup = is_array($deleteResult['auto_nominals'] ?? null) ? $deleteResult['auto_nominals'] : [];
            $deletedAutoNominalCount = (int)($autoNominalCleanup['deleted'] ?? 0);
            $skippedAutoNominalCount = (int)($autoNominalCleanup['skipped'] ?? 0);
            $autoNominalMessage = 'Manual/shared nominals were retained; '
                . $deletedAutoNominalCount
                . ' auto-created company account nominal'
                . ($deletedAutoNominalCount === 1 ? '' : 's')
                . ' removed'
                . ($skippedAutoNominalCount > 0
                    ? ', with ' . $skippedAutoNominalCount . ' still referenced and left in place'
                    : '')
                . '.';

            $remainingCompanies = $repository->fetchCompanies();
            $nextCompany = is_array($remainingCompanies[0] ?? null) ? $remainingCompanies[0] : null;
            $nextCompanyId = (int)($nextCompany['id'] ?? 0);
            $nextCompanyName = trim((string)($nextCompany['company_name'] ?? ''));
            $nextCompanyNumber = trim((string)($nextCompany['company_number'] ?? ''));
            $nextAccountingPeriodId = 0;

            if ($nextCompanyId > 0) {
                $firstAccountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriods($nextCompanyId);
                $nextAccountingPeriodId = (int)($firstAccountingPeriod[0]['id'] ?? 0);
            }

            (new \eel_accounts\Service\AccountingContextService())->setPageContext(
                $nextCompanyId,
                $nextCompanyName,
                $nextCompanyNumber,
                $nextAccountingPeriodId
            );

            return ActionResultFramework::success(
                ['page.context', SiteContextCoordinatorFramework::UI_INVALIDATION_FACT, 'layout.sidebar', 'settings_setup_health'],
                [[
                    'type' => 'success',
                    'message' => 'Company deleted successfully: ' . ($deletedCompanyName !== '' ? $deletedCompanyName : $deletedCompanyNumber) . '. All company-linked data, including stored Companies House reference documents, has been removed. ' . $autoNominalMessage,
                ]],
                [],
                [
                    'company_id' => $nextCompanyId > 0 ? $nextCompanyId : null,
                    'company_name' => $nextCompanyName !== '' ? $nextCompanyName : null,
                    'company_number' => $nextCompanyNumber !== '' ? $nextCompanyNumber : null,
                    'accounting_period_id' => $nextAccountingPeriodId > 0 ? $nextAccountingPeriodId : null,
                    'company_search_results' => [],
                    'company_search_term' => '',
                ]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]
            );
        }
    }

    private function saveAccountingPeriod(RequestFramework $request, bool $isCreate): ActionResultFramework
    {
        $companyId = (new \eel_accounts\Service\AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'Select a company before saving an accounting period.',
                ]]
            );
        }

        $requestedAccountingPeriodId = max(0, (int)$request->input('accounting_period_id', 0));
        $financialPeriodLabel = trim((string)$request->post('financial_period_label', ''));
        $periodStart = trim((string)$request->post('period_start', ''));
        $periodEnd = trim((string)$request->post('period_end', ''));

        $errors = $this->validateAccountingPeriodPayload($companyId, $requestedAccountingPeriodId, $periodStart, $periodEnd, $isCreate);
        if ($errors !== []) {
            return new ActionResultFramework(false, ['page.context'], array_map(
                static fn(string $message): array => ['type' => 'error', 'message' => $message],
                $errors
            ));
        }

        try {
            $companyRepository = new \eel_accounts\Repository\CompanyRepository();
            $company = $companyRepository->fetchCompanyDetails($companyId);

            if ($company === null) {
                throw new RuntimeException('The selected company could not be loaded.');
            }

            $settingsStore = new \eel_accounts\Store\CompanySettingsStore($companyId);
            $settingsService = new \eel_accounts\Service\CompanySettingsService();
            $settings = [
                'company_id' => (string)$companyId,
                'accounting_period_id' => $isCreate ? '' : (string)$requestedAccountingPeriodId,
                'financial_period_label' => $financialPeriodLabel !== ''
                    ? $financialPeriodLabel
                    : \eel_accounts\Service\TaxPeriodService::accountingPeriodLabel($periodStart, $periodEnd),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ];

            $settingsService->saveAccountingSection($settingsStore, $settings);

            $savedAccountingPeriodId = max(0, (int)($settings['accounting_period_id'] ?? 0));
            if ($savedAccountingPeriodId <= 0) {
                throw new RuntimeException('The accounting period could not be saved.');
            }

            (new \eel_accounts\Service\AccountingContextService())->setPageContext(
                $companyId,
                trim((string)($company['company_name'] ?? '')),
                trim((string)($company['company_number'] ?? '')),
                $savedAccountingPeriodId
            );

            return ActionResultFramework::success(
                ['page.context', SiteContextCoordinatorFramework::UI_INVALIDATION_FACT],
                [[
                    'type' => 'success',
                    'message' => $isCreate ? 'Accounting period added.' : 'Accounting period saved.',
                ]],
                [],
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $savedAccountingPeriodId,
                ]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]
            );
        }
    }

    private function validateAccountingPeriodPayload(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd,
        bool $isCreate
    ): array {
        $errors = [];

        if ($periodStart === '') {
            $errors[] = 'Enter an accounting period start date.';
        }

        if ($periodEnd === '') {
            $errors[] = 'Enter an accounting period end date.';
        }

        if ($errors !== []) {
            return $errors;
        }

        if (!$this->isValidIsoDate($periodStart) || !$this->isValidIsoDate($periodEnd)) {
            $errors[] = 'Accounting period dates must use the YYYY-MM-DD format.';
            return $errors;
        }

        if ($periodStart > $periodEnd) {
            $errors[] = 'The accounting period start date must be on or before the end date.';
            return $errors;
        }

        if (!$isCreate && $accountingPeriodId <= 0) {
            $errors[] = 'Select an existing accounting period before saving changes.';
            return $errors;
        }

        $repository = new \eel_accounts\Repository\AccountingPeriodRepository();
        $periodId = $isCreate ? 0 : $accountingPeriodId;

        if (!$isCreate && $repository->fetchAccountingPeriod($companyId, $accountingPeriodId) === null) {
            $errors[] = 'The selected accounting period could not be loaded.';
            return $errors;
        }

        $errors = array_merge($errors, $repository->validateOverlap($companyId, $periodId, $periodStart, $periodEnd));
        $errors = array_merge($errors, $repository->validateSequence($companyId, $periodId, $periodStart, $periodEnd));

        return $errors;
    }

    private function isValidIsoDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function searchCompanies(string $searchTerm): array
    {
        $environment = $this->companiesHouseEnvironment();
        $service = new \eel_accounts\Service\CompaniesHouseService($environment);
        $results = [];
        $mode = ctype_digit($searchTerm) ? 'number' : 'term';

        if ($mode === 'number') {
            $profile = $service->fetchProfileByNumber($searchTerm);

            if ($profile !== []) {
                $results[] = $this->mapProfileResult($profile, 'profile');
            }
        }

        if ($results === []) {
            $response = $service->request('/search/companies', ['q' => $searchTerm, 'items_per_page' => 10]);

            foreach ((array)($response['data']['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $results[] = $this->mapSearchResult($item);
            }
        }

        return [
            'results' => $results,
            'meta' => [
                'searched' => true,
                'mode' => $mode,
                'environment' => $environment,
            ],
        ];
    }

    private function mapProfileResult(array $profile, string $source): array
    {
        return [
            'company_name' => trim((string)($profile['company_name'] ?? '')),
            'company_number' => trim((string)($profile['company_number'] ?? '')),
            'company_status' => trim((string)($profile['company_status'] ?? '')),
            'incorporation_date' => trim((string)($profile['date_of_creation'] ?? '')),
            'source' => $source,
            'profile_payload' => $this->encodeProfilePayload($profile),
        ];
    }

    private function mapSearchResult(array $item): array
    {
        return [
            'company_name' => trim((string)($item['title'] ?? $item['company_name'] ?? '')),
            'company_number' => trim((string)($item['company_number'] ?? '')),
            'company_status' => trim((string)($item['company_status'] ?? '')),
            'incorporation_date' => trim((string)($item['date_of_creation'] ?? '')),
            'source' => 'search',
            'profile_payload' => '',
        ];
    }

    private function encodeProfilePayload(array $profile): string
    {
        $payload = json_encode($profile, JSON_UNESCAPED_SLASHES);

        return $payload === false ? '' : $payload;
    }

    private function decodeProfilePayload(string $payload): ?array
    {
        $payload = trim($payload);

        if ($payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normaliseSearchTerm(string $searchTerm): string
    {
        return preg_replace('/\s+/', ' ', trim($searchTerm)) ?? '';
    }

    private function companiesHouseEnvironment(): string
    {
        return \eel_accounts\Store\AccountingConfigurationStore::companiesHouseMode();
    }

    private function ensureSicLookupDataForProfile(?array $profile): void
    {
        if (!is_array($profile) || !is_array($profile['sic_codes'] ?? null) || $profile['sic_codes'] === []) {
            return;
        }

        (new \eel_accounts\Service\CompaniesHouseSICService())->ensureLookupDataAvailable();
    }

    private function checkboxValue(RequestFramework $request, string $field): bool
    {
        return in_array((string)$request->post($field, ''), ['1', 'true', 'on', 'yes'], true);
    }
}
