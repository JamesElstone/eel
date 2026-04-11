<?php

final class AppDbStatement extends PDOStatement
{
    /** @var list<string> */
    private array $namedOrder;
    private bool $rewriteNamedParams;

    protected function __construct(array $namedOrder = [], bool $rewriteNamedParams = false) {
        $this->namedOrder = array_values($namedOrder);
        $this->rewriteNamedParams = $rewriteNamedParams;
    }

    public function execute(?array $params = null): bool {
        if ($params !== null && $this->rewriteNamedParams) {
            $params = db_rewrite_execute_params($params, $this->namedOrder);
        }

        return parent::execute($params);
    }
}

class AppDbConnection extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false {
        [$preparedSql, $preparedOptions] = db_prepare_plan($this, $query, $options);

        return parent::prepare($preparedSql, $preparedOptions);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

function db_driver_name(PDO $pdo): string {
    return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
}

function db_is_odbc_driver(PDO $pdo): bool {
    return db_driver_name($pdo) === 'odbc';
}

function db_is_list_array(array $value): bool {
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }

    $expectedKey = 0;
    foreach ($value as $key => $_) {
        if ($key !== $expectedKey) {
            return false;
        }

        $expectedKey++;
    }

    return true;
}

function db_rewrite_named_placeholders(string $sql): array {
    $rewrittenSql = '';
    $namedOrder = [];
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];

        if ($character === '\'' || $character === '"' || $character === '`') {
            $quote = $character;
            $rewrittenSql .= $character;

            while (++$index < $length) {
                $quotedCharacter = $sql[$index];
                $rewrittenSql .= $quotedCharacter;

                if ($quotedCharacter === $quote) {
                    if ($quote === '\'' && $index + 1 < $length && $sql[$index + 1] === '\'') {
                        $rewrittenSql .= $sql[++$index];
                        continue;
                    }

                    break;
                }
            }

            continue;
        }

        if (
            $character === ':'
            && $index + 1 < $length
            && preg_match('/[A-Za-z_]/', $sql[$index + 1]) === 1
        ) {
            $placeholder = '';
            $cursor = $index + 1;

            while ($cursor < $length && preg_match('/[A-Za-z0-9_]/', $sql[$cursor]) === 1) {
                $placeholder .= $sql[$cursor];
                $cursor++;
            }

            if ($placeholder !== '') {
                $rewrittenSql .= '?';
                $namedOrder[] = $placeholder;
                $index = $cursor - 1;
                continue;
            }
        }

        $rewrittenSql .= $character;
    }

    return [$rewrittenSql, $namedOrder];
}

function db_prepare_plan(PDO $pdo, string $sql, array $options = []): array {
    if (!db_is_odbc_driver($pdo) || array_key_exists(PDO::ATTR_STATEMENT_CLASS, $options)) {
        return [$sql, $options];
    }

    [$rewrittenSql, $namedOrder] = db_rewrite_named_placeholders($sql);
    if ($namedOrder === []) {
        return [$sql, $options];
    }

    $options[PDO::ATTR_STATEMENT_CLASS] = [AppDbStatement::class, [$namedOrder, true]];

    return [$rewrittenSql, $options];
}

function db_rewrite_execute_params(array $params, array $namedOrder): array {
    if ($params === [] || $namedOrder === [] || db_is_list_array($params)) {
        return $params;
    }

    $ordered = [];
    foreach ($namedOrder as $placeholder) {
        if (!array_key_exists($placeholder, $params)) {
            throw new InvalidArgumentException('Missing SQL parameter: ' . $placeholder);
        }

        $ordered[] = $params[$placeholder];
    }

    return $ordered;
}

function db_prepare_execute(PDO $pdo, string $sql, array $params = []): PDOStatement {
    $stmt = db_prepare($pdo, $sql);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare SQL statement.');
    }
    $stmt->execute($params);

    return $stmt;
}

function db_prepare(PDO $pdo, string $sql, array $options = []): PDOStatement|false {
    if ($pdo instanceof AppDbConnection) {
        return $pdo->prepare($sql, $options);
    }

    [$preparedSql, $preparedOptions] = db_prepare_plan($pdo, $sql, $options);

    return $pdo->prepare($preparedSql, $preparedOptions);
}

function db_query(PDO $pdo, string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
    if ($pdo instanceof AppDbConnection) {
        return $pdo->query($sql, $fetchMode, ...$fetchModeArgs);
    }

    if ($fetchMode === null) {
        return $pdo->query($sql);
    }

    return $pdo->query($sql, $fetchMode, ...$fetchModeArgs);
}

function db_fetch_all(PDO $pdo, string $sql, array $params = []): array {
    return db_prepare_execute($pdo, $sql, $params)->fetchAll();
}

function db_fetch_one(PDO $pdo, string $sql, array $params = []): array|false {
    return db_prepare_execute($pdo, $sql, $params)->fetch();
}

function db_fetch_column(PDO $pdo, string $sql, array $params = [], int $column = 0): mixed {
    return db_prepare_execute($pdo, $sql, $params)->fetchColumn($column);
}

function db_connect_with_credentials(string $dsn, ?string $username = null, ?string $password = null, array $options = []): PDO {
    $baseOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    return new AppDbConnection(
        $dsn,
        $username,
        $password,
        $options + $baseOptions
    );
}

function connectDb(): PDO {
    $config = FrameWorkHelper::config();
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $dsn = trim((string)($dbConfig['dsn'] ?? ''));

    if ($dsn === '') {
        throw new RuntimeException('Database DSN is not configured in config/app.php.');
    }

    $username = (string)($dbConfig['user'] ?? '');
    $password = (string)($dbConfig['pass'] ?? '');

    return db_connect_with_credentials(
        $dsn,
        $username !== '' ? $username : null,
        $password !== '' ? $password : null,
        []
    );
}


function db(): PDO {
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = connectDb();

    return $pdo;
}


