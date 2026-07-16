<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CategorisationRuleService
{
    private ?string $lastSql = null;
    private array $lastParams = [];

    public function __construct() {
    }

    public function fetchRules(int $companyId): array {
        if ($companyId <= 0) {
            return [];
        }

        $stmt = $this->executeQuery($this->fetchRulesSql(), ['company_id' => $companyId]);
        return $stmt->fetchAll();
    }

    public function fetchRule(int $companyId, int $ruleId): ?array {
        if ($companyId <= 0 || $ruleId <= 0) {
            return null;
        }

        $stmt = $this->executeQuery($this->fetchRuleSql(), [
            'company_id' => $companyId,
            'id' => $ruleId,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function fetchSourceCategoryOptions(int $companyId): array {
        return $this->fetchDistinctTransactionTextOptions($companyId, 'source_category');
    }

    public function fetchSourceAccountOptions(int $companyId): array {
        return $this->fetchDistinctTransactionTextOptions($companyId, 'source_account_label');
    }

    public function buildRuleDraftFromTransaction(int $transactionId, int $nominalAccountId): ?array {
        if ($transactionId <= 0 || $nominalAccountId <= 0) {
            return null;
        }

        $stmt = $this->executeQuery(
             'SELECT id,
                    company_id,
                    director_id,
                    description,
                    reference,
                    source_category,
                    source_account_label,
                    is_auto_excluded
             FROM transactions
             WHERE id = :id
             LIMIT 1',
            ['id' => $transactionId]
        );
        $transaction = $stmt->fetch();

        if (!is_array($transaction) || (int)($transaction['is_auto_excluded'] ?? 0) === 1) {
            return null;
        }

        return [
            'transaction_id' => (int)$transaction['id'],
            'company_id' => (int)$transaction['company_id'],
            'priority' => $this->nextPriority((int)$transaction['company_id']),
            'match_field' => 'description',
            'desc_match_type' => 'contains',
            'desc_match_value' => $this->cleanMerchantName((string)($transaction['description'] ?? '')),
            'ref_match_type' => 'none',
            'ref_match_value' => trim((string)($transaction['reference'] ?? '')),
            'source_category_value' => '',
            'source_account_value' => '',
            'nominal_account_id' => $nominalAccountId,
            'director_id' => (int)($transaction['director_id'] ?? 0) ?: null,
            'is_active' => true,
        ];
    }

    public function saveRuleFromTransaction(int $companyId, int $transactionId, int $nominalAccountId): array {
        if ($companyId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a company before creating a categorisation rule.'],
                'rule_id' => null,
                'rule' => null,
            ];
        }

        $draft = $this->buildRuleDraftFromTransaction($transactionId, $nominalAccountId);

        if ($draft === null) {
            return [
                'success' => false,
                'errors' => ['The rule could not be created from the selected transaction.'],
                'rule_id' => null,
                'rule' => null,
            ];
        }

        return $this->saveRule($companyId, $draft);
    }

    public function saveRule(int $companyId, array $input, ?int $ruleId = null): array {
        $normalised = $this->normaliseRuleInput($input);
        if ($ruleId !== null && $ruleId > 0 && empty($normalised['director_id'])) {
            $existingRule = $this->fetchRule($companyId, $ruleId);
            $normalised['director_id'] = (int)($existingRule['director_id'] ?? 0) ?: null;
        }
        $errors = $this->validateRuleInput($companyId, $normalised, $ruleId);

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'rule_id' => $ruleId,
                'rule' => $normalised,
            ];
        }

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        if ($ruleId !== null && $ruleId > 0) {
            $this->executeQuery($this->updateRuleSql(), [
                'is_active' => $normalised['is_active'] ? 1 : 0,
                'priority' => $normalised['priority'],
                'match_field' => $normalised['match_field'],
                'desc_match_type' => $normalised['desc_match_type'],
                'desc_match_value' => $normalised['desc_match_value'],
                'ref_match_type' => $normalised['ref_match_type'],
                'ref_match_value' => $this->nullableString($normalised['ref_match_value']),
                'source_category_value' => $this->nullableString($normalised['source_category_value']),
                'source_account_value' => $this->nullableString($normalised['source_account_value']),
                'nominal_account_id' => $normalised['nominal_account_id'],
                'director_id' => $normalised['director_id'],
                'updated_at' => $now,
                'company_id' => $companyId,
                'id' => $ruleId,
            ]);

            return [
                'success' => true,
                'errors' => [],
                'rule_id' => $ruleId,
                'rule' => $this->fetchRule($companyId, $ruleId),
            ];
        }

        $params = [
            'company_id' => $companyId,
            'is_active' => $normalised['is_active'] ? 1 : 0,
            'priority' => $normalised['priority'],
            'match_field' => $normalised['match_field'],
            'desc_match_type' => $normalised['desc_match_type'],
            'desc_match_value' => $normalised['desc_match_value'],
            'ref_match_type' => $normalised['ref_match_type'],
            'ref_match_value' => $this->nullableString($normalised['ref_match_value']),
            'source_category_value' => $this->nullableString($normalised['source_category_value']),
            'source_account_value' => $this->nullableString($normalised['source_account_value']),
            'nominal_account_id' => $normalised['nominal_account_id'],
            'director_id' => $normalised['director_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->executeQuery($this->insertRuleSql(), $params);

        $createdRuleId = $this->findCreatedRuleId($companyId, $normalised, $now);

        return [
            'success' => true,
            'errors' => [],
            'rule_id' => $createdRuleId,
            'rule' => $createdRuleId !== null ? $this->fetchRule($companyId, $createdRuleId) : null,
        ];
    }

    public function deleteRule(int $companyId, int $ruleId): bool {
        if ($companyId <= 0 || $ruleId <= 0) {
            return false;
        }

        $stmt = $this->executeQuery($this->deleteRuleSql(), [
            'company_id' => $companyId,
            'id' => $ruleId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function setRuleActive(int $companyId, int $ruleId, bool $isActive): bool {
        if ($companyId <= 0 || $ruleId <= 0) {
            return false;
        }
        if ($isActive) {
            $rule = $this->fetchRule($companyId, $ruleId);
            if (
                is_array($rule)
                && (new DirectorLoanAttributionService())->isDirectorLoanNominal($companyId, (int)($rule['nominal_account_id'] ?? 0))
                && (int)($rule['director_id'] ?? 0) <= 0
            ) {
                return false;
            }
        }

        $stmt = $this->executeQuery($this->setRuleActiveSql(), [
            'is_active' => $isActive ? 1 : 0,
            'updated_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'company_id' => $companyId,
            'id' => $ruleId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function blankRuleForm(int $companyId = 0): array {
        return [
            'transaction_id' => null,
            'company_id' => $companyId,
            'priority' => $companyId > 0 ? $this->nextPriority($companyId) : 100,
            'match_field' => 'description',
            'desc_match_type' => 'contains',
            'desc_match_value' => '',
            'ref_match_type' => 'none',
            'ref_match_value' => '',
            'source_category_value' => '',
            'source_account_value' => '',
            'nominal_account_id' => '',
            'director_id' => null,
            'is_active' => true,
        ];
    }

    public function exportRules(int $companyId): array {
        $rules = $this->fetchRules($companyId);

        return [
            'format' => 'transaction_categorisation_rules',
            'version' => 1,
            'company_id' => $companyId,
            'exported_at' => (new \DateTimeImmutable('now'))->format(\DATE_ATOM),
            'rules' => array_map(fn(array $rule): array => $this->exportRuleRow($companyId, $rule), $rules),
        ];
    }

    public function importRules(int $companyId, string $json): array {
        $json = trim($json);

        if ($companyId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a company before importing categorisation rules.'],
                'created' => 0,
                'failed' => 0,
            ];
        }

        if ($json === '') {
            return [
                'success' => false,
                'errors' => ['Paste an exported rules JSON payload before importing.'],
                'created' => 0,
                'failed' => 0,
            ];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => ['The imported rules JSON is invalid: ' . $exception->getMessage()],
                'created' => 0,
                'failed' => 0,
            ];
        }

        $ruleRows = $this->normaliseImportRuleRows($decoded);
        if ($ruleRows === []) {
            return [
                'success' => false,
                'errors' => ['No rules were found in the imported JSON payload.'],
                'created' => 0,
                'failed' => 0,
            ];
        }

        $created = 0;
        $failed = 0;
        $errors = [];

        foreach ($ruleRows as $index => $row) {
            $resolvedNominalAccountId = $this->resolveImportNominalAccountId($companyId, $row);

            if ($resolvedNominalAccountId <= 0) {
                $failed++;
                $errors[] = sprintf(
                    'Rule %d could not be imported because its nominal account could not be matched for this company.',
                    $index + 1
                );
                continue;
            }

            $saveResult = $this->saveRule($companyId, [
                'priority' => $row['priority'] ?? 100,
                'desc_match_type' => $row['desc_match_type'] ?? $row['match_type'] ?? 'contains',
                'desc_match_value' => $row['desc_match_value'] ?? $row['match_value'] ?? '',
                'ref_match_type' => $row['ref_match_type'] ?? 'none',
                'ref_match_value' => $row['ref_match_value'] ?? '',
                'source_category_value' => $row['source_category_value'] ?? '',
                'source_account_value' => $row['source_account_value'] ?? '',
                'nominal_account_id' => $resolvedNominalAccountId,
                'is_active' => !empty($row['is_active']) ? '1' : '0',
            ]);

            if (!empty($saveResult['success'])) {
                $created++;
                continue;
            }

            $failed++;
            foreach (($saveResult['errors'] ?? []) as $error) {
                $errors[] = sprintf('Rule %d: %s', $index + 1, (string)$error);
            }
        }

        return [
            'success' => $created > 0 && $failed === 0,
            'errors' => $errors,
            'created' => $created,
            'failed' => $failed,
        ];
    }

    private function normaliseRuleInput(array $input): array {
        $priority = trim((string)($input['priority'] ?? $input['rule_priority'] ?? '100'));
        $nominalAccountId = trim((string)($input['nominal_account_id'] ?? ''));
        $descMatchType = $input['desc_match_type'] ?? $input['rule_desc_type'] ?? $input['match_type'] ?? 'contains';
        $descMatchValue = $input['desc_match_value'] ?? $input['rule_desc_value'] ?? $input['match_value'] ?? '';
        $refMatchType = $input['ref_match_type'] ?? $input['rule_ref_type'] ?? 'none';
        $refMatchValue = $input['ref_match_value'] ?? $input['rule_ref_value'] ?? '';

        return [
            'priority' => preg_match('/^-?[0-9]+$/', $priority) === 1 ? (int)$priority : 100,
            'match_field' => 'description',
            'desc_match_type' => $this->normaliseMatchType((string)$descMatchType),
            'desc_match_value' => trim((string)$descMatchValue),
            'ref_match_type' => $this->normaliseReferenceMatchType((string)$refMatchType),
            'ref_match_value' => trim((string)$refMatchValue),
            'source_category_value' => trim((string)($input['source_category_value'] ?? '')),
            'source_account_value' => trim((string)($input['source_account_value'] ?? '')),
            'nominal_account_id' => preg_match('/^[1-9][0-9]*$/', $nominalAccountId) === 1 ? (int)$nominalAccountId : 0,
            'director_id' => (int)($input['director_id'] ?? 0) > 0 ? (int)$input['director_id'] : null,
            'is_active' => isset($input['is_active']) ? $this->truthyValue($input['is_active']) : true,
        ];
    }

    private function validateRuleInput(int $companyId, array $input, ?int $ruleId): array {
        $errors = [];

        if ($companyId <= 0) {
            $errors[] = 'Select a company before managing categorisation rules.';
        }

        if ($ruleId !== null && $ruleId > 0 && $this->fetchRule($companyId, $ruleId) === null) {
            $errors[] = 'The selected categorisation rule could not be found.';
        }

        if ($input['priority'] < 1) {
            $errors[] = 'Rule priority must be a positive whole number.';
        }

        if ($input['desc_match_value'] === '') {
            $errors[] = 'Description match text is required.';
        }

        if ($input['ref_match_type'] !== 'none' && $input['ref_match_value'] === '') {
            $errors[] = 'Reference match text is required when reference matching is enabled.';
        }

        if ($input['nominal_account_id'] <= 0) {
            $errors[] = 'Choose the nominal account this rule should assign.';
        } elseif (!$this->nominalBelongsToCompany($companyId, (int)$input['nominal_account_id'])) {
            $errors[] = 'The selected nominal account is not available for the current company.';
        }
        if (
            $input['is_active']
            && (new DirectorLoanAttributionService())->isDirectorLoanNominal($companyId, (int)$input['nominal_account_id'])
        ) {
            if ((int)($input['director_id'] ?? 0) <= 0) {
                $errors[] = 'Attribute the source transaction to a director on the Director Loan Statement before creating an active Director Loan rule.';
            } else {
                try {
                    (new CompanyDirectorService())->requireForCompany($companyId, (int)$input['director_id']);
                } catch (\Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }

        return $errors;
    }

    private function findCreatedRuleId(int $companyId, array $normalised, string $createdAt): ?int {
        $stmt = $this->executeQuery($this->findCreatedRuleIdSql(), [
            'company_id' => $companyId,
            'created_at' => $createdAt,
            'priority' => $normalised['priority'],
            'match_field' => $normalised['match_field'],
            'desc_match_type' => $normalised['desc_match_type'],
            'desc_match_value' => $normalised['desc_match_value'],
            'ref_match_type' => $normalised['ref_match_type'],
            'ref_match_value_match' => $normalised['ref_match_value'],
            'ref_match_value_null' => $normalised['ref_match_value'],
            'nominal_account_id' => $normalised['nominal_account_id'],
            'is_active' => $normalised['is_active'] ? 1 : 0,
            'source_category_value_match' => $normalised['source_category_value'],
            'source_category_value_null' => $normalised['source_category_value'],
            'source_account_value_match' => $normalised['source_account_value'],
            'source_account_value_null' => $normalised['source_account_value'],
        ]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    private function nextPriority(int $companyId): int {
        if ($companyId <= 0) {
            return 100;
        }

        $stmt = $this->executeQuery($this->nextPrioritySql(), ['company_id' => $companyId]);
        $priority = (int)$stmt->fetchColumn();

        return $priority > 0 ? $priority + 10 : 100;
    }

    private function fetchDistinctTransactionTextOptions(int $companyId, string $column): array {
        if ($companyId <= 0 || !in_array($column, ['source_category', 'source_account_label'], true)) {
            return [];
        }

        $stmt = $this->executeQuery(
            'SELECT TRIM(COALESCE(' . $column . ', \'\')) AS value
             FROM transactions
             WHERE company_id = :company_id
               AND TRIM(COALESCE(' . $column . ', \'\')) <> \'\'
             GROUP BY TRIM(COALESCE(' . $column . ', \'\'))
             ORDER BY value ASC',
            ['company_id' => $companyId]
        );

        $options = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = trim((string)($row['value'] ?? ''));
            if ($value !== '') {
                $options[] = $value;
            }
        }

        return $options;
    }

    private function cleanMerchantName(string $description): string {
        $description = preg_replace('/[^A-Za-z0-9&\/ ]+/', ' ', $description) ?? '';
        $description = preg_replace('/\b(?:card|pos|payment|purchase|debit|credit|ref|reference|transfer|faster|bank|online|visa|mastercard)\b/i', ' ', $description) ?? '';
        $description = preg_replace('/\b[0-9]{2,}\b/', ' ', $description) ?? '';
        $description = preg_replace('/\s+/', ' ', trim($description)) ?? '';

        if ($description === '') {
            return '';
        }

        $words = preg_split('/\s+/', $description) ?: [];
        $words = array_slice(array_values(array_filter($words, static fn(string $word): bool => trim($word) !== '')), 0, 4);

        return implode(' ', array_map(
            static fn(string $word): string => function_exists('mb_convert_case')
                ? mb_convert_case($word, MB_CASE_TITLE, 'UTF-8')
                : ucfirst(strtolower($word)),
            $words
        ));
    }

    private function nominalBelongsToCompany(int $companyId, int $nominalAccountId): bool {
        return \InterfaceDB::countWhere('nominal_accounts', [
            'id' => $nominalAccountId,
            'is_active' => 1,
        ]) === 1;
    }

    public function getLastQueryDebug(): ?string {
        if ($this->lastSql === null) {
            return null;
        }

        $params = $this->lastParams === []
            ? '{}'
            : (function_exists('json_encode')
                ? (string)json_encode($this->lastParams, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                : '{}');

        return 'SQL: ' . preg_replace('/\s+/', ' ', trim($this->lastSql)) . ' | Params: ' . $params;
    }

    private function normaliseMatchType(string $matchType): string {
        $matchType = trim($matchType);

        return in_array($matchType, ['contains', 'equals', 'starts_with'], true)
            ? $matchType
            : 'contains';
    }

    private function normaliseReferenceMatchType(string $matchType): string {
        $matchType = trim($matchType);

        return in_array($matchType, ['none', 'contains', 'equals', 'starts_with'], true)
            ? $matchType
            : 'none';
    }

    private function truthyValue(mixed $value): bool {
        if (is_array($value)) {
            $value = $value !== [] ? end($value) : '';
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function nullableString(string $value): ?string {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function exportRuleRow(int $companyId, array $rule): array {
        $nominal = $this->fetchNominalAccountSummary($companyId, (int)($rule['nominal_account_id'] ?? 0));

        return [
            'priority' => (int)($rule['priority'] ?? 100),
            'match_field' => 'description',
            'desc_match_type' => (string)($rule['desc_match_type'] ?? $rule['match_type'] ?? 'contains'),
            'desc_match_value' => (string)($rule['desc_match_value'] ?? $rule['match_value'] ?? ''),
            'ref_match_type' => (string)($rule['ref_match_type'] ?? 'none'),
            'ref_match_value' => (string)($rule['ref_match_value'] ?? ''),
            'match_type' => (string)($rule['desc_match_type'] ?? $rule['match_type'] ?? 'contains'),
            'match_value' => (string)($rule['desc_match_value'] ?? $rule['match_value'] ?? ''),
            'source_category_value' => (string)($rule['source_category_value'] ?? ''),
            'source_account_value' => (string)($rule['source_account_value'] ?? ''),
            'nominal_account_id' => (int)($rule['nominal_account_id'] ?? 0),
            'nominal_code' => (string)($nominal['code'] ?? ($rule['nominal_code'] ?? '')),
            'nominal_name' => (string)($nominal['name'] ?? ($rule['nominal_name'] ?? '')),
            'is_active' => (int)($rule['is_active'] ?? 0) === 1,
        ];
    }

    private function normaliseImportRuleRows(mixed $decoded): array {
        if (is_array($decoded) && array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }

        if (is_array($decoded) && isset($decoded['rules']) && is_array($decoded['rules'])) {
            return array_values(array_filter($decoded['rules'], 'is_array'));
        }

        return [];
    }

    private function resolveImportNominalAccountId(int $companyId, array $row): int {
        $nominalAccountId = isset($row['nominal_account_id']) && preg_match('/^[1-9][0-9]*$/', trim((string)$row['nominal_account_id'])) === 1
            ? (int)$row['nominal_account_id']
            : 0;

        if ($nominalAccountId > 0 && $this->nominalBelongsToCompany($companyId, $nominalAccountId)) {
            return $nominalAccountId;
        }

        $nominalCode = trim((string)($row['nominal_code'] ?? ''));
        if ($nominalCode !== '') {
            $matchedByCode = $this->findNominalAccountIdByCode($companyId, $nominalCode);
            if ($matchedByCode > 0) {
                return $matchedByCode;
            }
        }

        $nominalName = trim((string)($row['nominal_name'] ?? ''));
        if ($nominalName !== '') {
            return $this->findNominalAccountIdByName($companyId, $nominalName);
        }

        return 0;
    }

    private function fetchNominalAccountSummary(int $companyId, int $nominalAccountId): ?array {
        if ($nominalAccountId <= 0) {
            return null;
        }

        $sql = 'SELECT id, code, name
               FROM nominal_accounts
               WHERE id = :id
               LIMIT 1';
        $stmt = $this->executeQuery($sql, ['id' => $nominalAccountId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function findNominalAccountIdByCode(int $companyId, string $code): int {
        $sql = 'SELECT id
               FROM nominal_accounts
               WHERE code = :code
                 AND is_active = 1
               ORDER BY id ASC
               LIMIT 1';
        $stmt = $this->executeQuery($sql, ['code' => $code]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : 0;
    }

    private function findNominalAccountIdByName(int $companyId, string $name): int {
        $sql = 'SELECT id
               FROM nominal_accounts
               WHERE name = :name
                 AND is_active = 1
               ORDER BY id ASC
               LIMIT 1';
        $stmt = $this->executeQuery($sql, ['name' => $name]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : 0;
    }

    private function fetchRulesSql(): string {
        return 'SELECT cr.id,
                    cr.company_id,
                    cr.is_active,
                    cr.priority,
                    cr.match_field,
                    cr.desc_match_type,
                    cr.desc_match_value,
                    cr.desc_match_type AS match_type,
                    cr.desc_match_value AS match_value,
                    cr.ref_match_type,
                    cr.ref_match_value,
                    cr.source_category_value,
                    cr.source_account_value,
                    cr.nominal_account_id,
                    cr.director_id,
                    cr.created_at,
                    cr.updated_at,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name
             FROM categorisation_rules cr
             LEFT JOIN nominal_accounts na ON na.id = cr.nominal_account_id
             WHERE cr.company_id = :company_id
             ORDER BY cr.priority ASC, cr.id ASC';
    }

    private function fetchRuleSql(): string {
        return 'SELECT cr.id,
                    cr.company_id,
                    cr.is_active,
                    cr.priority,
                    cr.match_field,
                    cr.desc_match_type,
                    cr.desc_match_value,
                    cr.desc_match_type AS match_type,
                    cr.desc_match_value AS match_value,
                    cr.ref_match_type,
                    cr.ref_match_value,
                    cr.source_category_value,
                    cr.source_account_value,
                    cr.nominal_account_id,
                    cr.director_id,
                    cr.created_at,
                    cr.updated_at
             FROM categorisation_rules cr
             LEFT JOIN nominal_accounts na ON na.id = cr.nominal_account_id
             WHERE cr.company_id = :company_id
               AND cr.id = :id
             LIMIT 1';
    }

    private function updateRuleSql(): string {
        return 'UPDATE categorisation_rules
                 SET is_active = :is_active,
                     priority = :priority,
                     match_field = :match_field,
                     desc_match_type = :desc_match_type,
                     desc_match_value = :desc_match_value,
                     ref_match_type = :ref_match_type,
                     ref_match_value = :ref_match_value,
                     source_category_value = :source_category_value,
                     source_account_value = :source_account_value,
                     nominal_account_id = :nominal_account_id,
                     director_id = :director_id,
                     updated_at = :updated_at
                 WHERE id = :id
                   AND company_id = :company_id';
    }

    private function insertRuleSql(): string {
        return 'INSERT INTO categorisation_rules (
            company_id,
            is_active,
            priority,
            match_field,
            desc_match_type,
            desc_match_value,
            ref_match_type,
            ref_match_value,
            source_category_value,
            source_account_value,
            nominal_account_id,
            director_id,
            created_at,
            updated_at
        ) VALUES (
            :company_id,
            :is_active,
            :priority,
            :match_field,
            :desc_match_type,
            :desc_match_value,
            :ref_match_type,
            :ref_match_value,
            :source_category_value,
            :source_account_value,
            :nominal_account_id,
            :director_id,
            :created_at,
            :updated_at
        )';
    }

    private function deleteRuleSql(): string {
        return 'DELETE FROM categorisation_rules
             WHERE id = :id
               AND company_id = :company_id';
    }

    private function setRuleActiveSql(): string {
        return 'UPDATE categorisation_rules
             SET is_active = :is_active,
                 updated_at = :updated_at
             WHERE id = :id
               AND company_id = :company_id';
    }

    private function findCreatedRuleIdSql(): string {
        return 'SELECT cr.id
             FROM categorisation_rules cr
             LEFT JOIN nominal_accounts na ON na.id = cr.nominal_account_id
             WHERE cr.company_id = :company_id
               AND cr.created_at = :created_at
               AND cr.priority = :priority
               AND cr.match_field = :match_field
               AND cr.desc_match_type = :desc_match_type
               AND cr.desc_match_value = :desc_match_value
               AND cr.ref_match_type = :ref_match_type
               AND (
                    (cr.ref_match_value = :ref_match_value_match)
                    OR (cr.ref_match_value IS NULL AND :ref_match_value_null = \'\')
               )
               AND cr.nominal_account_id = :nominal_account_id
               AND cr.is_active = :is_active
               AND (
                    (cr.source_category_value = :source_category_value_match)
                    OR (cr.source_category_value IS NULL AND :source_category_value_null = \'\')
               )
               AND (
                    (cr.source_account_value = :source_account_value_match)
                    OR (cr.source_account_value IS NULL AND :source_account_value_null = \'\')
               )
             ORDER BY cr.id DESC
             LIMIT 1';
    }

    private function nextPrioritySql(): string {
        return 'SELECT MAX(priority)
             FROM categorisation_rules
             WHERE company_id = :company_id';
    }

    private function executeQuery(string $sql, array $params = []): \PDOStatement {
        $this->lastSql = $sql;
        $this->lastParams = \PdoDB::filterParamsForSql($sql, $params);

        return \InterfaceDB::prepareExecute($sql, $params);
    }
}


