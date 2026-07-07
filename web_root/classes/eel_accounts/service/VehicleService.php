<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class VehicleService
{
    public static function vehicleNominalCodes(): array
    {
        return ['1320', '1321', '1322'];
    }

    public static function vehicleTypeOptions(): array
    {
        return [
            'unreviewed' => 'Unreviewed',
            'car' => 'Car',
            'van' => 'Van',
            'lorry_truck' => 'Lorry / Truck',
            'motorcycle' => 'Motorcycle',
            'other_commercial' => 'Other commercial vehicle',
        ];
    }

    public static function acquisitionConditionOptions(): array
    {
        return [
            '' => 'Not recorded',
            'new_unused' => 'New and Unused',
            'second_hand' => 'Second Hand',
        ];
    }

    public static function vehicleColourOptions(): array
    {
        return [
            '' => 'Not recorded',
            'Beige' => 'Beige',
            'Black' => 'Black',
            'Blue' => 'Blue',
            'Bronze' => 'Bronze',
            'Brown' => 'Brown',
            'Cream' => 'Cream',
            'Gold' => 'Gold',
            'Green' => 'Green',
            'Grey' => 'Grey',
            'Maroon' => 'Maroon',
            'Multi-colour' => 'Multi-colour',
            'Orange' => 'Orange',
            'Pink' => 'Pink',
            'Purple' => 'Purple',
            'Red' => 'Red',
            'Silver' => 'Silver',
            'Turquoise' => 'Turquoise',
            'White' => 'White',
            'Yellow' => 'Yellow',
        ];
    }

    public function hasRequiredSchema(): bool
    {
        return \InterfaceDB::tableExists('asset_register')
            && \InterfaceDB::tableExists('asset_vehicle_details')
            && \InterfaceDB::tableExists('transactions')
            && \InterfaceDB::tableExists('expense_claim_lines');
    }

    public function fetchRegister(int $companyId, int $accountingPeriodId = 0): array
    {
        if ($companyId <= 0 || !$this->hasRequiredSchema()) {
            return [
                'schema_ready' => $this->hasRequiredSchema(),
                'vehicle_types' => self::vehicleTypeOptions(),
                'acquisition_conditions' => self::acquisitionConditionOptions(),
                'vehicle_colours' => self::vehicleColourOptions(),
                'rows' => [],
                'warnings' => [],
            ];
        }

        $vehicleNominalIds = $this->vehicleNominalIds();
        if ($vehicleNominalIds === []) {
            return [
                'schema_ready' => true,
                'vehicle_types' => self::vehicleTypeOptions(),
                'acquisition_conditions' => self::acquisitionConditionOptions(),
                'vehicle_colours' => self::vehicleColourOptions(),
                'rows' => [],
                'warnings' => ['Vehicle nominal accounts 1320, 1321, and 1322 are not all available.'],
            ];
        }

        $periodExpression = 'COALESCE(t.accounting_period_id, ec.accounting_period_id, j.accounting_period_id, 0)';
        $periodClause = $accountingPeriodId > 0 ? ' AND ' . $periodExpression . ' = :accounting_period_id' : '';
        $params = ['company_id' => $companyId];
        if ($accountingPeriodId > 0) {
            $params['accounting_period_id'] = $accountingPeriodId;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT ar.id,
                    ar.company_id,
                    ar.asset_code,
                    ar.description,
                    ar.category,
                    ar.nominal_account_id,
                    ' . $periodExpression . ' AS accounting_period_id,
                    ar.purchase_date,
                    ar.cost,
                    ar.status,
                    ar.linked_transaction_id,
                    ar.linked_expense_claim_line_id,
                    ar.linked_journal_id,
                    na.code AS nominal_code,
                    na.name AS nominal_name,
                    tn.code AS transaction_nominal_code,
                    en.code AS expense_nominal_code,
                    vd.vehicle_type,
                    vd.registration_mark,
                    vd.make_model,
                    vd.colour,
                    vd.engine_capacity_cc,
                    vd.first_registered_date,
                    vd.acquisition_condition,
                    vd.is_zero_emission,
                    vd.co2_emissions_g_km,
                    vd.payload_kg,
                    vd.tax_review_status,
                    vd.notes
             FROM asset_register ar
             INNER JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             LEFT JOIN asset_vehicle_details vd ON vd.asset_id = ar.id
             LEFT JOIN transactions t ON t.id = ar.linked_transaction_id
             LEFT JOIN nominal_accounts tn ON tn.id = t.nominal_account_id
             LEFT JOIN expense_claim_lines ecl ON ecl.id = ar.linked_expense_claim_line_id
             LEFT JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
             LEFT JOIN nominal_accounts en ON en.id = ecl.nominal_account_id
             LEFT JOIN journals j ON j.id = ar.linked_journal_id
             WHERE ar.company_id = :company_id' . $periodClause . '
               AND (
                   na.code IN (\'1320\', \'1321\', \'1322\')
                   OR tn.code IN (\'1320\', \'1321\', \'1322\')
                   OR en.code IN (\'1320\', \'1321\', \'1322\')
                   OR vd.asset_id IS NOT NULL
               )
             ORDER BY ar.purchase_date DESC, ar.id DESC',
            $params
        ) ?: [];

        $warnings = [];
        foreach ($rows as $index => $row) {
            $integrity = $this->integrityWarning($row);
            $taxWarnings = $this->taxWarnings($row);
            $rows[$index]['warnings'] = array_values(array_filter(array_merge([$integrity], $taxWarnings)));
            foreach ($rows[$index]['warnings'] as $warning) {
                $warnings[] = $warning;
            }
        }

        return [
            'schema_ready' => true,
            'vehicle_types' => self::vehicleTypeOptions(),
            'acquisition_conditions' => self::acquisitionConditionOptions(),
            'vehicle_colours' => self::vehicleColourOptions(),
            'rows' => $rows,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function saveVehicleDetails(int $companyId, int $assetId, array $payload, int $defaultBankNominalId = 0, string $changedBy = 'web_app'): array
    {
        if ($companyId <= 0 || $assetId <= 0) {
            return ['success' => false, 'errors' => ['Select a vehicle asset before saving.']];
        }
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the vehicle register migration before saving vehicle details.']];
        }

        $asset = $this->fetchAsset($companyId, $assetId);
        if ($asset === null) {
            return ['success' => false, 'errors' => ['The selected vehicle asset could not be found.']];
        }
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, (int)$asset['accounting_period_id'], 'change vehicle classification for this period');

        $normalised = $this->normalisePayload($payload);
        if ($normalised['errors'] !== []) {
            return ['success' => false, 'errors' => $normalised['errors']];
        }

        $targetNominalId = $this->nominalIdForVehicleType((string)$normalised['values']['vehicle_type']);
        if ($targetNominalId <= 0) {
            return ['success' => false, 'errors' => ['The required motor vehicle nominal is missing.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $this->upsertVehicleDetails($companyId, $assetId, $normalised['values'], $changedBy);
            $oldNominalId = (int)$asset['nominal_account_id'];
            $targetCategory = $this->assetCategoryForVehicleType((string)$normalised['values']['vehicle_type']);
            if ($oldNominalId !== $targetNominalId || (string)($asset['category'] ?? '') !== $targetCategory) {
                $this->updateAssetNominal($assetId, $targetNominalId, $normalised['values']);
            }
            if ($oldNominalId !== $targetNominalId) {
                $this->syncLinkedSourceNominal($asset, $targetNominalId, $oldNominalId, $defaultBankNominalId);
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['Vehicle details could not be saved: ' . $exception->getMessage()]];
        }

        (new \eel_accounts\Service\AssetService())->refreshTaxData($companyId);

        return [
            'success' => true,
            'messages' => ['Vehicle details saved.'],
            'vehicle_register' => $this->fetchRegister($companyId, (int)$asset['accounting_period_id']),
        ];
    }

    public function cleanupVehicleDetailsForTransaction(int $transactionId): void
    {
        if ($transactionId <= 0 || !\InterfaceDB::tableExists('asset_vehicle_details')) {
            return;
        }

        $vehicleIds = $this->vehicleNominalIds();
        if ($vehicleIds === []) {
            return;
        }

        $transaction = \InterfaceDB::fetchOne(
            'SELECT nominal_account_id FROM transactions WHERE id = :id LIMIT 1',
            ['id' => $transactionId]
        );
        if ($transaction === null || in_array((int)($transaction['nominal_account_id'] ?? 0), $vehicleIds, true)) {
            return;
        }

        $assetIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            \InterfaceDB::fetchAll(
                'SELECT id FROM asset_register WHERE linked_transaction_id = :transaction_id',
                ['transaction_id' => $transactionId]
            ) ?: []
        );
        $this->deleteVehicleDetailsForAssets($assetIds);
    }

    public function cleanupVehicleDetailsForExpenseClaimLine(int $lineId): void
    {
        if ($lineId <= 0 || !\InterfaceDB::tableExists('asset_vehicle_details')) {
            return;
        }

        $vehicleIds = $this->vehicleNominalIds();
        if ($vehicleIds === []) {
            return;
        }

        $line = \InterfaceDB::fetchOne(
            'SELECT nominal_account_id FROM expense_claim_lines WHERE id = :id LIMIT 1',
            ['id' => $lineId]
        );
        if ($line === null || in_array((int)($line['nominal_account_id'] ?? 0), $vehicleIds, true)) {
            return;
        }

        $assetIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            \InterfaceDB::fetchAll(
                'SELECT id FROM asset_register WHERE linked_expense_claim_line_id = :line_id',
                ['line_id' => $lineId]
            ) ?: []
        );
        $this->deleteVehicleDetailsForAssets($assetIds);
    }

    public function periodReviewWarnings(int $companyId, int $accountingPeriodId): array
    {
        $register = $this->fetchRegister($companyId, $accountingPeriodId);
        $warnings = [];

        foreach ((array)($register['rows'] ?? []) as $row) {
            if ((string)($row['nominal_code'] ?? '') === '1320') {
                $warnings[] = 'Motor vehicle asset ' . (string)($row['asset_code'] ?? '') . ' is still in default nominal 1320. Review it on the Vehicles page before relying on capital allowances.';
            }
            foreach ((array)($row['warnings'] ?? []) as $warning) {
                $warnings[] = (string)$warning;
            }
        }

        return array_values(array_unique(array_filter($warnings)));
    }

    private function fetchAsset(int $companyId, int $assetId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT ar.*,
                    COALESCE(t.accounting_period_id, ec.accounting_period_id, j.accounting_period_id, 0) AS accounting_period_id
             FROM asset_register ar
             LEFT JOIN transactions t ON t.id = ar.linked_transaction_id
             LEFT JOIN expense_claim_lines ecl ON ecl.id = ar.linked_expense_claim_line_id
             LEFT JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
             LEFT JOIN journals j ON j.id = ar.linked_journal_id
             WHERE ar.company_id = :company_id
               AND ar.id = :id
             LIMIT 1',
            ['company_id' => $companyId, 'id' => $assetId]
        );

        return is_array($row) ? $row : null;
    }

    private function deleteVehicleDetailsForAssets(array $assetIds): void
    {
        $assetIds = array_values(array_filter(array_map('intval', $assetIds), static fn(int $id): bool => $id > 0));
        if ($assetIds === []) {
            return;
        }

        foreach (array_chunk($assetIds, 100) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            \InterfaceDB::prepareExecute('DELETE FROM asset_vehicle_details WHERE asset_id IN (' . $placeholders . ')', $chunk);
        }
    }

    private function normalisePayload(array $payload): array
    {
        $vehicleType = trim((string)($payload['vehicle_type'] ?? 'unreviewed'));
        $errors = [];
        if (!array_key_exists($vehicleType, self::vehicleTypeOptions())) {
            $errors[] = 'Choose a valid vehicle type.';
        }

        $values = [
            'vehicle_type' => $vehicleType,
            'registration_mark' => $this->nullableString(strtoupper((string)($payload['registration_mark'] ?? '')), 32),
            'make_model' => $this->nullableString((string)($payload['make_model'] ?? ''), 255),
            'colour' => $this->normaliseVehicleColour((string)($payload['colour'] ?? ''), $errors),
            'engine_capacity_cc' => $this->nullablePositiveInt($payload['engine_capacity_cc'] ?? null),
            'first_registered_date' => $this->nullableIsoDate((string)($payload['first_registered_date'] ?? ''), $errors, 'first registration date'),
            'acquisition_condition' => $this->normaliseAcquisitionCondition((string)($payload['acquisition_condition'] ?? '')),
            'is_zero_emission' => $this->truthy($payload['is_zero_emission'] ?? '0') ? 1 : 0,
            'co2_emissions_g_km' => $this->nullablePositiveInt($payload['co2_emissions_g_km'] ?? null),
            'payload_kg' => $this->nullablePositiveMoney($payload['payload_kg'] ?? null),
            'tax_review_status' => $vehicleType === 'unreviewed' ? 'unreviewed' : 'reviewed',
            'notes' => $this->nullableString((string)($payload['notes'] ?? ''), 512),
        ];

        return ['errors' => $errors, 'values' => $values];
    }

    private function upsertVehicleDetails(int $companyId, int $assetId, array $values, string $changedBy): void
    {
        $columns = 'asset_id, company_id, vehicle_type, registration_mark, make_model, colour, engine_capacity_cc,
            first_registered_date, acquisition_condition, is_zero_emission, co2_emissions_g_km, payload_kg,
            tax_review_status, reviewed_at, reviewed_by, notes, created_at, updated_at';
        $params = [
            'asset_id' => $assetId,
            'company_id' => $companyId,
            'vehicle_type' => (string)$values['vehicle_type'],
            'registration_mark' => $values['registration_mark'],
            'make_model' => $values['make_model'],
            'colour' => $values['colour'],
            'engine_capacity_cc' => $values['engine_capacity_cc'],
            'first_registered_date' => $values['first_registered_date'],
            'acquisition_condition' => $values['acquisition_condition'],
            'is_zero_emission' => (int)$values['is_zero_emission'],
            'co2_emissions_g_km' => $values['co2_emissions_g_km'],
            'payload_kg' => $values['payload_kg'],
            'tax_review_status' => (string)$values['tax_review_status'],
            'reviewed_at' => (string)$values['tax_review_status'] === 'reviewed' ? date('Y-m-d H:i:s') : null,
            'reviewed_by' => (string)$values['tax_review_status'] === 'reviewed' ? $changedBy : null,
            'notes' => $values['notes'],
        ];

        $sql = 'INSERT INTO asset_vehicle_details (' . $columns . ')
            VALUES (:asset_id, :company_id, :vehicle_type, :registration_mark, :make_model, :colour, :engine_capacity_cc,
                :first_registered_date, :acquisition_condition, :is_zero_emission, :co2_emissions_g_km, :payload_kg,
                :tax_review_status, :reviewed_at, :reviewed_by, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)';

        if (\InterfaceDB::driverName() === 'sqlite') {
            $sql .= ' ON CONFLICT(asset_id) DO UPDATE SET
                company_id = excluded.company_id,
                vehicle_type = excluded.vehicle_type,
                registration_mark = excluded.registration_mark,
                make_model = excluded.make_model,
                colour = excluded.colour,
                engine_capacity_cc = excluded.engine_capacity_cc,
                first_registered_date = excluded.first_registered_date,
                acquisition_condition = excluded.acquisition_condition,
                is_zero_emission = excluded.is_zero_emission,
                co2_emissions_g_km = excluded.co2_emissions_g_km,
                payload_kg = excluded.payload_kg,
                tax_review_status = excluded.tax_review_status,
                reviewed_at = excluded.reviewed_at,
                reviewed_by = excluded.reviewed_by,
                notes = excluded.notes,
                updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql .= ' ON DUPLICATE KEY UPDATE
                company_id = VALUES(company_id),
                vehicle_type = VALUES(vehicle_type),
                registration_mark = VALUES(registration_mark),
                make_model = VALUES(make_model),
                colour = VALUES(colour),
                engine_capacity_cc = VALUES(engine_capacity_cc),
                first_registered_date = VALUES(first_registered_date),
                acquisition_condition = VALUES(acquisition_condition),
                is_zero_emission = VALUES(is_zero_emission),
                co2_emissions_g_km = VALUES(co2_emissions_g_km),
                payload_kg = VALUES(payload_kg),
                tax_review_status = VALUES(tax_review_status),
                reviewed_at = VALUES(reviewed_at),
                reviewed_by = VALUES(reviewed_by),
                notes = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP';
        }

        \InterfaceDB::prepareExecute($sql, $params);
    }

    private function updateAssetNominal(int $assetId, int $targetNominalId, array $values): void
    {
        $category = $this->assetCategoryForVehicleType((string)$values['vehicle_type']);

        \InterfaceDB::prepareExecute(
            'UPDATE asset_register
             SET nominal_account_id = :nominal_account_id,
                 category = :category,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            ['nominal_account_id' => $targetNominalId, 'category' => $category, 'id' => $assetId]
        );
    }

    private function syncLinkedSourceNominal(array $asset, int $targetNominalId, int $oldNominalId, int $defaultBankNominalId): void
    {
        $transactionId = (int)($asset['linked_transaction_id'] ?? 0);
        if ($transactionId > 0) {
            $result = (new \eel_accounts\Service\TransactionCategorisationService())->saveManualCategorisation(
                $transactionId,
                $targetNominalId,
                null,
                false,
                'vehicle_register',
                true
            );
            if (empty($result['success'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', (array)($result['errors'] ?? ['Transaction nominal could not be updated.']))));
            }
            if ($defaultBankNominalId > 0) {
                (new \eel_accounts\Service\TransactionJournalService())->syncJournalForTransaction($transactionId, $defaultBankNominalId, 'vehicle_register', true);
            }
        }

        $lineId = (int)($asset['linked_expense_claim_line_id'] ?? 0);
        if ($lineId > 0) {
            \InterfaceDB::prepareExecute(
                'UPDATE expense_claim_lines
                 SET nominal_account_id = :nominal_account_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                ['nominal_account_id' => $targetNominalId, 'id' => $lineId]
            );
            \InterfaceDB::prepareExecute(
                'UPDATE expense_claim_line_assets
                 SET category = :category,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE expense_claim_line_id = :line_id',
                [
                    'category' => $this->assetCategoryForNominalId($targetNominalId),
                    'line_id' => $lineId,
                ]
            );
        }

        $journalId = (int)($asset['linked_journal_id'] ?? 0);
        if ($journalId > 0) {
            \InterfaceDB::prepareExecute(
                'UPDATE journal_lines
                 SET nominal_account_id = :new_nominal_id
                 WHERE journal_id = :journal_id
                   AND nominal_account_id = :old_nominal_id
                   AND ABS(debit - :cost) <= 0.01',
                [
                    'new_nominal_id' => $targetNominalId,
                    'journal_id' => $journalId,
                    'old_nominal_id' => $oldNominalId,
                    'cost' => round((float)($asset['cost'] ?? 0), 2),
                ]
            );
        }
    }

    private function nominalIdForVehicleType(string $vehicleType): int
    {
        return match ($vehicleType) {
            'car' => $this->findNominalIdByCode('1321'),
            'van', 'lorry_truck', 'motorcycle', 'other_commercial' => $this->findNominalIdByCode('1322'),
            default => $this->findNominalIdByCode('1320'),
        };
    }

    private function assetCategoryForVehicleType(string $vehicleType): string
    {
        return match ($vehicleType) {
            'car' => 'car',
            'van', 'lorry_truck', 'motorcycle', 'other_commercial' => 'van',
            default => 'motor_vehicle',
        };
    }

    private function assetCategoryForNominalId(int $nominalId): string
    {
        if ($nominalId === $this->findNominalIdByCode('1321')) {
            return 'car';
        }

        if ($nominalId === $this->findNominalIdByCode('1322')) {
            return 'van';
        }

        return 'motor_vehicle';
    }

    private function vehicleNominalIds(): array
    {
        $ids = [];
        foreach (self::vehicleNominalCodes() as $code) {
            $id = $this->findNominalIdByCode($code);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function findNominalIdByCode(string $code): int
    {
        return (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
            ['code' => $code]
        );
    }

    private function integrityWarning(array $row): string
    {
        $hasVehicleDetails = trim((string)($row['vehicle_type'] ?? '')) !== '';
        if (!$hasVehicleDetails) {
            return '';
        }

        $nominals = array_filter([
            (string)($row['nominal_code'] ?? ''),
            (string)($row['transaction_nominal_code'] ?? ''),
            (string)($row['expense_nominal_code'] ?? ''),
        ]);
        foreach ($nominals as $code) {
            if (in_array($code, self::vehicleNominalCodes(), true)) {
                return '';
            }
        }

        return 'Vehicle details exist for asset ' . (string)($row['asset_code'] ?? '') . ', but the linked source no longer uses a vehicle nominal.';
    }

    private function taxWarnings(array $row): array
    {
        $warnings = [];
        $vehicleType = (string)($row['vehicle_type'] ?? 'unreviewed');
        if ((string)($row['nominal_code'] ?? '') === '1320') {
            $warnings[] = 'Asset ' . (string)($row['asset_code'] ?? '') . ' remains in default vehicle nominal 1320.';
        }
        if ($vehicleType === 'car') {
            if (trim((string)($row['acquisition_condition'] ?? '')) === '') {
                $warnings[] = 'Car asset ' . (string)($row['asset_code'] ?? '') . ' is missing new/second-hand status.';
            }
            if (($row['co2_emissions_g_km'] ?? null) === null && (int)($row['is_zero_emission'] ?? 0) !== 1) {
                $warnings[] = 'Car asset ' . (string)($row['asset_code'] ?? '') . ' is missing CO2 emissions.';
            }
        }

        return $warnings;
    }

    private function normaliseAcquisitionCondition(string $value): string
    {
        $value = trim($value);

        return array_key_exists($value, self::acquisitionConditionOptions()) ? $value : '';
    }

    private function normaliseVehicleColour(string $value, array &$errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (self::vehicleColourOptions() as $colour => $label) {
            if ($colour === '') {
                continue;
            }
            if (strcasecmp($value, (string)$colour) === 0 || strcasecmp($value, (string)$label) === 0) {
                return (string)$colour;
            }
        }

        $errors[] = 'Choose a valid DVLA vehicle colour.';
        return null;
    }

    private function nullableString(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        return max(0, (int)$raw);
    }

    private function nullablePositiveMoney(mixed $value): ?float
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        return round(max(0.0, (float)$raw), 2);
    }

    private function nullableIsoDate(string $value, array &$errors, string $label): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof \DateTimeImmutable || (is_array($dateErrors) && ((int)$dateErrors['warning_count'] > 0 || (int)$dateErrors['error_count'] > 0))) {
            $errors[] = 'Enter a valid ' . $label . '.';
            return null;
        }

        return $parsed->format('Y-m-d');
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