function fetchCompanies(PDO $pdo): array {
    $select = ['id', 'company_name', 'company_number'];

    if (companiesTableHasIncorporationDate($pdo)) {
        $select[] = 'incorporation_date';
    }

    if (companiesTableHasCompaniesHouseProfileColumns($pdo)) {
        $select[] = 'company_status';
        $select[] = 'registered_office_address_line_1';
        $select[] = 'registered_office_address_line_2';
        $select[] = 'registered_office_locality';
        $select[] = 'registered_office_region';
        $select[] = 'registered_office_postal_code';
        $select[] = 'registered_office_country';
        $select[] = 'registered_office_care_of';
        $select[] = 'registered_office_po_box';
        $select[] = 'registered_office_premises';
        $select[] = 'can_file';
        $select[] = 'has_charges';
        $select[] = 'has_insolvency_history';
        $select[] = 'has_been_liquidated';
        $select[] = 'registered_office_is_in_dispute';
        $select[] = 'undeliverable_registered_office_address';
        $select[] = 'has_super_secure_pscs';
        $select[] = 'companies_house_environment';
        $select[] = 'companies_house_etag';
        $select[] = 'companies_house_last_checked_at';
        $select[] = 'companies_house_profile_json';
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM companies ORDER BY company_name, id';
    return $pdo->query($sql)->fetchAll();
}


function fetchTaxYears(PDO $pdo, int $companyId): array {
    $stmt = $pdo->prepare('SELECT id, label, period_start, period_end FROM tax_years WHERE company_id = ? ORDER BY period_start DESC, id DESC');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}


function fetchNominalAccounts(PDO $pdo, int $companyId): array {
    $sql = 'SELECT na.id, na.code, na.name, na.account_type, na.tax_treatment, nas.code AS subtype_code
            FROM nominal_accounts na
            LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
            WHERE na.is_active = 1
            ORDER BY na.sort_order, na.code, na.name, na.id';
    return $pdo->query($sql)->fetchAll();
}


function fetchNominalSubtypes(PDO $pdo): array {
    $sql = 'SELECT id, code, name, parent_account_type, sort_order, is_active
            FROM nominal_account_subtypes
            ORDER BY sort_order, name, id';

    return $pdo->query($sql)->fetchAll();
}


function fetchNominalAccountCatalog(PDO $pdo): array {
    $sql = 'SELECT na.id,
                   na.code,
                   na.name,
                   na.account_type,
                   na.account_subtype_id,
                   na.tax_treatment,
                   na.is_active,
                   na.sort_order,
                   nas.code AS subtype_code,
                   nas.name AS subtype_name,
                   nas.parent_account_type
            FROM nominal_accounts na
            LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
            ORDER BY na.sort_order, na.code, na.name, na.id';

    return $pdo->query($sql)->fetchAll();
}


function exportNominalCatalog(PDO $pdo): array {
    $subtypes = array_map(static function (array $row): array {
        return [
            'code' => (string)($row['code'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'parent_account_type' => (string)($row['parent_account_type'] ?? ''),
            'sort_order' => isset($row['sort_order']) ? (int)$row['sort_order'] : 100,
            'is_active' => (int)($row['is_active'] ?? 0) === 1,
        ];
    }, fetchNominalSubtypes($pdo));

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
    }, fetchNominalAccountCatalog($pdo));

    return [
        'format' => 'nominal_accounts',
        'version' => 1,
        'exported_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
        'subtypes' => $subtypes,
        'accounts' => $accounts,
    ];
}


function importNominalCatalog(PDO $pdo, string $json): array {
    $json = trim($json);

    if ($json === '') {
        return [
            'success' => false,
            'errors' => ['Paste an exported nominals JSON payload before importing.'],
            'subtypes_created' => 0,
            'subtypes_updated' => 0,
            'accounts_created' => 0,
            'accounts_updated' => 0,
            'failed' => 0,
        ];
    }

    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return [
            'success' => false,
            'errors' => ['The imported nominals JSON is invalid: ' . $exception->getMessage()],
            'subtypes_created' => 0,
            'subtypes_updated' => 0,
            'accounts_created' => 0,
            'accounts_updated' => 0,
            'failed' => 0,
        ];
    }

    $subtypeRows = normaliseNominalImportSubtypeRows($decoded);
    $accountRows = normaliseNominalImportAccountRows($decoded);

    if ($subtypeRows === [] && $accountRows === []) {
        return [
            'success' => false,
            'errors' => ['No nominal subtypes or accounts were found in the imported JSON payload.'],
            'subtypes_created' => 0,
            'subtypes_updated' => 0,
            'accounts_created' => 0,
            'accounts_updated' => 0,
            'failed' => 0,
        ];
    }

    $subtypesCreated = 0;
    $subtypesUpdated = 0;
    $accountsCreated = 0;
    $accountsUpdated = 0;
    $failed = 0;
    $errors = [];

    foreach ($subtypeRows as $index => $row) {
        $code = strtolower(trim((string)($row['code'] ?? '')));
        $existingSubtype = $code !== '' ? findNominalSubtypeByCode($pdo, $code) : null;
        $input = [
            'code' => $code,
            'name' => (string)($row['name'] ?? ''),
            'parent_account_type' => (string)($row['parent_account_type'] ?? ''),
            'sort_order' => (string)($row['sort_order'] ?? '100'),
            'is_active' => !empty($row['is_active']) ? 1 : 0,
        ];
        $validationErrors = validateNominalSubtypeInput(
            $pdo,
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

        saveNominalSubtype($pdo, $input, $existingSubtype !== null ? (int)$existingSubtype['id'] : null);
        if ($existingSubtype !== null) {
            $subtypesUpdated++;
        } else {
            $subtypesCreated++;
        }
    }

    $subtypeIndex = [];
    foreach (fetchNominalSubtypes($pdo) as $subtype) {
        $subtypeIndex[(int)$subtype['id']] = $subtype;
    }

    foreach ($accountRows as $index => $row) {
        $code = trim((string)($row['code'] ?? ''));
        $existingAccount = $code !== '' ? findNominalAccountByCode($pdo, $code) : null;
        $subtypeCode = strtolower(trim((string)($row['subtype_code'] ?? '')));
        $subtype = $subtypeCode !== '' ? findNominalSubtypeByCode($pdo, $subtypeCode) : null;

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
        $validationErrors = validateNominalAccountInput(
            $pdo,
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

        saveNominalAccount($pdo, $input, $existingAccount !== null ? (int)$existingAccount['id'] : null);
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


function normaliseNominalImportSubtypeRows(mixed $decoded): array {
    if (!is_array($decoded)) {
        return [];
    }

    $rows = $decoded['subtypes'] ?? [];
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)));
}


function normaliseNominalImportAccountRows(mixed $decoded): array {
    if (!is_array($decoded)) {
        return [];
    }

    $rows = $decoded['accounts'] ?? [];
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)));
}


function findNominalSubtypeByCode(PDO $pdo, string $code): ?array {
    $row = db_fetch_one(
        $pdo,
        'SELECT id, code, name, parent_account_type, sort_order, is_active
         FROM nominal_account_subtypes
         WHERE code = :code
         LIMIT 1',
        ['code' => strtolower(trim($code))]
    );

    return is_array($row) ? $row : null;
}


function findNominalAccountByCode(PDO $pdo, string $code): ?array {
    $row = db_fetch_one(
        $pdo,
        'SELECT id, code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order
         FROM nominal_accounts
         WHERE code = :code
         LIMIT 1',
        ['code' => trim($code)]
    );

    return is_array($row) ? $row : null;
}


function loadSettingsFromDatabase(PDO $pdo, CompanySettingsStore $settingsStore, int $companyId, int $taxYearId): array {
    $settings = defaultSettings();
    $settings['company_id'] = $companyId > 0 ? (string)$companyId : '';
    $settings['tax_year_id'] = $taxYearId > 0 ? (string)$taxYearId : '';

    if ($companyId > 0) {
        $settingsStore->persistMissingDefaults();
    }

    if ($companyId > 0) {
        $select = ['id', 'company_name', 'company_number'];

        if (companiesTableHasIncorporationDate($pdo)) {
            $select[] = 'incorporation_date';
        }

        if (companiesTableHasCompaniesHouseProfileColumns($pdo)) {
            $select[] = 'company_status';
            $select[] = 'registered_office_address_line_1';
            $select[] = 'registered_office_address_line_2';
            $select[] = 'registered_office_locality';
            $select[] = 'registered_office_region';
            $select[] = 'registered_office_postal_code';
            $select[] = 'registered_office_country';
            $select[] = 'registered_office_care_of';
            $select[] = 'registered_office_po_box';
            $select[] = 'registered_office_premises';
            $select[] = 'can_file';
            $select[] = 'has_charges';
            $select[] = 'has_insolvency_history';
            $select[] = 'has_been_liquidated';
            $select[] = 'registered_office_is_in_dispute';
            $select[] = 'undeliverable_registered_office_address';
            $select[] = 'has_super_secure_pscs';
            $select[] = 'companies_house_environment';
            $select[] = 'companies_house_etag';
            $select[] = 'companies_house_last_checked_at';
            $select[] = 'companies_house_profile_json';
        }

        if (companiesTableHasVatRegistrationColumns($pdo)) {
            $select[] = 'is_vat_registered';
            $select[] = 'vat_country_code';
            $select[] = 'vat_number';
            $select[] = 'vat_validation_status';
            $select[] = 'vat_validated_at';
            $select[] = 'vat_validation_source';
            $select[] = 'vat_validation_name';
            if (companiesTableHasVatValidationDetailColumns($pdo)) {
                $select[] = 'vat_validation_address_line1';
                $select[] = 'vat_validation_postcode';
            }
            if (companiesTableHasVatValidationCountryColumn($pdo)) {
                $select[] = 'vat_validation_country_code';
            }
            $select[] = 'vat_last_error';
        }

        $companySql = 'SELECT ' . implode(', ', $select) . ' FROM companies WHERE id = ?';
        $stmt = $pdo->prepare($companySql);
        $stmt->execute([$companyId]);
        $company = $stmt->fetch();

        if (is_array($company)) {
            $settings['company_name'] = (string)($company['company_name'] ?? '');
            $settings['companies_house_number'] = (string)($company['company_number'] ?? '');
            $settings['incorporation_date'] = (string)($company['incorporation_date'] ?? '');
            $settings['company_status'] = (string)($company['company_status'] ?? '');
            $settings['registered_office_address_line_1'] = (string)($company['registered_office_address_line_1'] ?? '');
            $settings['registered_office_address_line_2'] = (string)($company['registered_office_address_line_2'] ?? '');
            $settings['registered_office_locality'] = (string)($company['registered_office_locality'] ?? '');
            $settings['registered_office_region'] = (string)($company['registered_office_region'] ?? '');
            $settings['registered_office_postal_code'] = (string)($company['registered_office_postal_code'] ?? '');
            $settings['registered_office_country'] = (string)($company['registered_office_country'] ?? '');
            $settings['registered_office_care_of'] = (string)($company['registered_office_care_of'] ?? '');
            $settings['registered_office_po_box'] = (string)($company['registered_office_po_box'] ?? '');
            $settings['registered_office_premises'] = (string)($company['registered_office_premises'] ?? '');
            $settings['can_file'] = isset($company['can_file']) ? (int)$company['can_file'] : null;
            $settings['has_charges'] = isset($company['has_charges']) ? (int)$company['has_charges'] : null;
            $settings['has_insolvency_history'] = isset($company['has_insolvency_history']) ? (int)$company['has_insolvency_history'] : null;
            $settings['has_been_liquidated'] = isset($company['has_been_liquidated']) ? (int)$company['has_been_liquidated'] : null;
            $settings['registered_office_is_in_dispute'] = isset($company['registered_office_is_in_dispute']) ? (int)$company['registered_office_is_in_dispute'] : null;
            $settings['undeliverable_registered_office_address'] = isset($company['undeliverable_registered_office_address']) ? (int)$company['undeliverable_registered_office_address'] : null;
            $settings['has_super_secure_pscs'] = isset($company['has_super_secure_pscs']) ? (int)$company['has_super_secure_pscs'] : null;
            $settings['companies_house_environment'] = (string)($company['companies_house_environment'] ?? '');
            $settings['companies_house_etag'] = (string)($company['companies_house_etag'] ?? '');
            $settings['companies_house_last_checked_at'] = (string)($company['companies_house_last_checked_at'] ?? '');
            $settings['companies_house_profile_json'] = (string)($company['companies_house_profile_json'] ?? '');
            $settings['is_vat_registered'] = !empty($company['is_vat_registered']);
            $settings['vat_country_code'] = strtoupper(trim((string)($company['vat_country_code'] ?? '')));
            $settings['vat_number'] = (string)($company['vat_number'] ?? '');
            $settings['vat_validation_status'] = (string)($company['vat_validation_status'] ?? '');
            $settings['vat_validated_at'] = (string)($company['vat_validated_at'] ?? '');
            $settings['vat_validation_source'] = (string)($company['vat_validation_source'] ?? '');
            $settings['vat_validation_name'] = (string)($company['vat_validation_name'] ?? '');
            $settings['vat_validation_address_line1'] = (string)($company['vat_validation_address_line1'] ?? '');
            $settings['vat_validation_postcode'] = (string)($company['vat_validation_postcode'] ?? '');
            $settings['vat_validation_country_code'] = (string)($company['vat_validation_country_code'] ?? '');
            $settings['vat_last_error'] = (string)($company['vat_last_error'] ?? '');
        }
    }

    if ($taxYearId > 0) {
        $stmt = $pdo->prepare('SELECT id, label, period_start, period_end FROM tax_years WHERE id = ? AND company_id = ?');
        $stmt->execute([$taxYearId, $companyId]);
        $taxYear = $stmt->fetch();

        if (is_array($taxYear)) {
            $settings['financial_period_label'] = (string)($taxYear['label'] ?? '');
            $settings['period_start'] = (string)($taxYear['period_start'] ?? '');
            $settings['period_end'] = (string)($taxYear['period_end'] ?? '');
        }
    }

    foreach ($settingsStore->all() as $setting => $value) {
        $settings[$setting] = $value;
    }

    return $settings;
}


