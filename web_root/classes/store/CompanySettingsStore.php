<?php

final class CompanySettingsStore
{
    private int $companyId;
    private bool $loaded = false;
    private array $cache = [];
    private array $dirty = [];
    private array $persistedTypes = [];
    private ?string $storageMode = null;

    public function __construct(int $companyId) {
        $this->companyId = $companyId;
    }

    public static function definitions(): array {
        return [
            'utr' => ['type' => 'int', 'default' => ''],
            'default_currency' => ['type' => 'char', 'default' => 'GBP'],
            'default_currency_symbol' => ['type' => 'char', 'default' => '&#163;'],
            'default_bank_nominal_id' => ['type' => 'int', 'default' => ''],
            'default_expense_nominal_id' => ['type' => 'int', 'default' => ''],
            'director_loan_nominal_id' => ['type' => 'int', 'default' => ''],
            'vat_nominal_id' => ['type' => 'int', 'default' => ''],
            'uncategorised_nominal_id' => ['type' => 'int', 'default' => ''],
            'uploads_path' => ['type' => 'char', 'default' => '/var/eel_accounts/uploads'],
            'date_format' => ['type' => 'char', 'default' => 'd/m/Y'],
            'enable_duplicate_file_check' => ['type' => 'bool', 'default' => true],
            'enable_duplicate_row_check' => ['type' => 'bool', 'default' => true],
            'auto_create_rule_prompt' => ['type' => 'bool', 'default' => true],
            'lock_posted_periods' => ['type' => 'bool', 'default' => false],
        ];
    }

    public static function defaults(): array {
        $defaults = [];

        foreach (self::definitions() as $setting => $definition) {
            $defaults[$setting] = $definition['default'];
        }

        return $defaults;
    }

    public function all(): array {
        $this->load();

        return $this->cache;
    }

    public function get(string $setting, mixed $default = null): mixed {
        $this->load();

        if (array_key_exists($setting, $this->cache)) {
            return $this->cache[$setting];
        }

        return $default;
    }

    public function set(string $setting, mixed $value, ?string $type = null): void {
        $this->load();

        $resolvedType = $this->resolveType($setting, $type);
        $normalisedValue = $this->normaliseValue($resolvedType, $value);
        $currentValue = $this->cache[$setting] ?? null;

        if (array_key_exists($setting, $this->cache) && $currentValue === $normalisedValue) {
            return;
        }

        $this->cache[$setting] = $normalisedValue;
        $this->dirty[$setting] = [
            'type' => $resolvedType,
            'value' => $normalisedValue,
        ];
    }

    public function hasPersistedValues(): bool {
        $this->load();

        return !empty($this->persistedTypes);
    }

    public function persistMissingDefaults(): bool {
        $this->load();

        if ($this->companyId <= 0 || $this->storageMode !== 'kv') {
            return false;
        }

        $seeded = false;

        foreach (self::definitions() as $setting => $definition) {
            if (isset($this->persistedTypes[$setting])) {
                continue;
            }

            $this->dirty[$setting] = [
                'type' => (string)$definition['type'],
                'value' => $this->cache[$setting] ?? $definition['default'],
            ];
            $seeded = true;
        }

        if (!$seeded) {
            return false;
        }

        $this->flush();

        return true;
    }

