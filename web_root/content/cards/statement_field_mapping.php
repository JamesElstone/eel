<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

/**
 * Reusable statement CSV field mapping card.
 *
 * Usage modes:
 * - uploads page: set context uploads.id, renders the selected upload mapping form.
 * - banking page: set context field_mapping.account_id, renders the latest mapping for that account.
 *
 * Expected action support:
 * - UploadsAction: intent save_account_mapping, stage_account_upload
 * - BankingAction: intent save_account_mapping, or route Banking to the same StatementUploadService::saveFieldMapping payload
 */
final class _statement_field_mappingCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'statement_field_mapping';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'activeCompanyAccounts',
                'service' => \eel_accounts\Service\CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':company.id',
                    'activeOnly' => true,
                ],
            ],
            [
                'key' => 'selected_upload_preview',
                'service' => \eel_accounts\Service\StatementUploadService::class,
                'method' => 'fetchUploadPreview',
                'params' => [
                    'companyId' => ':company.id',
                    'uploadId' => ':uploads.id',
                ],
            ],
            [
                'key' => 'selected_upload_mapping_status',
                'service' => \eel_accounts\Service\StatementUploadService::class,
                'method' => 'describeUploadAccountMappingStatus',
                'params' => [
                    'companyId' => ':company.id',
                    'uploadId' => ':uploads.id',
                ],
            ],
            [
                'key' => 'account_mapping_preview',
                'service' => \eel_accounts\Service\StatementUploadService::class,
                'method' => 'fetchAccountMappingPreview',
                'params' => [
                    'companyId' => ':company.id',
                    'accountId' => ':field_mapping.account_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function helper(array $context): string
    {
        return 'Map bank CSV columns to import fields before staging rows, or maintain the saved mapping for a bank account.';
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $pageId = trim((string)($context['page']['page_id'] ?? ''));
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $activeCompanyAccounts = (array)($context['services']['activeCompanyAccounts'] ?? []);

        $selectedUploadId = (int)($context['uploads']['id'] ?? 0);
        $bankingMappingAccountId = (int)($context['field_mapping']['account_id'] ?? 0);
        $action = $pageId !== '' ? '?page=' . rawurlencode($pageId) : '';
        $cardAction = in_array($pageId, ['source_accounts', 'bank_accounts', 'banking'], true) ? 'Banking' : 'Uploads';

        $mode = $selectedUploadId > 0 || $pageId === 'uploads' ? 'upload' : 'account';
        $preview = $mode === 'upload'
            ? (array)($context['services']['selected_upload_preview'] ?? [])
            : (array)($context['services']['account_mapping_preview'] ?? []);

        if ($preview === []) {
            if ($mode === 'upload') {
                return $this->renderUploadMappingInterface(
                    [],
                    [],
                    ['headers' => [], 'rows' => []],
                    [],
                    [],
                    false,
                    [],
                    $activeCompanyAccounts,
                    0,
                    0,
                    $selectedUploadId,
                    $bankingMappingAccountId,
                    $companyId,
                    $accountingPeriodId,
                    $action,
                    $cardAction
                );
            }

            return $this->renderEmptyState($bankingMappingAccountId, $activeCompanyAccounts, $action, $cardAction);
        }

        $upload = is_array($preview['upload'] ?? null) ? $preview['upload'] : [];
        $mappingRow = is_array($preview['mapping'] ?? null) ? $preview['mapping'] : [];
        $sourceSample = is_array($preview['source_sample'] ?? null) ? $preview['source_sample'] : ['headers' => [], 'rows' => []];
        $sourceHeaders = $this->resolveHeaders($upload, $sourceSample);
        $mappingView = $this->decodeJsonArrayValue((string)($mappingRow['mapping_json'] ?? ''));

        if ($mappingView === []) {
            $mappingView = \eel_accounts\Service\StatementUploadService::autoMapHeaders($sourceHeaders);
        }

        $mappingStatus = $mode === 'upload'
            ? (array)($context['services']['selected_upload_mapping_status'] ?? [])
            : ['has_mapping' => $mappingRow !== [], 'extra_headers' => []];

        $hasConfirmedMapping = !empty($mappingStatus['has_mapping']) || $mappingRow !== [];
        $extraHeaders = array_values((array)($mappingStatus['extra_headers'] ?? []));
        $uploadId = (int)($upload['id'] ?? $selectedUploadId);
        $accountId = (int)($upload['account_id'] ?? $bankingMappingAccountId);

        return $this->renderUploadMappingInterface(
            $upload,
            $mappingRow,
            $sourceSample,
            $sourceHeaders,
            $mappingView,
            $hasConfirmedMapping,
            $extraHeaders,
            $activeCompanyAccounts,
            $accountId,
            $uploadId,
            $selectedUploadId,
            $bankingMappingAccountId,
            $companyId,
            $accountingPeriodId,
            $action,
            $cardAction,
            $mode,
        );
    }

    private function renderUploadMappingInterface(
        array $upload,
        array $mappingRow,
        array $sourceSample,
        array $sourceHeaders,
        array $mappingView,
        bool $hasConfirmedMapping,
        array $extraHeaders,
        array $activeCompanyAccounts,
        int $accountId,
        int $uploadId,
        int $selectedUploadId,
        int $bankingMappingAccountId,
        int $companyId,
        int $accountingPeriodId,
        string $action,
        string $cardAction,
        string $mode = 'upload'
    ): string {
        $summaryHtml = $upload !== []
            ? $this->renderSummary(
                $mode,
                $upload,
                $mappingRow,
                $hasConfirmedMapping,
                $extraHeaders,
                $activeCompanyAccounts,
                $accountId,
                $action,
                $cardAction
            )
            : '';
        $sampleHtml = $this->renderSourceSample($sourceSample);
        $mappingDisabled = $mode === 'upload' && ($uploadId <= 0 || $accountId <= 0);
        $committedMappingProtectedFields = $mode === 'account' && (int)($upload['rows_committed'] ?? 0) > 0
            ? \eel_accounts\Service\StatementUploadService::committedMappingProtectedFields()
            : [];
        $mappingFieldsHtml = $this->renderMappingFields($mappingView, $sourceHeaders, $mode, $mappingDisabled, $committedMappingProtectedFields);
        $accountSelectHtml = $mode === 'upload' ? $this->renderAccountSelector($activeCompanyAccounts, $accountId, $mode) : '';
        $buttonsHtml = $this->renderButtons($mode, $hasConfirmedMapping, $uploadId, $companyId, $accountingPeriodId, $accountId);

        return '
            <div class="stack">
                ' . $summaryHtml . '
                ' . $sampleHtml . '
                <form method="post" action="' . HelperFramework::escape($action) . '" data-ajax="true">
                    <input type="hidden" name="card_action" value="' . HelperFramework::escape($cardAction) . '">
                    <input type="hidden" name="intent" value="save_account_mapping">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="upload_id" value="' . $uploadId . '">
                    <input type="hidden" name="mapping_account_id" value="' . $bankingMappingAccountId . '">
                        ' . $accountSelectHtml . '
                    <div class="form-grid flow statement-mapping-fields">
                        ' . $mappingFieldsHtml . '
                    </div>
                </form>
                ' . $buttonsHtml . '
            </div>
        ';
    }

    private function renderEmptyState(
        int $bankingMappingAccountId,
        array $accounts,
        string $action,
        string $cardAction
    ): string
    {
        $accountSwitcherHtml = $this->renderAccountMappingSwitcher($accounts, $bankingMappingAccountId, $action, $cardAction);

        if ($bankingMappingAccountId <= 0) {
            return '<div class="statement-mapping-summary">
                <div class="statement-mapping-summary-copy"></div>
                ' . $accountSwitcherHtml . '
            </div>';
        }

        return '<div class="statement-mapping-summary">
            <div class="helper statement-mapping-summary-copy">Upload a CSV for this account first. This card will then let you maintain the saved mapping for that account.</div>
            ' . $accountSwitcherHtml . '
        </div>';
    }

    private function renderSummary(
        string $mode,
        array $upload,
        array $mappingRow,
        bool $hasConfirmedMapping,
        array $extraHeaders,
        array $accounts,
        int $selectedAccountId,
        string $action,
        string $cardAction
    ): string {
        $statusClass = $hasConfirmedMapping && $extraHeaders === [] ? 'success' : 'warning';
        $statusText = $hasConfirmedMapping && $extraHeaders === [] ? 'Mapping available' : 'Review mapping';

        $message = $mode === 'upload'
            ? 'Selected upload: <strong>' . HelperFramework::escape((string)($upload['original_filename'] ?? '')) . '</strong>.'
            : 'Saved account mapping source: <strong>' . HelperFramework::escape((string)($upload['original_filename'] ?? '')) . '</strong>.';

        $message .= '<br>Field mappings apply to: <strong>' . HelperFramework::escape((string)($upload['account_name'] ?? 'No account selected')) . '</strong>.';
        $mappingLabel = $this->mappingStatusLabel($mappingRow);

        if ($mappingLabel !== '') {
            $message .= '<br>Mapping status: <strong>' . HelperFramework::escape($mappingLabel) . '</strong>.';
        }

        if ($extraHeaders !== []) {
            $message .= '<br>Extra columns found in this file: <strong>'
                . HelperFramework::escape(implode(', ', array_map(static fn($header): string => (string)$header, $extraHeaders)))
                . '</strong>.';
        }

        $accountSwitcherHtml = $mode === 'account'
            ? $this->renderAccountMappingSwitcher($accounts, $selectedAccountId, $action, $cardAction)
            : '';

        return '<div class="statement-mapping-summary">
            <div class="helper statement-mapping-summary-copy">
                <span class="badge ' . HelperFramework::escape($statusClass) . '">' . HelperFramework::escape($statusText) . '</span><br>
                ' . $message . '
            </div>
            ' . $accountSwitcherHtml . '
        </div>';
    }

    private function mappingStatusLabel(array $mappingRow): string
    {
        if ($mappingRow === []) {
            return '';
        }

        if (trim((string)($mappingRow['confirmed_at'] ?? '')) !== '') {
            return 'Mapping Confirmed';
        }

        return match (trim((string)($mappingRow['mapping_origin'] ?? ''))) {
            'auto' => 'Auto Mapping Applied',
            'reused' => 'Account Mapping Reused',
            'manual' => 'Mapping Confirmed',
            default => '',
        };
    }

    private function renderSourceSample(array $sourceSample): string
    {
        $headers = (array)($sourceSample['headers'] ?? []);
        $rows = (array)($sourceSample['rows'] ?? []);

        if ($headers === []) {
            return '';
        }

        $thead = '';
        foreach ($headers as $header) {
            $thead .= '<th>' . HelperFramework::escape((string)$header) . '</th>';
        }

        $tbody = '';
        foreach ($rows as $row) {
            $tbody .= '<tr>';
            foreach ((array)$row as $value) {
                $tbody .= '<td>' . HelperFramework::escape((string)$value) . '</td>';
            }
            $tbody .= '</tr>';
        }

        if ($rows === []) {
            $tbody .= '<tr><td colspan="' . count($headers) . '">No example rows are available for this upload yet.</td></tr>';
        }

        return '<div>
            <div class="helper">CSV headings and first two rows from the uploaded file:</div>
            <div>
                <table>
                    <thead><tr>' . $thead . '</tr></thead>
                    <tbody>' . $tbody . '</tbody>
                </table>
            </div>
        </div>';
    }

    private function renderAccountSelector(array $accounts, int $selectedAccountId, string $mode): string
    {
        $placeholderSelected = $selectedAccountId <= 0 ? ' selected' : '';
        $options = '<option value="" disabled' . $placeholderSelected . '></option>';

        foreach ($accounts as $account) {
            $accountId = (int)($account['id'] ?? 0);
            $selected = $selectedAccountId === $accountId ? ' selected' : '';
            $type = \eel_accounts\Service\CompanyAccountService::accountTypes()[(string)($account['account_type'] ?? '')]
                ?? ucfirst((string)($account['account_type'] ?? ''));

            $options .= '<option value="' . $accountId . '"' . $selected . '>'
                . HelperFramework::escape((string)($account['account_name'] ?? ''))
                . ' (' . HelperFramework::escape($type) . ')</option>';
        }

        $idPrefix = $mode === 'upload' ? 'upload' : 'account';

        return '<div class="form-row">
            <label for="' . HelperFramework::escape($idPrefix) . '_mapping_account_id">Default Account to use</label>
            <select class="select" id="' . HelperFramework::escape($idPrefix) . '_mapping_account_id" name="account_id" required data-no-submit-on-change="true" data-statement-mapping-account-selector>' . $options . '</select>
        </div>';
    }

    private function renderAccountMappingSwitcher(array $accounts, int $selectedAccountId, string $action, string $cardAction): string
    {
        if ($accounts === []) {
            return '';
        }

        $placeholderSelected = $selectedAccountId <= 0 ? ' selected' : '';
        $options = '<option value="" disabled' . $placeholderSelected . '></option>';

        foreach ($accounts as $account) {
            $accountId = (int)($account['id'] ?? 0);
            $selected = $selectedAccountId === $accountId ? ' selected' : '';

            $options .= '<option value="' . $accountId . '"' . $selected . '>'
                . HelperFramework::escape((string)($account['account_name'] ?? ''))
                . '</option>';
        }

        return '<form method="post" action="' . HelperFramework::escape($action) . '" data-ajax="true" class="statement-mapping-account-switcher">
            <input type="hidden" name="card_action" value="' . HelperFramework::escape($cardAction) . '">
            <input type="hidden" name="intent" value="select_field_mapping">
            <input type="hidden" name="show_card" value="statement_field_mapping">
            <label class="sr-only" for="statement_mapping_account_switcher">Account mapping source</label>
            <select class="select" id="statement_mapping_account_switcher" name="field_mapping_account_id" data-submit-on-change="true">' . $options . '</select>
        </form>';
    }

    private function renderMappingFields(array $mappingView, array $sourceHeaders, string $mode, bool $disabled = false, array $protectedFields = []): string
    {
        $html = '';
        $idPrefix = $mode === 'upload' ? 'upload_mapping' : 'account_mapping';
        $protectedFields = array_fill_keys(array_map('strval', $protectedFields), true);

        foreach (\eel_accounts\Service\StatementUploadService::fieldDefinitions() as $fieldName => $definition) {
            $selectedHeader = $this->selectedMappingValue($fieldName, $mappingView[$fieldName] ?? null);
            $options = '<option value="">Not mapped</option>';
            $fieldDisabled = $disabled || isset($protectedFields[$fieldName]);
            $disabledAttributes = '';

            if ($fieldDisabled) {
                $disabledAttributes .= ' disabled';
            }

            if ($disabled) {
                $disabledAttributes .= ' data-statement-mapping-requires-account';
            }

            if (isset($protectedFields[$fieldName])) {
                $disabledAttributes .= ' data-statement-mapping-protected-field';
            } elseif (!$disabled) {
                $disabledAttributes .= ' data-statement-mapping-requires-account';
            }

            if ($fieldName === 'currency') {
                $gbpValue = \eel_accounts\Service\StatementUploadService::CURRENCY_DEFAULT_OPTION_GBP;
                $options .= '<option value="' . HelperFramework::escape($gbpValue) . '"'
                    . ($selectedHeader === $gbpValue ? ' selected' : '')
                    . '>£ GBP</option>';
            }

            foreach ($sourceHeaders as $header) {
                $header = (string)$header;
                $options .= '<option value="' . HelperFramework::escape($header) . '"'
                    . ($selectedHeader === $header ? ' selected' : '')
                    . '>' . HelperFramework::escape($header) . '</option>';
            }

            $html .= '<div class="form-row">
                <label for="' . HelperFramework::escape($idPrefix . '_' . $fieldName) . '">'
                    . HelperFramework::escape((string)($definition['label'] ?? $fieldName))
                    . (!empty($definition['required']) ? ' *' : '')
                    . '</label>
                <select class="select" id="' . HelperFramework::escape($idPrefix . '_' . $fieldName) . '" name="mapping_' . HelperFramework::escape($fieldName) . '"' . $disabledAttributes . '>'
                    . $options
                    . '</select>
            </div>';
        }

        return $html;
    }

    private function renderButtons(
        string $mode,
        bool $hasConfirmedMapping,
        int $uploadId,
        int $companyId,
        int $accountingPeriodId,
        int $accountId
    ): string {
        if ($mode !== 'upload' || $uploadId <= 0 || !$hasConfirmedMapping) {
            return '';
        }

        return '<form method="post" action="?page=uploads" data-ajax="true">
            <input type="hidden" name="card_action" value="Uploads">
            <input type="hidden" name="intent" value="stage_account_upload">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="upload_id" value="' . $uploadId . '">
            <input type="hidden" name="account_id" value="' . $accountId . '">
            <button class="button primary" type="submit" data-show-card="uploads_validate_commit" data-processing-text="Preparing import..." data-processing-state="disabled">Preview And Validate Rows</button>
        </form>';
    }

    private function resolveHeaders(array $upload, array $sourceSample): array
    {
        $headers = $this->decodeJsonArrayValue((string)($upload['source_headers_json'] ?? ''));

        if ($headers !== []) {
            return $headers;
        }

        return array_values((array)($sourceSample['headers'] ?? []));
    }

    private function selectedMappingValue(string $fieldName, mixed $mapping): string
    {
        if (!is_array($mapping)) {
            return '';
        }

        if ($fieldName === 'currency' && array_key_exists('default_value', $mapping)) {
            return \eel_accounts\Service\StatementUploadService::CURRENCY_DEFAULT_OPTION_GBP;
        }

        return (string)($mapping['header'] ?? '');
    }

    private function decodeJsonArrayValue(string $payload): array
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}
