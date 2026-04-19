<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class NominalCatalogService
{
    public function export(): array
    {
        $subtypeRepository = new NominalSubtypeRepository();
        $accountRepository = new NominalAccountRepository();

        $subtypes = array_map(static function (array $row): array {
            return [
                'code' => (string)($row['code'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'parent_account_type' => (string)($row['parent_account_type'] ?? ''),
                'sort_order' => isset($row['sort_order']) ? (int)$row['sort_order'] : 100,
                'is_active' => (int)($row['is_active'] ?? 0) === 1,
            ];
        }, $subtypeRepository->fetchNominalSubtypes());

        $accounts = array_map(static function (array $row): array {
            return [
                'code' => (string)($row['code'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'account_type' => (string)($row['account_type'] ?? ''),
                'subtype_code' => (string)($row['subtype_code'] ?? ''),
                'tax_treatment' => (string)($row['tax_treatment'] ?? 'allowable'),
                'sort_order' => isset($row['sort_order']) ? (int)$row['sort_order'] : 100,
                'is_active' => (int)($row['is_active'] ?? 0) === 1,
            ];
        }, $accountRepository->fetchNominalAccountCatalog());

        return [
            'format' => 'nominal_accounts',
            'version' => 1,
            'exported_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'subtypes' => $subtypes,
            'accounts' => $accounts,
        ];
    }

    public function import(string $json): array
    {
        $json = trim($json);

        if ($json === '') {
            return $this->emptyImportResult('Paste an exported nominals JSON payload before importing.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return $this->emptyImportResult('The imported nominals JSON is invalid: ' . $exception->getMessage());
        }

        $subtypeRows = $this->normaliseSubtypeRows($decoded);
        $accountRows = $this->normaliseAccountRows($decoded);

        if ($subtypeRows === [] && $accountRows === []) {
            return $this->emptyImportResult('No nominal subtypes or accounts were found in the imported JSON payload.');
        }

        $subtypeRepository = new NominalSubtypeRepository();
        $accountRepository = new NominalAccountRepository();
        $subtypesCreated = 0;
        $subtypesUpdated = 0;
        $accountsCreated = 0;
        $accountsUpdated = 0;
        $failed = 0;
        $errors = [];

        foreach ($subtypeRows as $index => $row) {
            $code = strtolower(trim((string)($row['code'] ?? '')));
            $existingSubtype = $code !== '' ? $subtypeRepository->findByCode($code) : null;
            $input = [
                'code' => $code,
                'name' => (string)($row['name'] ?? ''),
                'parent_account_type' => (string)($row['parent_account_type'] ?? ''),
                'sort_order' => (string)($row['sort_order'] ?? '100'),
                'is_active' => !empty($row['is_active']) ? 1 : 0,
            ];
            $validationErrors = $subtypeRepository->validateInput(
                $input,
                $existingSubtype !== null ? (int)$existingSubtype['id'] : null
            );

            if ($validationErrors !== []) {
                $failed++;
                foreach ($validationErrors as $error) {
                    $errors[] = sprintf('Subtype %d: %s', $index + 1, $error);
                }
                continue;
            }

            $subtypeRepository->save($input, $existingSubtype !== null ? (int)$existingSubtype['id'] : null);
            if ($existingSubtype !== null) {
                $subtypesUpdated++;
            } else {
                $subtypesCreated++;
            }
        }

        $subtypeIndex = [];
        foreach ($subtypeRepository->fetchNominalSubtypes() as $subtype) {
            $subtypeIndex[(int)$subtype['id']] = $subtype;
        }

        foreach ($accountRows as $index => $row) {
            $code = trim((string)($row['code'] ?? ''));
            $existingAccount = $code !== '' ? $accountRepository->findByCode($code) : null;
            $subtypeCode = strtolower(trim((string)($row['subtype_code'] ?? '')));
            $subtype = $subtypeCode !== '' ? $subtypeRepository->findByCode($subtypeCode) : null;

            if ($subtypeCode !== '' && $subtype === null) {
                $failed++;
                $errors[] = sprintf(
                    'Account %d could not be imported because subtype "%s" was not found.',
                    $index + 1,
                    $subtypeCode
                );
                continue;
            }

            $input = [
                'code' => $code,
                'name' => (string)($row['name'] ?? ''),
                'account_type' => (string)($row['account_type'] ?? ''),
                'account_subtype_id' => $subtype !== null ? (string)$subtype['id'] : '',
                'tax_treatment' => (string)($row['tax_treatment'] ?? 'allowable'),
                'sort_order' => (string)($row['sort_order'] ?? '100'),
                'is_active' => !empty($row['is_active']) ? 1 : 0,
            ];
            $validationErrors = $accountRepository->validateInput(
                $input,
                $subtypeIndex,
                $existingAccount !== null ? (int)$existingAccount['id'] : null
            );

            if ($validationErrors !== []) {
                $failed++;
                foreach ($validationErrors as $error) {
                    $errors[] = sprintf('Account %d: %s', $index + 1, $error);
                }
                continue;
            }

            $accountRepository->save($input, $existingAccount !== null ? (int)$existingAccount['id'] : null);
            if ($existingAccount !== null) {
                $accountsUpdated++;
            } else {
                $accountsCreated++;
            }
        }

        return [
            'success' => $failed === 0 && ($subtypesCreated + $subtypesUpdated + $accountsCreated + $accountsUpdated) > 0,
            'errors' => $errors,
            'subtypes_created' => $subtypesCreated,
            'subtypes_updated' => $subtypesUpdated,
            'accounts_created' => $accountsCreated,
            'accounts_updated' => $accountsUpdated,
            'failed' => $failed,
        ];
    }

    public function normaliseSubtypeRows(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        $rows = $decoded['subtypes'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)));
    }

    public function normaliseAccountRows(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        $rows = $decoded['accounts'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)));
    }

    private function emptyImportResult(string $error): array
    {
        return [
            'success' => false,
            'errors' => [$error],
            'subtypes_created' => 0,
            'subtypes_updated' => 0,
            'accounts_created' => 0,
            'accounts_updated' => 0,
            'failed' => 0,
        ];
    }
}