function companiesTableHasIncorporationDate(PDO $pdo): bool {
    return companiesTableHasColumn($pdo, 'incorporation_date');
}

function companiesTableHasCompaniesHouseProfileColumns(PDO $pdo): bool {
    return companiesTableHasColumn($pdo, 'companies_house_profile_json');
}

function companiesTableHasVatRegistrationColumns(PDO $pdo): bool {
    return companiesTableHasColumn($pdo, 'is_vat_registered');
}

function companiesTableHasVatValidationDetailColumns(PDO $pdo): bool {
    return companiesTableHasColumn($pdo, 'vat_validation_address_line1')
        && companiesTableHasColumn($pdo, 'vat_validation_postcode');
}

function companiesTableHasVatValidationCountryColumn(PDO $pdo): bool {
    return companiesTableHasColumn($pdo, 'vat_validation_country_code');
}

function companiesTableHasColumn(PDO $pdo, string $columnName): bool {
    static $cache = [];

    if (array_key_exists($columnName, $cache)) {
        return $cache[$columnName];
    }

    try {
        $stmt = $pdo->query('SELECT ' . $columnName . ' FROM companies WHERE 1 = 0');
        $stmt->closeCursor();
        $cache[$columnName] = true;
    } catch (Throwable $e) {
        $cache[$columnName] = false;
    }

    return $cache[$columnName];
}


function normaliseCompaniesHouseProfileForStorage(?array $profile, string $environment = ''): array {
    if (!is_array($profile) || $profile === []) {
        return [];
    }

    $address = is_array($profile['registered_office_address'] ?? null)
        ? $profile['registered_office_address']
        : [];

    $profileJson = json_encode($profile, JSON_UNESCAPED_SLASHES);

    if ($profileJson === false) {
        throw new RuntimeException('Companies House profile could not be encoded as JSON.');
    }

    return [
        'company_status' => trim((string)($profile['company_status'] ?? '')) ?: null,
        'registered_office_address_line_1' => trim((string)($address['address_line_1'] ?? '')) ?: null,
        'registered_office_address_line_2' => trim((string)($address['address_line_2'] ?? '')) ?: null,
        'registered_office_locality' => trim((string)($address['locality'] ?? '')) ?: null,
        'registered_office_region' => trim((string)($address['region'] ?? '')) ?: null,
        'registered_office_postal_code' => trim((string)($address['postal_code'] ?? '')) ?: null,
        'registered_office_country' => trim((string)($address['country'] ?? '')) ?: null,
        'registered_office_care_of' => trim((string)($address['care_of'] ?? '')) ?: null,
        'registered_office_po_box' => trim((string)($address['po_box'] ?? '')) ?: null,
        'registered_office_premises' => trim((string)($address['premises'] ?? ($address['premise'] ?? ''))) ?: null,
        'can_file' => array_key_exists('can_file', $profile) ? ((bool)$profile['can_file'] ? 1 : 0) : null,
        'has_charges' => array_key_exists('has_charges', $profile) ? ((bool)$profile['has_charges'] ? 1 : 0) : null,
        'has_insolvency_history' => array_key_exists('has_insolvency_history', $profile) ? ((bool)$profile['has_insolvency_history'] ? 1 : 0) : null,
        'has_been_liquidated' => array_key_exists('has_been_liquidated', $profile) ? ((bool)$profile['has_been_liquidated'] ? 1 : 0) : null,
        'registered_office_is_in_dispute' => array_key_exists('registered_office_is_in_dispute', $profile) ? ((bool)$profile['registered_office_is_in_dispute'] ? 1 : 0) : null,
        'undeliverable_registered_office_address' => array_key_exists('undeliverable_registered_office_address', $profile) ? ((bool)$profile['undeliverable_registered_office_address'] ? 1 : 0) : null,
        'has_super_secure_pscs' => array_key_exists('has_super_secure_pscs', $profile) ? ((bool)$profile['has_super_secure_pscs'] ? 1 : 0) : null,
        'companies_house_environment' => trim($environment) !== '' ? trim($environment) : null,
        'companies_house_etag' => trim((string)($profile['etag'] ?? '')) ?: null,
        'companies_house_profile_json' => $profileJson,
    ];
}


function validateNominalSubtypeInput(PDO $pdo, array $input, ?int $ignoreId = null): array {
    $errors = [];
    $code = strtolower(trim((string)($input['code'] ?? '')));
    $name = trim((string)($input['name'] ?? ''));
    $parentType = trim((string)($input['parent_account_type'] ?? ''));
    $sortOrder = trim((string)($input['sort_order'] ?? '100'));

    if ($code === '' || !preg_match('/^[a-z0-9_]+$/', $code)) {
        $errors[] = 'Subtype code is required and must use lowercase letters, numbers, or underscores.';
    }

    if ($name === '') {
        $errors[] = 'Subtype name is required.';
    }

    if (!in_array($parentType, validAccountTypes(), true)) {
        $errors[] = 'Subtype parent account type is invalid.';
    }

    if ($sortOrder === '' || !preg_match('/^-?\d+$/', $sortOrder)) {
        $errors[] = 'Subtype sort order must be a whole number.';
    }

    if (empty($errors) && $code !== '') {
        $sql = 'SELECT id FROM nominal_account_subtypes WHERE code = ?';
        $params = [$code];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetch()) {
            $errors[] = 'Subtype code must be unique.';
        }
    }

    return $errors;
}


function saveNominalSubtype(PDO $pdo, array $input, ?int $id = null): void {
    $payload = [
        strtolower(trim((string)$input['code'])),
        trim((string)$input['name']),
        trim((string)$input['parent_account_type']),
        (int)$input['sort_order'],
        (int)$input['is_active'],
    ];

    if ($id === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute($payload);
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE nominal_account_subtypes
         SET code = ?, name = ?, parent_account_type = ?, sort_order = ?, is_active = ?
         WHERE id = ?'
    );
    $payload[] = $id;
    $stmt->execute($payload);
}


