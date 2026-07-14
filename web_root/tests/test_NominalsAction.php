<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(NominalsAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof NominalsAction) {
        throw new RuntimeException('Unexpected NominalsAction instance.');
    }

    $harness->check('NominalsAction', 'implements the action interface', function () use ($harness, $instance): void {
        $harness->assertSame(true, $instance instanceof ActionInterfaceFramework);
    });

    $harness->check('NominalsAction', 'saved nominal defaults message lists each saved default', function () use ($harness, $instance): void {
        $method = new ReflectionMethod(NominalsAction::class, 'savedNominalsMessageHtml');
        $method->setAccessible(true);

        $message = $method->invoke($instance, [
            'default_bank_nominal_id' => '10',
            'default_trade_nominal_id' => '15',
            'default_expense_nominal_id' => '20',
            'tools_small_equipment_nominal_id' => '21',
            'prepayment_asset_nominal_id' => '22',
            'director_loan_asset_nominal_id' => '30',
            'director_loan_liability_nominal_id' => '31',
            'vat_nominal_id' => '',
            'uncategorised_nominal_id' => '50',
        ], [
            ['id' => 10, 'code' => '1200', 'name' => 'Bank'],
            ['id' => 15, 'code' => '2300', 'name' => 'Trade Creditors'],
            ['id' => 20, 'code' => '5000', 'name' => 'Expenses'],
            ['id' => 21, 'code' => '6070', 'name' => 'Tools & Small Equipment'],
            ['id' => 22, 'code' => '1150', 'name' => 'Prepayments'],
            ['id' => 30, 'code' => '1200', 'name' => 'Director Loan Asset'],
            ['id' => 31, 'code' => '2100', 'name' => 'Director Loan Liability'],
            ['id' => 50, 'code' => '9999', 'name' => 'Uncategorised'],
        ]);

        $harness->assertSame(true, str_contains($message, 'Default bank: 1200 - Bank'));
        $harness->assertSame(true, str_contains($message, '<br>Saved:<br>'));
        $harness->assertSame(true, str_contains($message, 'Default trade: 2300 - Trade Creditors'));
        $harness->assertSame(true, str_contains($message, 'Expense claims payable: 5000 - Expenses'));
        $harness->assertSame(true, str_contains($message, 'Tools &amp; Small Equipment: 6070 - Tools &amp; Small Equipment'));
        $harness->assertSame(true, str_contains($message, 'Prepayments asset: 1150 - Prepayments'));
        $harness->assertSame(true, str_contains($message, 'Director loan asset: 1200 - Director Loan Asset'));
        $harness->assertSame(true, str_contains($message, 'Director loan liability: 2100 - Director Loan Liability'));
        $harness->assertSame(true, str_contains($message, 'VAT control: Unassigned'));
        $harness->assertSame(true, str_contains($message, 'Fallback uncategorised: 9999 - Uncategorised'));
    });

    $harness->check('NominalsAction', 'save_nominals requires a selected company', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            ['card_action' => 'Nominals', 'intent' => 'save_nominals'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('Select a company before saving nominal defaults.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('NominalsAction', 'apply_nominal_suggestions requires a selected company', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            ['card_action' => 'Nominals', 'intent' => 'apply_nominal_suggestions'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('Select a company before applying suggested nominal defaults.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('NominalsAction', 'returns a validation error when no complete suggestion set is available', function () use ($harness, $instance): void {
        authenticateTestSession();
        $companyId = nominalsActionInsertCompany('Incomplete Nominal Suggestions Fixture Limited');
        (new \eel_accounts\Service\AccountingContextService())->setPageContext($companyId, 'Test Company', '00000000', 0);
        $settingsStore = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $prepaymentNominalId = (int)InterfaceDB::fetchColumn(
            'SELECT na.id
             FROM nominal_accounts na
             INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.account_type = :account_type
               AND na.is_active = 1
               AND nas.code = :subtype
             ORDER BY na.id ASC
             LIMIT 1',
            ['account_type' => 'asset', 'subtype' => 'prepayments']
        );
        if ($prepaymentNominalId <= 0) {
            $prepaymentSubtypeId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM nominal_account_subtypes WHERE code = :code',
                ['code' => 'prepayments']
            );
            if ($prepaymentSubtypeId <= 0) {
                InterfaceDB::prepareExecute(
                    'INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
                     VALUES (:code, :name, :parent_account_type, :sort_order, 1)',
                    [
                        'code' => 'prepayments',
                        'name' => 'Prepayments',
                        'parent_account_type' => 'asset',
                        'sort_order' => 1150,
                    ]
                );
                $prepaymentSubtypeId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM nominal_account_subtypes WHERE code = :code',
                    ['code' => 'prepayments']
                );
            }
            InterfaceDB::prepareExecute(
                'INSERT INTO nominal_accounts (
                    code, name, account_type, account_subtype_id,
                    tax_treatment, is_active, sort_order
                 ) VALUES (
                    :code, :name, :account_type, :account_subtype_id,
                    :tax_treatment, 1, :sort_order
                 )',
                [
                    'code' => '1150',
                    'name' => 'Prepayments',
                    'account_type' => 'asset',
                    'account_subtype_id' => $prepaymentSubtypeId,
                    'tax_treatment' => 'other',
                    'sort_order' => 1150,
                ]
            );
            $prepaymentNominalId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM nominal_accounts WHERE code = :code',
                ['code' => '1150']
            );
        }
        foreach ([
            'default_bank_nominal_id',
            'default_trade_nominal_id',
            'default_expense_nominal_id',
            'tools_small_equipment_nominal_id',
            'prepayment_asset_nominal_id',
            'director_loan_asset_nominal_id',
            'director_loan_liability_nominal_id',
            'vat_nominal_id',
            'uncategorised_nominal_id',
        ] as $index => $setting) {
            $settingsStore->set(
                $setting,
                $setting === 'prepayment_asset_nominal_id' ? $prepaymentNominalId : 9000 + $index,
                'int'
            );
        }
        $settingsService = new \eel_accounts\Service\CompanySettingsService();
        $suggestionMethod = new ReflectionMethod($settingsService, 'buildNominalDefaultSuggestions');
        $suggestionMethod->setAccessible(true);
        $nominalAccounts = (new \eel_accounts\Repository\NominalAccountRepository())->fetchNominalAccounts($companyId);
        foreach ((array)$suggestionMethod->invoke($settingsService, $nominalAccounts) as $setting => $nominal) {
            if (isset($nominal['id'])) {
                $settingsStore->set((string)$setting, (int)$nominal['id'], 'int');
            }
        }
        $settingsStore->flush();

        $request = new RequestFramework(
            [],
            ['card_action' => 'Nominals', 'intent' => 'apply_nominal_suggestions'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('No complete nominal suggestion set is currently available.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('NominalsAction', 'delete_nominal_account requires developer options', function () use ($harness, $instance): void {
        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        try {
            AppConfigurationStore::set('developer_options', false);

            $request = new RequestFramework(
                [],
                ['card_action' => 'Nominals', 'intent' => 'delete_nominal_account', 'nominal_account_id' => '1'],
                ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
                [],
                [],
                null
            );

            $result = $instance->handle($request, createTestPageServiceFramework());

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame('Developer options must be enabled before nominal accounts can be deleted.', (string)($result->flashMessages()[0]['message'] ?? ''));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });

    $harness->check('NominalsAction', 'delete_nominal_account rejects referenced nominal accounts', function () use ($harness, $instance): void {
        ensureNominalsActionReferenceSchema();

        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        $nominalId = nominalsActionInsertNominal('Referenced Action Delete Fixture');
        $companyId = nominalsActionInsertCompany('Referenced Action Delete Fixture Limited');

        try {
            AppConfigurationStore::set('developer_options', true);

            InterfaceDB::prepareExecute(
                'INSERT INTO company_settings (company_id, setting, type, value)
                 VALUES (:company_id, :setting, :type, :value)',
                [
                    'company_id' => $companyId,
                    'setting' => 'default_bank_nominal_id',
                    'type' => 'int',
                    'value' => (string)$nominalId,
                ]
            );

            $request = new RequestFramework(
                [],
                ['card_action' => 'Nominals', 'intent' => 'delete_nominal_account', 'nominal_account_id' => (string)$nominalId],
                ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
                [],
                [],
                null
            );

            $result = $instance->handle($request, createTestPageServiceFramework());

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame('This nominal account is in use and cannot be deleted.', (string)($result->flashMessages()[0]['message'] ?? ''));
            $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM nominal_accounts WHERE id = :id', ['id' => $nominalId]));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });

    $harness->check('NominalsAction', 'delete_nominal_account deletes unused nominal accounts', function () use ($harness, $instance): void {
        ensureNominalsActionReferenceSchema();

        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        $nominalId = nominalsActionInsertNominal('Unused Action Delete Fixture');

        try {
            AppConfigurationStore::set('developer_options', true);

            $request = new RequestFramework(
                [],
                ['card_action' => 'Nominals', 'intent' => 'delete_nominal_account', 'nominal_account_id' => (string)$nominalId],
                ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
                [],
                [],
                null
            );

            $result = $instance->handle($request, createTestPageServiceFramework());

            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM nominal_accounts WHERE id = :id', ['id' => $nominalId]));
            $harness->assertSame('nominals_accounts', (string)($result->query()['show_card'] ?? ''));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });
});

clearAuthenticatedTestSession();

function nominalsActionInsertNominal(string $name): int
{
    $code = '9A' . strtoupper(substr(hash('sha256', $name . microtime(true)), 0, 6));

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => 'asset',
            'tax_treatment' => 'other',
            'sort_order' => 9000,
        ]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code', ['code' => $code]);
}

function nominalsActionInsertCompany(string $companyName): int
{
    $number = 'NA' . strtoupper(substr(hash('sha256', $companyName . microtime(true)), 0, 8));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:name, :number)',
        ['name' => $companyName, 'number' => $number]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => $number]);
}

function ensureNominalsActionReferenceSchema(): void
{
    if (!InterfaceDB::tableExists('corporation_tax_treatment_rules')) {
        InterfaceDB::execute(
            'CREATE TABLE corporation_tax_treatment_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nominal_account_id INTEGER NULL
            )'
        );
    }
}