    public function flush(): void {
        $this->load();

        if ($this->companyId <= 0 || empty($this->dirty)) {
            return;
        }

        if ($this->storageMode !== 'kv') {
            throw new RuntimeException('The company_settings table is still using the legacy schema. Run db/eel_accounts.company_settings_kv.20260402.sql before saving settings.');
        }

        $ownsTransaction = !InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            $stmt = InterfaceDB::prepare('SELECT id, setting FROM company_settings WHERE company_id = ?');
            $stmt->execute([$this->companyId]);

            $existingRows = [];

            foreach ($stmt->fetchAll() as $row) {
                $existingRows[(string)$row['setting']] = (int)$row['id'];
            }

            foreach ($this->dirty as $setting => $payload) {
                $type = (string)$payload['type'];
                $value = $this->serialiseValue($type, $payload['value']);

                if (isset($existingRows[$setting])) {
                    $update = InterfaceDB::prepare(
                        'UPDATE company_settings
                         SET type = ?, value = ?, updated_at = CURRENT_TIMESTAMP
                         WHERE id = ?'
                    );
                    $update->execute([
                        $type,
                        $value,
                        $existingRows[$setting],
                    ]);
                } else {
                    $insert = InterfaceDB::prepare(
                        'INSERT INTO company_settings (company_id, setting, type, value, created_at, updated_at)
                         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                    );
                    $insert->execute([
                        $this->companyId,
                        $setting,
                        $type,
                        $value,
                    ]);
                }

                $this->persistedTypes[$setting] = $type;
            }

            $this->dirty = [];

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            throw $e;
        }
    }

    private function load(): void {
        if ($this->loaded) {
            return;
        }

        $this->cache = self::defaults();
        $this->dirty = [];
        $this->persistedTypes = [];
        $this->loaded = true;

        if ($this->companyId <= 0) {
            $this->storageMode = 'kv';
            return;
        }

        if ($this->usesKeyValueSchema()) {
            $this->storageMode = 'kv';
            $this->loadKeyValueRows();
            return;
        }

        $this->storageMode = 'legacy';
        $this->loadLegacyWideRow();
    }

    private function resolveType(string $setting, ?string $type = null): string {
        if ($type !== null && $type !== '') {
            return strtolower($type);
        }

        $definitions = self::definitions();

        if (isset($definitions[$setting]['type'])) {
            return (string)$definitions[$setting]['type'];
        }

        if (isset($this->persistedTypes[$setting])) {
            return (string)$this->persistedTypes[$setting];
        }

        return 'char';
    }

    private function normaliseValue(string $type, mixed $value): mixed {
        if ($type === 'bool') {
            return (bool)$value;
        }

        if ($type === 'int') {
            $stringValue = trim((string)$value);

            if ($stringValue === '') {
                return '';
            }

            if (!ctype_digit($stringValue)) {
                throw new RuntimeException('Invalid integer setting value supplied.');
            }

            return $stringValue;
        }

        return trim((string)$value);
    }

    private function serialiseValue(string $type, mixed $value): ?string {
        if ($type === 'bool') {
            return $value ? '1' : '0';
        }

        $stringValue = trim((string)$value);

        return $stringValue === '' ? null : $stringValue;
    }

    private function deserialiseValue(string $type, mixed $value): mixed {
        if ($type === 'bool') {
            return in_array((string)$value, ['1', 'true', 'TRUE', 'yes', 'on'], true);
        }

        if ($value === null) {
            return '';
        }

        return trim((string)$value);
    }

    private function usesKeyValueSchema(): bool {
        if ($this->storageMode !== null) {
            return $this->storageMode === 'kv';
        }

        try {
            $stmt = InterfaceDB::query('SELECT setting, type, value FROM company_settings WHERE 1 = 0');
            $stmt->closeCursor();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function loadKeyValueRows(): void {
        $stmt = InterfaceDB::prepare(
            'SELECT setting, type, value
             FROM company_settings
             WHERE company_id = ?
             ORDER BY id'
        );
        $stmt->execute([$this->companyId]);

        foreach ($stmt->fetchAll() as $row) {
            $setting = trim((string)($row['setting'] ?? ''));
            $type = strtolower(trim((string)($row['type'] ?? 'char')));

            if ($setting === '') {
                continue;
            }

            $this->cache[$setting] = $this->deserialiseValue($type, $row['value'] ?? null);
            $this->persistedTypes[$setting] = $type;
        }
    }

    private function loadLegacyWideRow(): void {
        $stmt = InterfaceDB::prepare('SELECT * FROM company_settings WHERE company_id = ?');
        $stmt->execute([$this->companyId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return;
        }

        foreach (self::definitions() as $setting => $definition) {
            if (!array_key_exists($setting, $row)) {
                continue;
            }

            $type = (string)$definition['type'];
            $this->cache[$setting] = $this->deserialiseValue($type, $row[$setting] ?? null);
            $this->persistedTypes[$setting] = $type;
        }
    }
}