function validateNominalAccountInput(PDO $pdo, array $input, array $subtypeIndex, ?int $ignoreId = null): array {
    $errors = [];
    $code = trim((string)($input['code'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $accountType = trim((string)($input['account_type'] ?? ''));
    $subtypeId = trim((string)($input['account_subtype_id'] ?? ''));
    $taxTreatment = strtolower(trim((string)($input['tax_treatment'] ?? 'allowable')));
    $sortOrder = trim((string)($input['sort_order'] ?? '100'));

    if ($code === '') {
        $errors[] = 'Nominal code is required.';
    }

    if ($name === '') {
        $errors[] = 'Nominal name is required.';
    }

    if (!in_array($accountType, validAccountTypes(), true)) {
        $errors[] = 'Nominal account type is invalid.';
    }

    if (!in_array($taxTreatment, validNominalTaxTreatments(), true)) {
        $errors[] = 'Nominal tax treatment is invalid.';
    }

    if ($sortOrder === '' || !preg_match('/^-?\d+$/', $sortOrder)) {
        $errors[] = 'Nominal sort order must be a whole number.';
    }

    if ($subtypeId !== '') {
        if (!ctype_digit($subtypeId) || !isset($subtypeIndex[(int)$subtypeId])) {
            $errors[] = 'Nominal subtype selection is invalid.';
        } elseif ((string)$subtypeIndex[(int)$subtypeId]['parent_account_type'] !== $accountType) {
            $errors[] = 'Nominal account type must match the selected subtype parent account type.';
        }
    }

    if (empty($errors) && $code !== '') {
        $sql = 'SELECT id FROM nominal_accounts WHERE code = ?';
        $params = [$code];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetch()) {
            $errors[] = 'Nominal code must be unique.';
        }
    }

    return $errors;
}


function saveNominalAccount(PDO $pdo, array $input, ?int $id = null): void {
    $subtypeId = trim((string)$input['account_subtype_id']) !== '' ? (int)$input['account_subtype_id'] : null;
    $payload = [
        trim((string)$input['code']),
        trim((string)$input['name']),
        trim((string)$input['account_type']),
        $subtypeId,
        strtolower(trim((string)($input['tax_treatment'] ?? 'allowable'))),
        (int)$input['is_active'],
        (int)$input['sort_order'],
    ];

    if ($id === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute($payload);
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE nominal_accounts
         SET code = ?, name = ?, account_type = ?, account_subtype_id = ?, tax_treatment = ?, is_active = ?, sort_order = ?
         WHERE id = ?'
    );
    $payload[] = $id;
    $stmt->execute($payload);
}


function saveSettingsToDatabase(PDO $pdo, CompanySettingsStore $settingsStore, array $settings): void {
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('UPDATE companies SET company_name = ?, company_number = ? WHERE id = ?');
        $stmt->execute([
            $settings['company_name'],
            $settings['companies_house_number'] !== '' ? $settings['companies_house_number'] : null,
            (int)$settings['company_id'],
        ]);

        $stmt = $pdo->prepare('UPDATE tax_years SET label = ?, period_start = ?, period_end = ? WHERE id = ? AND company_id = ?');
        $stmt->execute([
            $settings['financial_period_label'],
            $settings['period_start'],
            $settings['period_end'],
            (int)$settings['tax_year_id'],
            (int)$settings['company_id'],
        ]);
        foreach (CompanySettingsStore::definitions() as $settingName => $definition) {
            if (!array_key_exists($settingName, $settings)) {
                continue;
            }

            $settingsStore->set($settingName, $settings[$settingName], (string)$definition['type']);
        }

        $settingsStore->flush();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


function saveCompanySection(PDO $pdo, CompanySettingsStore $settingsStore, array $settings): void {
    if (!companiesTableHasVatRegistrationColumns($pdo)) {
        throw new RuntimeException('Run db/eel_accounts.company_vat_registration.20260403.sql before saving VAT registration details.');
    }

    $isVatRegistered = !empty($settings['is_vat_registered']);
    $canPersistVatDetails = $isVatRegistered && in_array(
        trim((string)($settings['vat_validation_status'] ?? '')),
        ['valid', 'mismatch_override'],
        true
    );
    $vatCountryCode = $canPersistVatDetails
        ? (trim((string)($settings['vat_country_code'] ?? '')) !== '' ? strtoupper(trim((string)$settings['vat_country_code'])) : null)
        : null;
    $vatNumber = $canPersistVatDetails
        ? (trim((string)($settings['vat_number'] ?? '')) !== '' ? trim((string)$settings['vat_number']) : null)
        : null;
    $vatValidationStatus = $canPersistVatDetails ? trim((string)($settings['vat_validation_status'] ?? '')) : null;
    $vatValidatedAt = $canPersistVatDetails ? (trim((string)($settings['vat_validated_at'] ?? '')) !== '' ? trim((string)$settings['vat_validated_at']) : null) : null;
    $vatValidationSource = $canPersistVatDetails ? (trim((string)($settings['vat_validation_source'] ?? '')) !== '' ? trim((string)$settings['vat_validation_source']) : null) : null;
    $vatValidationName = $canPersistVatDetails ? (trim((string)($settings['vat_validation_name'] ?? '')) !== '' ? trim((string)$settings['vat_validation_name']) : null) : null;
    $vatValidationAddressLine1 = $canPersistVatDetails ? (trim((string)($settings['vat_validation_address_line1'] ?? '')) !== '' ? trim((string)$settings['vat_validation_address_line1']) : null) : null;
    $vatValidationPostcode = $canPersistVatDetails ? (trim((string)($settings['vat_validation_postcode'] ?? '')) !== '' ? trim((string)$settings['vat_validation_postcode']) : null) : null;
    $vatValidationCountryCode = $canPersistVatDetails ? (trim((string)($settings['vat_validation_country_code'] ?? '')) !== '' ? strtoupper(trim((string)$settings['vat_validation_country_code'])) : null) : null;
    $vatLastError = $canPersistVatDetails ? (trim((string)($settings['vat_last_error'] ?? '')) !== '' ? trim((string)$settings['vat_last_error']) : null) : null;
    $hasVatValidationDetailColumns = companiesTableHasVatValidationDetailColumns($pdo);
    $hasVatValidationCountryColumn = companiesTableHasVatValidationCountryColumn($pdo);

    $pdo->beginTransaction();

    try {
        $sql = 'UPDATE companies
             SET company_name = ?,
                 company_number = ?,
                 is_vat_registered = ?,
                 vat_country_code = ?,
                 vat_number = ?,
                 vat_validation_status = ?,
                 vat_validated_at = ?,
                 vat_validation_source = ?,
                 vat_validation_name = ?';
        $params = [
            $settings['company_name'],
            $settings['companies_house_number'] !== '' ? $settings['companies_house_number'] : null,
            $isVatRegistered ? 1 : 0,
            $vatCountryCode,
            $vatNumber,
            $vatValidationStatus,
            $vatValidatedAt,
            $vatValidationSource,
            $vatValidationName,
        ];

        if ($hasVatValidationDetailColumns) {
            $sql .= ',
                 vat_validation_address_line1 = ?,
                 vat_validation_postcode = ?';
            $params[] = $vatValidationAddressLine1;
            $params[] = $vatValidationPostcode;
        }

        if ($hasVatValidationCountryColumn) {
            $sql .= ',
                 vat_validation_country_code = ?';
            $params[] = $vatValidationCountryCode;
        }

        $sql .= ',
                 vat_last_error = ?
             WHERE id = ?';
        $params[] = $vatLastError;
        $params[] = (int)$settings['company_id'];

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $settingsStore->set('utr', $settings['utr'], 'int');
        $settingsStore->set('default_currency', $settings['default_currency'], 'char');
        $settingsStore->set('date_format', $settings['date_format'], 'char');
        $settingsStore->flush();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


function saveAccountingSection(PDO $pdo, CompanySettingsStore $settingsStore, array &$settings): void {
    $pdo->beginTransaction();

    try {
        if ($settings['tax_year_id'] === '') {
            $newPeriodId = createAccountingPeriod(
                $pdo,
                (int)$settings['company_id'],
                $settings['period_start'],
                $settings['period_end'],
                $settings['financial_period_label']
            );
            $settings['tax_year_id'] = $newPeriodId > 0 ? (string)$newPeriodId : '';
        } else {
            $stmt = $pdo->prepare('UPDATE tax_years SET label = ?, period_start = ?, period_end = ? WHERE id = ? AND company_id = ?');
            $stmt->execute([
                $settings['financial_period_label'],
                $settings['period_start'],
                $settings['period_end'],
                (int)$settings['tax_year_id'],
                (int)$settings['company_id'],
            ]);
        }

        $settingsStore->flush();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


function saveNominalsSection(PDO $pdo, CompanySettingsStore $settingsStore, array $settings): void {
    $settingsStore->set('default_bank_nominal_id', $settings['default_bank_nominal_id'], 'int');
    $settingsStore->set('default_expense_nominal_id', $settings['default_expense_nominal_id'], 'int');
    $settingsStore->set('director_loan_nominal_id', $settings['director_loan_nominal_id'], 'int');
    $settingsStore->set('vat_nominal_id', $settings['vat_nominal_id'], 'int');
    $settingsStore->set('uncategorised_nominal_id', $settings['uncategorised_nominal_id'], 'int');
    $settingsStore->flush();
}


function saveImportReviewSection(PDO $pdo, CompanySettingsStore $settingsStore, array $settings): void {
    $settingsStore->set('enable_duplicate_file_check', $settings['enable_duplicate_file_check'], 'bool');
    $settingsStore->set('enable_duplicate_row_check', $settings['enable_duplicate_row_check'], 'bool');
    $settingsStore->set('auto_create_rule_prompt', $settings['auto_create_rule_prompt'], 'bool');
    $settingsStore->set('lock_posted_periods', $settings['lock_posted_periods'], 'bool');
    $settingsStore->flush();
}


function deleteCompany(PDO $pdo, int $companyId): void {
    if ($companyId <= 0) {
        throw new RuntimeException('A valid company must be selected before deletion.');
    }

    $pdo->beginTransaction();

    try {
        $companyLookup = $pdo->prepare('SELECT company_number FROM companies WHERE id = ?');
        $companyLookup->execute([$companyId]);
        $companyNumber = trim((string)$companyLookup->fetchColumn());

        /*
         * Companies House filed accounts are reference-only data, but once a company
         * is explicitly deleted from the app we remove its stored reference documents
         * as well so the database matches the UI promise of deleting linked company data.
         */
        if ($companyNumber !== '') {
            $stmt = $pdo->prepare('DELETE FROM companies_house_documents WHERE company_id = ? OR company_number = ?');
            $stmt->execute([$companyId, $companyNumber]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM companies_house_documents WHERE company_id = ?');
            $stmt->execute([$companyId]);
        }

        $stmt = $pdo->prepare('DELETE FROM company_settings WHERE company_id = ?');
        $stmt->execute([$companyId]);

        $stmt = $pdo->prepare('DELETE FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);

        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('The selected company could not be deleted.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


function applyNominalSuggestions(PDO $pdo, CompanySettingsStore $settingsStore, array &$settings, array $nominalAccounts): bool {
    $suggestions = buildNominalDefaultSuggestions($nominalAccounts, $settings);

    if (count($suggestions) !== 5) {
        return false;
    }

    $settings['default_bank_nominal_id'] = (string)$suggestions['default_bank_nominal_id']['id'];
    $settings['default_expense_nominal_id'] = (string)$suggestions['default_expense_nominal_id']['id'];
    $settings['director_loan_nominal_id'] = (string)$suggestions['director_loan_nominal_id']['id'];
    $settings['vat_nominal_id'] = (string)$suggestions['vat_nominal_id']['id'];
    $settings['uncategorised_nominal_id'] = (string)$suggestions['uncategorised_nominal_id']['id'];

    saveNominalsSection($pdo, $settingsStore, $settings);

    return true;
}


function validateAccountingPeriodOverlap(PDO $pdo, int $companyId, int $periodId, string $periodStart, string $periodEnd): array {
    $stmt = $pdo->prepare('SELECT id, label, period_start, period_end FROM tax_years WHERE company_id = ? ORDER BY period_start, id');
    $stmt->execute([$companyId]);
    $errors = [];

    foreach ($stmt->fetchAll() as $row) {
        $rowId = (int)$row['id'];

        if ($rowId === $periodId) {
            continue;
        }

        if (accountingPeriodsOverlap($periodStart, $periodEnd, (string)$row['period_start'], (string)$row['period_end'])) {
            $errors[] = 'The selected accounting period overlaps with existing accounting period "' . (string)$row['label'] . '".';
        }
    }

    return $errors;
}


function validateAccountingPeriodSequence(PDO $pdo, int $companyId, int $periodId, string $periodStart, string $periodEnd): array {
    $stmt = $pdo->prepare('SELECT id, label, period_start, period_end FROM tax_years WHERE company_id = ? ORDER BY period_start, id');
    $stmt->execute([$companyId]);
    $periods = [];

    foreach ($stmt->fetchAll() as $row) {
        $rowId = (int)$row['id'];

        if ($rowId === $periodId) {
            continue;
        }

        $periods[] = [
            'id' => $rowId,
            'label' => (string)$row['label'],
            'period_start' => (string)$row['period_start'],
            'period_end' => (string)$row['period_end'],
        ];
    }

    $periods[] = [
        'id' => $periodId,
            'label' => ctrl_accounting_period_label($periodStart, $periodEnd),
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
    ];

    usort($periods, static function (array $a, array $b): int {
        return [$a['period_start'], $a['period_end'], $a['id']] <=> [$b['period_start'], $b['period_end'], $b['id']];
    });

    for ($index = 1, $count = count($periods); $index < $count; $index++) {
        $previousEnd = new DateTimeImmutable($periods[$index - 1]['period_end']);
        $currentStart = new DateTimeImmutable($periods[$index]['period_start']);
        $expectedStart = $previousEnd->modify('+1 day')->format('Y-m-d');

        if ($currentStart->format('Y-m-d') !== $expectedStart) {
            return [
                'Accounting periods must be sequential with no gaps. "' . $periods[$index]['label'] . '" should start on ' . $expectedStart . '.',
            ];
        }
    }

    return [];
}


function createAccountingPeriod(PDO $pdo, int $companyId, string $periodStart, string $periodEnd, ?string $label = null): int {
    $label = $label !== null && $label !== '' ? $label : ctrl_accounting_period_label($periodStart, $periodEnd);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tax_years WHERE company_id = ? AND period_start = ? AND period_end = ?');
    $stmt->execute([$companyId, $periodStart, $periodEnd]);

    if ((int)$stmt->fetchColumn() > 0) {
        $find = $pdo->prepare('SELECT id FROM tax_years WHERE company_id = ? AND period_start = ? AND period_end = ? ORDER BY id DESC LIMIT 1');
        $find->execute([$companyId, $periodStart, $periodEnd]);

        return (int)$find->fetchColumn();
    }

    $stmt = $pdo->prepare('INSERT INTO tax_years (company_id, label, period_start, period_end) VALUES (?, ?, ?, ?)');
    $stmt->execute([$companyId, $label, $periodStart, $periodEnd]);

    $find = $pdo->prepare('SELECT id FROM tax_years WHERE company_id = ? AND period_start = ? AND period_end = ? ORDER BY id DESC LIMIT 1');
    $find->execute([$companyId, $periodStart, $periodEnd]);

    return (int)$find->fetchColumn();
}


function fetchCompaniesHouseFiledAccountingPeriods(PDO $pdo, int $companyId, ?string $companyNumber = null): array {
    $companyNumber = strtoupper(trim((string)$companyNumber));
    $filters = [];
    $params = [];

    if ($companyId > 0) {
        $filters[] = 'd.company_id = ?';
        $params[] = $companyId;
    }

    if ($companyNumber !== '') {
        $filters[] = 'd.company_number = ?';
        $params[] = $companyNumber;
    }

    if ($filters === []) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT
            d.id AS document_row_id,
            d.document_id,
            d.filing_date,
            d.filing_type,
            d.filing_category,
            d.filing_description,
            MAX(CASE WHEN c.short_name = \'StartDateForPeriodCoveredByReport\' THEN f.normalised_date END) AS period_start,
            MAX(CASE WHEN c.short_name = \'EndDateForPeriodCoveredByReport\' THEN f.normalised_date END) AS period_end,
            MAX(CASE WHEN c.short_name = \'BalanceSheetDate\' THEN f.normalised_date END) AS balance_sheet_date
        FROM companies_house_documents d
        LEFT JOIN companies_house_document_facts f
            ON f.document_fk = d.id
           AND f.is_latest_year_fact = 1
        LEFT JOIN companies_house_taxonomy_concepts c
            ON c.id = f.concept_fk
        WHERE (' . implode(' OR ', $filters) . ')
          AND d.filing_category = \'accounts\'
          AND d.classification = \'digital_xhtml\'
          AND d.parse_status = \'parsed_latest_year\'
        GROUP BY
            d.id,
            d.document_id,
            d.filing_date,
            d.filing_type,
            d.filing_category,
            d.filing_description
        ORDER BY d.filing_date DESC, d.id DESC'
    );
    $stmt->execute($params);

    $periodsByKey = [];

    foreach ($stmt->fetchAll() as $row) {
        /*
         * Only latest-year facts are considered here because the iXBRL parser already
         * excluded prior-year comparatives when persisting document facts.
         */
        $periodStart = trim((string)($row['period_start'] ?? ''));
        $periodEnd = trim((string)($row['period_end'] ?? ''));
        $balanceSheetDate = trim((string)($row['balance_sheet_date'] ?? ''));

        if ($periodEnd === '' && $balanceSheetDate !== '') {
            $periodEnd = $balanceSheetDate;
        }

        if ($periodStart === '' || $periodEnd === '') {
            continue;
        }

        $key = $periodStart . '|' . $periodEnd;
        $row['period_start'] = $periodStart;
        $row['period_end'] = $periodEnd;
        $row['balance_sheet_date'] = $balanceSheetDate;

        if (!isset($periodsByKey[$key])) {
            $periodsByKey[$key] = $row;
            continue;
        }

        $existing = $periodsByKey[$key];
        $rowRank = [$row['filing_date'] ?? '', (int)($row['document_row_id'] ?? 0)];
        $existingRank = [$existing['filing_date'] ?? '', (int)($existing['document_row_id'] ?? 0)];

        if ($rowRank > $existingRank) {
            $periodsByKey[$key] = $row;
        }
    }

    $periods = array_values($periodsByKey);
    usort($periods, static function (array $a, array $b): int {
        return [$a['period_start'], $a['period_end'], $a['document_id']] <=> [$b['period_start'], $b['period_end'], $b['document_id']];
    });

    return $periods;
}


function fetchStoredCompaniesHouseDocumentIds(PDO $pdo, int $companyId, ?string $companyNumber = null): array {
    $companyNumber = strtoupper(trim((string)$companyNumber));
    $filters = [];
    $params = [];

    if ($companyId > 0) {
        $filters[] = 'company_id = ?';
        $params[] = $companyId;
    }

    if ($companyNumber !== '') {
        $filters[] = 'company_number = ?';
        $params[] = $companyNumber;
    }

    if ($filters === []) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT document_id
         FROM companies_house_documents
         WHERE ' . implode(' OR ', $filters) . '
         ORDER BY document_id'
    );
    $stmt->execute($params);

    return array_values(array_filter(array_map(static function ($value): string {
        return trim((string)$value);
    }, $stmt->fetchAll(PDO::FETCH_COLUMN))));
}


function createAccountingPeriodsFromCompaniesHouseFiledPeriods(PDO $pdo, int $companyId, ?string $companyNumber = null): array {
    $result = [
        'filed_period_count' => 0,
        'created_count' => 0,
        'existing_count' => 0,
        'skipped_overlap_count' => 0,
        'created_periods' => [],
        'existing_periods' => [],
        'skipped_overlap_periods' => [],
    ];

    if ($companyId <= 0) {
        return $result;
    }

    $filedPeriods = fetchCompaniesHouseFiledAccountingPeriods($pdo, $companyId, $companyNumber);
    $result['filed_period_count'] = count($filedPeriods);

    $findExact = $pdo->prepare(
        'SELECT id
         FROM tax_years
         WHERE company_id = ?
           AND period_start = ?
           AND period_end = ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $findOverlap = $pdo->prepare(
        'SELECT COUNT(*)
         FROM tax_years
         WHERE company_id = ?
           AND NOT (period_end < ? OR period_start > ?)
           AND NOT (period_start = ? AND period_end = ?)'
    );

    foreach ($filedPeriods as $period) {
        $periodStart = (string)$period['period_start'];
        $periodEnd = (string)$period['period_end'];

        $findExact->execute([$companyId, $periodStart, $periodEnd]);
        $existingId = $findExact->fetchColumn();

        if ($existingId !== false) {
            $result['existing_count']++;
            $result['existing_periods'][] = $period;
            continue;
        }

        /*
         * Filed iXBRL periods are reliable enough to seed accounting periods automatically,
         * but we still avoid overwriting or duplicating a different period the user already has.
         */
        $findOverlap->execute([$companyId, $periodStart, $periodEnd, $periodStart, $periodEnd]);

        if ((int)$findOverlap->fetchColumn() > 0) {
            $result['skipped_overlap_count']++;
            $result['skipped_overlap_periods'][] = $period;
            continue;
        }

        createAccountingPeriod($pdo, $companyId, $periodStart, $periodEnd);
        $result['created_count']++;
        $result['created_periods'][] = $period;
    }

    return $result;
}


function buildAccountingGuidance(PDO $pdo, array $settings, array $accountingPeriods): array {
    $guidance = [
        'incorporation_date' => (string)($settings['incorporation_date'] ?? ''),
        'incorporation_date_display' => '',
        'filed_periods' => [],
        'latest_filed_period_end' => '',
        'latest_filed_period_end_display' => '',
        'suggestion_basis' => '',
        'suggested_periods' => [],
        'missing_suggested_periods' => [],
        'ct_periods' => [],
        'ct600_summary' => '',
        'coverage' => ['months' => [], 'missing_months' => [], 'outside_period_count' => 0],
        'messages' => [],
    ];

    $guidance['incorporation_date_display'] = $guidance['incorporation_date'] !== ''
        ? formatDisplayDate($guidance['incorporation_date'], $settings)
        : '';

    $suggester = new AccountingPeriodSuggester();
    $companyId = (int)($settings['company_id'] !== '' ? $settings['company_id'] : 0);
    $companyNumber = (string)($settings['companies_house_number'] ?? '');
    $guidance['filed_periods'] = fetchCompaniesHouseFiledAccountingPeriods($pdo, $companyId, $companyNumber);

    if ($guidance['filed_periods'] !== []) {
        $latestFiledPeriod = end($guidance['filed_periods']);
        $guidance['latest_filed_period_end'] = (string)($latestFiledPeriod['period_end'] ?? '');
        $guidance['latest_filed_period_end_display'] = $guidance['latest_filed_period_end'] !== ''
            ? formatDisplayDate($guidance['latest_filed_period_end'], $settings)
            : '';
        $guidance['suggestion_basis'] = 'companies_house_filed_periods';
        // Filed iXBRL periods are used as the anchor so we only suggest genuinely later periods.
        $guidance['suggested_periods'] = $suggester->suggestFollowOnPeriodsThroughDate(
            new DateTimeImmutable($guidance['latest_filed_period_end']),
            new DateTimeImmutable('today')
        );
    } elseif ($guidance['incorporation_date'] !== '') {
        $guidance['suggestion_basis'] = 'incorporation_date';
        $guidance['suggested_periods'] = $suggester->suggestPeriodsThroughDate(
            new DateTimeImmutable($guidance['incorporation_date']),
            new DateTimeImmutable('today')
        );
    } else {
        $guidance['messages'][] = 'No incorporation date or filed iXBRL accounting periods are stored yet, so accounting-period guidance is limited.';

        return $guidance;
    }

    $guidance['missing_suggested_periods'] = $suggester->missingSuggestedPeriods($accountingPeriods, $guidance['suggested_periods']);

    foreach ($guidance['suggested_periods'] as &$period) {
        $period['display_range'] = formatDisplayDateRange($period['start'], $period['end'], $settings);
    }
    unset($period);

    if (!empty($guidance['missing_suggested_periods'])) {
        if ($guidance['suggestion_basis'] === 'companies_house_filed_periods') {
            $guidance['messages'][] = 'Suggested accounting periods now continue from the latest imported Companies House filed period, so only periods after the filed accounts are proposed.';
        } else {
            $guidance['messages'][] = 'Suggested accounting periods are based on the incorporation date and the month-end of the anniversary month. Confirm them before relying on them for filing.';
        }
    }

    if ($settings['period_start'] !== '' && $settings['period_end'] !== '') {
        $ctDeriver = new CtPeriodDeriver();
        $guidance['ct_periods'] = $ctDeriver->derive($settings['period_start'], $settings['period_end']);

        foreach ($guidance['ct_periods'] as &$period) {
            $period['display_range'] = formatDisplayDateRange($period['start'], $period['end'], $settings);
        }
        unset($period);
        $guidance['ct600_summary'] = count($guidance['ct_periods']) === 1
            ? 'This accounting period needs 1 CT600 return.'
            : 'This accounting period needs ' . count($guidance['ct_periods']) . ' CT600 returns because HMRC accounting periods cannot exceed 12 months.';

        $coverageService = new AccountingPeriodCoverageService();
        $guidance['coverage'] = $coverageService->summarise(
            $pdo,
            (int)($settings['company_id'] !== '' ? $settings['company_id'] : 0),
            (int)($settings['tax_year_id'] !== '' ? $settings['tax_year_id'] : 0),
            $settings['period_start'],
            $settings['period_end']
        );

        if (!empty($guidance['coverage']['missing_months'])) {
            $guidance['messages'][] = 'Some months inside the selected accounting period currently have no uploaded transactions.';
        }

        if (($guidance['coverage']['outside_period_count'] ?? 0) > 0) {
            $guidance['messages'][] = 'Some transactions linked to this accounting period currently sit outside the selected dates.';
        }

        $guidance['messages'][] = 'Editing the accounting period may affect both Companies House and HMRC obligations. Confirm the historic year-end before saving changes.';
    }

    if (count($guidance['messages']) > 1) {
        $guidance['messages'] = [implode(' ', $guidance['messages'])];
    }

    return $guidance;
}


function findExistingCompanyId(PDO $pdo, string $companyName, ?string $companyNumber = null): int {
    $companyName = trim($companyName);
    $companyNumber = $companyNumber !== null ? trim($companyNumber) : null;
    $companyNumber = $companyNumber !== '' ? $companyNumber : null;

    if ($companyNumber !== null) {
        $stmt = $pdo->prepare('SELECT id FROM companies WHERE company_number = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$companyNumber]);
        $companyId = $stmt->fetchColumn();

        if ($companyId !== false) {
            return (int)$companyId;
        }
    }

    if ($companyName === '') {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM companies
         WHERE company_name = ?
           AND ((company_number = ?) OR (company_number IS NULL AND ? IS NULL))
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        $companyName,
        $companyNumber,
        $companyNumber,
    ]);
    $companyId = $stmt->fetchColumn();

    return $companyId !== false ? (int)$companyId : 0;
}


function createCompany(
    PDO $pdo,
    string $companyName,
    ?string $companyNumber = null,
    ?string $incorporationDate = null,
    ?array $companiesHouseProfile = null,
    string $companiesHouseEnvironment = ''
): int {
    $companyName = trim($companyName);
    $companyNumber = $companyNumber !== null ? trim($companyNumber) : null;
    $incorporationDate = $incorporationDate !== null ? trim($incorporationDate) : null;
    $storedProfile = normaliseCompaniesHouseProfileForStorage($companiesHouseProfile, $companiesHouseEnvironment);

    if ($companyName === '') {
        throw new RuntimeException('Company name is required.');
    }

    $companyNumber = $companyNumber !== '' ? $companyNumber : null;
    $incorporationDate = $incorporationDate !== '' ? $incorporationDate : null;
    $existingCompanyId = findExistingCompanyId($pdo, $companyName, $companyNumber);

    if ($existingCompanyId > 0) {
        if ($incorporationDate !== null && companiesTableHasIncorporationDate($pdo) || (!empty($storedProfile) && companiesTableHasCompaniesHouseProfileColumns($pdo))) {
            $updateClauses = [];
            $params = [];

            if ($incorporationDate !== null && companiesTableHasIncorporationDate($pdo)) {
                $updateClauses[] = 'incorporation_date = ?';
                $params[] = $incorporationDate;
            }

            if (!empty($storedProfile) && companiesTableHasCompaniesHouseProfileColumns($pdo)) {
                foreach ($storedProfile as $column => $value) {
                    $updateClauses[] = $column . ' = ?';
                    $params[] = $column === 'companies_house_last_checked_at'
                        ? $value
                        : $value;
                }
                $updateClauses[] = 'companies_house_last_checked_at = CURRENT_TIMESTAMP';
            }

            $params[] = $existingCompanyId;
            $stmt = $pdo->prepare('UPDATE companies SET ' . implode(', ', $updateClauses) . ' WHERE id = ?');
            $stmt->execute($params);
        }

        return $existingCompanyId;
    }

    $pdo->beginTransaction();

    try {
        $columns = ['company_name', 'company_number'];
        $placeholders = ['?', '?'];
        $payload = [$companyName, $companyNumber];

        if (companiesTableHasIncorporationDate($pdo)) {
            $columns[] = 'incorporation_date';
            $placeholders[] = '?';
            $payload[] = $incorporationDate;
        }

        if (!empty($storedProfile) && companiesTableHasCompaniesHouseProfileColumns($pdo)) {
            foreach ($storedProfile as $column => $value) {
                $columns[] = $column;
                $placeholders[] = '?';
                $payload[] = $value;
            }
            $columns[] = 'companies_house_last_checked_at';
            $placeholders[] = 'CURRENT_TIMESTAMP';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO companies (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($payload);

        /*
         * MariaDB via ODBC may not support lastInsertId(), so reload by the
         * inserted values and take the latest matching row.
         */
        $stmt = $pdo->prepare(
            'SELECT id
             FROM companies
             WHERE company_name = ?
               AND ((company_number = ?) OR (company_number IS NULL AND ? IS NULL))
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            $companyName,
            $companyNumber,
            $companyNumber,
        ]);
        $companyId = $stmt->fetchColumn();

        if ($companyId === false) {
            throw new RuntimeException('Company was inserted but could not be reloaded.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    return (int)$companyId;
}


function fetchDashboardStats(PDO $pdo, int $companyId, int $taxYearId): array {
    $stats = [
        'bank_accounts' => 0,
        'unreconciled_items' => 0,
        'draft_journals' => 0,
        'vat_returns_due' => 0,
    ];

    if ($companyId <= 0) {
        return $stats;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company_accounts WHERE company_id = ? AND is_active = 1 AND account_type = 'bank'");
    $stmt->execute([$companyId]);
    $stats['bank_accounts'] = (int)$stmt->fetchColumn();

    if ($taxYearId > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE company_id = ? AND tax_year_id = ? AND category_status = 'uncategorised'");
        $stmt->execute([$companyId, $taxYearId]);
        $stats['unreconciled_items'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM journals WHERE company_id = ? AND tax_year_id = ? AND source_type = 'manual'");
        $stmt->execute([$companyId, $taxYearId]);
        $stats['draft_journals'] = (int)$stmt->fetchColumn();
    }

    return $stats;
}


function fetchRecentTransactions(PDO $pdo, int $companyId, int $taxYearId, ?int $defaultBankNominalId = null, int $limit = 12): array {
    if ($companyId <= 0 || $taxYearId <= 0) {
        return [];
    }

    $limit = max(1, min($limit, 100));
    $sql = "SELECT t.txn_date AS date,
                   COALESCE(NULLIF(t.source_account_label, ''), bank_na.name, 'Bank') AS account,
                   t.description,
                   COALESCE(cat_na.name, 'Uncategorised') AS category,
                   COALESCE(t.currency, '') AS currency,
                   t.amount,
                   CASE
                       WHEN t.category_status = 'uncategorised' THEN 'Needs review'
                       WHEN t.category_status = 'manual' THEN 'Posted'
                       ELSE 'Matched'
                   END AS status,
                   COALESCE(t.source_category, '') AS source_category,
                   COALESCE(t.document_download_status, 'skipped') AS document_status,
                   t.local_document_path,
                   t.source_document_url
            FROM transactions t
            LEFT JOIN nominal_accounts bank_na ON bank_na.id = ?
            LEFT JOIN nominal_accounts cat_na ON cat_na.id = t.nominal_account_id
            WHERE t.company_id = ? AND t.tax_year_id = ?
            ORDER BY t.txn_date DESC, t.id DESC
            LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $defaultBankNominalId,
        $companyId,
        $taxYearId,
    ]);
    return $stmt->fetchAll();
}


function fetchUploadHistory(PDO $pdo, int $companyId, int $taxYearId, ?int $limit = null, int $offset = 0): array {
    if ($companyId <= 0) {
        return [];
    }

    $offset = max(0, $offset);
    $sql = "SELECT su.id,
                   su.uploaded_at AS uploaded_at_sort,
                   DATE_FORMAT(su.uploaded_at, '%Y-%m-%d %H:%i') AS uploaded_at,
                   su.source_type,
                   su.workflow_status,
                   su.original_filename AS filename,
                   CASE
                       WHEN su.date_range_start IS NOT NULL AND su.date_range_end IS NOT NULL
                           THEN CONCAT(DATE_FORMAT(su.date_range_start, '%d/%m/%Y'), ' to ', DATE_FORMAT(su.date_range_end, '%d/%m/%Y'))
                       ELSE DATE_FORMAT(su.statement_month, '%b %Y')
                   END AS month,
                   su.rows_committed AS inserted,
                   su.rows_duplicate AS duplicates,
                   su.rows_valid,
                   su.rows_invalid,
                   su.rows_ready_to_import,
                   su.rows_parsed,
                   su.stored_filename AS stored_filename,
                   su.source_headers_json,
                   su.account_id,
                   COALESCE(ca.account_name, '') AS account_name,
                   COALESCE(ca.account_type, '') AS account_type,
                   COALESCE(sim.original_headers_json, '') AS mapping_headers_json
            FROM statement_uploads su
            LEFT JOIN company_accounts ca
                ON ca.id = su.account_id
               AND ca.company_id = su.company_id
            LEFT JOIN statement_import_mappings sim
                ON sim.upload_id = su.id
            WHERE su.company_id = ?";

    $params = [$companyId];

    if ($taxYearId > 0) {
        $sql .= " AND su.tax_year_id = ?";
        $params[] = $taxYearId;
    }

    $sql .= "
            ORDER BY su.uploaded_at DESC, su.id DESC";

    if ($limit !== null) {
        $limit = max(1, min($limit, 500));
        $sql .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}


function buildMonthStatus(PDO $pdo, int $companyId, int $taxYearId): array {
    if ($companyId <= 0 || $taxYearId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT label, period_start, period_end FROM tax_years WHERE id = ? AND company_id = ?');
    $stmt->execute([$taxYearId, $companyId]);
    $taxYear = $stmt->fetch();

    if (!is_array($taxYear) || empty($taxYear['period_start']) || empty($taxYear['period_end'])) {
        return [];
    }

    $summaryStmt = $pdo->prepare("SELECT DATE_FORMAT(txn_date, '%Y-%m-01') AS month_key,
                                         COUNT(*) AS txn_count,
                                         SUM(CASE WHEN category_status = 'uncategorised' THEN 1 ELSE 0 END) AS uncategorised_count,
                                         SUM(CASE WHEN is_auto_excluded = 1 THEN 1 ELSE 0 END) AS deferred_count,
                                         SUM(
                                             CASE
                                                 WHEN category_status IN ('auto', 'manual')
                                                   AND nominal_account_id IS NOT NULL
                                                   AND NOT EXISTS (
                                                       SELECT 1
                                                       FROM journals j
                                                       WHERE j.source_type = 'bank_csv'
                                                         AND j.source_ref = CONCAT('transaction:', transactions.id)
                                                   )
                                                 THEN 1
                                                 ELSE 0
                                             END
                                         ) AS ready_to_post_count
                                  FROM transactions
                                  WHERE company_id = ? AND tax_year_id = ?
                                  GROUP BY DATE_FORMAT(txn_date, '%Y-%m-01')
                                  ORDER BY month_key");
    $summaryStmt->execute([$companyId, $taxYearId]);
    $summaries = [];
    foreach ($summaryStmt->fetchAll() as $row) {
        $summaries[$row['month_key']] = $row;
    }

    $months = [];
    $cursor = new DateTime((string)$taxYear['period_start']);
    $end = new DateTime((string)$taxYear['period_end']);
    $cursor->modify('first day of this month');
    $end->modify('first day of this month');

    while ($cursor <= $end) {
        $monthKey = $cursor->format('Y-m-01');
        $txnCount = isset($summaries[$monthKey]) ? (int)$summaries[$monthKey]['txn_count'] : 0;
        $uncatCount = isset($summaries[$monthKey]) ? (int)$summaries[$monthKey]['uncategorised_count'] : 0;
        $deferredCount = isset($summaries[$monthKey]) ? (int)$summaries[$monthKey]['deferred_count'] : 0;
        $readyToPostCount = isset($summaries[$monthKey]) ? (int)$summaries[$monthKey]['ready_to_post_count'] : 0;

        if ($txnCount === 0) {
            $status = 'red';
        } elseif ($uncatCount > 0 || $deferredCount > 0) {
            $status = 'amber';
        } else {
            $status = 'green';
        }

        $months[] = [
            'month' => $cursor->format('M'),
            'year' => $cursor->format('Y'),
            'month_key' => $monthKey,
            'label' => $cursor->format('M Y'),
            'status' => $status,
            'status_colour' => $status,
            'transactions' => $txnCount,
            'uncategorised' => $uncatCount,
            'deferred' => $deferredCount,
            'ready_to_post' => $readyToPostCount,
        ];

        $cursor->modify('+1 month');
    }

    return $months;
}


function normaliseTransactionMonthFilter(?string $monthKey): string {
    $monthKey = trim((string)$monthKey);

    return preg_match('/^\d{4}-\d{2}-01$/', $monthKey) === 1 ? $monthKey : '';
}


function normaliseTransactionCategoryFilter(?string $filter): string {
    $filter = trim((string)$filter);

    return in_array($filter, ['all', 'uncategorised', 'auto', 'manual'], true) ? $filter : 'all';
}


function defaultTransactionMonth(array $monthStatus): string {
    $currentMonthKey = (new DateTimeImmutable('first day of this month'))->format('Y-m-01');

    foreach ($monthStatus as $month) {
        if ((string)($month['month_key'] ?? '') === $currentMonthKey) {
            return $currentMonthKey;
        }
    }

    foreach ($monthStatus as $month) {
        if ((int)($month['uncategorised'] ?? 0) > 0 && !empty($month['month_key'])) {
            return (string)$month['month_key'];
        }
    }

    foreach ($monthStatus as $month) {
        if ((int)($month['transactions'] ?? 0) > 0 && !empty($month['month_key'])) {
            return (string)$month['month_key'];
        }
    }

    return isset($monthStatus[0]['month_key']) ? (string)$monthStatus[0]['month_key'] : '';
}


function fetchTransactionsForMonth(
    PDO $pdo,
    int $companyId,
    int $taxYearId,
    string $monthKey,
    string $categoryFilter = 'all',
    int $limit = 500
): array {
    if ($companyId <= 0 || $taxYearId <= 0) {
        return [];
    }

    $monthKey = normaliseTransactionMonthFilter($monthKey);
    $categoryFilter = normaliseTransactionCategoryFilter($categoryFilter);
    $limit = max(1, min($limit, 1000));

    $where = [
        't.company_id = :company_id',
        't.tax_year_id = :tax_year_id',
    ];
    $params = [
        'company_id' => $companyId,
        'tax_year_id' => $taxYearId,
    ];

    if ($monthKey !== '') {
        $monthStart = new DateTimeImmutable($monthKey);
        $monthEnd = $monthStart->modify('last day of this month');
        $where[] = 't.txn_date BETWEEN :month_start AND :month_end';
        $params['month_start'] = $monthStart->format('Y-m-d');
        $params['month_end'] = $monthEnd->format('Y-m-d');
    }

    if ($categoryFilter !== 'all') {
        $where[] = 't.category_status = :category_status';
        $params['category_status'] = $categoryFilter;
    }

    $sql = "SELECT t.id,
                   t.account_id,
                   t.txn_date,
                   COALESCE(t.txn_type, '') AS txn_type,
                   t.description,
                   t.amount,
                   COALESCE(t.currency, '') AS currency,
                   COALESCE(t.source_account_label, '') AS source_account,
                   COALESCE(t.source_category, '') AS source_category,
                   COALESCE(t.source_document_url, '') AS source_document_url,
                   COALESCE(t.local_document_path, '') AS local_document_path,
                   COALESCE(t.document_download_status, 'skipped') AS document_download_status,
                   COALESCE(t.document_error, '') AS document_error,
                   t.nominal_account_id,
                   t.transfer_account_id,
                   COALESCE(t.is_internal_transfer, 0) AS is_internal_transfer,
                   COALESCE(ca.internal_transfer_marker, '') AS internal_transfer_marker,
                   COALESCE(ca.account_name, '') AS owned_account_name,
                   COALESCE(ta.account_name, '') AS transfer_account_name,
                   COALESCE(na.name, '') AS assigned_nominal,
                   t.category_status,
                   COALESCE(t.auto_rule_id, 0) AS auto_rule_id,
                   COALESCE(cr.match_value, '') AS auto_rule_match_value,
                   COALESCE(t.is_auto_excluded, 0) AS is_auto_excluded,
                   EXISTS(
                       SELECT 1
                       FROM journals j
                       WHERE j.source_type = 'bank_csv'
                         AND j.source_ref = CONCAT('transaction:', t.id)
                   ) AS has_derived_journal
            FROM transactions t
            LEFT JOIN company_accounts ca ON ca.id = t.account_id
            LEFT JOIN company_accounts ta ON ta.id = t.transfer_account_id
            LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
            LEFT JOIN categorisation_rules cr ON cr.id = t.auto_rule_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.txn_date DESC, t.id DESC
            LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}


function fetchActionQueue(PDO $pdo, int $companyId, int $taxYearId): array {
    if ($companyId <= 0 || $taxYearId <= 0) {
        return [];
    }

    $items = [];

    $stmt = $pdo->prepare("SELECT COUNT(*)
                           FROM transactions
                           WHERE company_id = ?
                             AND tax_year_id = ?
                             AND category_status = 'uncategorised'");
    $stmt->execute([$companyId, $taxYearId]);
    $uncategorisedCount = (int)$stmt->fetchColumn();
    if ($uncategorisedCount > 0) {
        $items[] = [
            'title' => 'Categorise uncategorised transactions',
            'detail' => $uncategorisedCount . ' transaction' . ($uncategorisedCount === 1 ? '' : 's') . ' still need a nominal account.',
        ];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*)
                           FROM statement_uploads
                           WHERE company_id = ?
                             AND tax_year_id = ?
                             AND rows_duplicate > 0");
    $stmt->execute([$companyId, $taxYearId]);
    $duplicateUploads = (int)$stmt->fetchColumn();
    if ($duplicateUploads > 0) {
        $items[] = [
            'title' => 'Review duplicate upload hits',
            'detail' => $duplicateUploads . ' upload' . ($duplicateUploads === 1 ? '' : 's') . ' reported duplicate rows.',
        ];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*)
                           FROM statement_uploads
                           WHERE company_id = ?
                             AND tax_year_id = ?
                             AND rows_inserted = 0");
    $stmt->execute([$companyId, $taxYearId]);
    $emptyUploads = (int)$stmt->fetchColumn();
    if ($emptyUploads > 0) {
        $items[] = [
            'title' => 'Check empty imports',
            'detail' => $emptyUploads . ' upload' . ($emptyUploads === 1 ? '' : 's') . ' inserted no rows and may need inspection.',
        ];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*)
                           FROM transactions
                           WHERE company_id = ?
                             AND tax_year_id = ?
                             AND statement_upload_id IS NULL");
    $stmt->execute([$companyId, $taxYearId]);
    $manualTransactions = (int)$stmt->fetchColumn();
    if ($manualTransactions > 0) {
        $items[] = [
            'title' => 'Review manually added transactions',
            'detail' => $manualTransactions . ' transaction' . ($manualTransactions === 1 ? '' : 's') . ' are not tied to an uploaded statement.',
        ];
    }

    if (empty($items)) {
        $items[] = [
            'title' => 'No immediate actions',
            'detail' => 'This period looks tidy. The accounting gremlins appear to be off shift.',
        ];
    }

    return array_slice($items, 0, 6);
}


function hasCompanySettingsRow(PDO $pdo, int $companyId): bool {
    if ($companyId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM company_settings WHERE company_id = ?');
    $stmt->execute([$companyId]);

    return (int)$stmt->fetchColumn() > 0;
}
