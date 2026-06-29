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
            'director_loan_nominal_id' => '30',
            'vat_nominal_id' => '',
            'uncategorised_nominal_id' => '50',
        ], [
            ['id' => 10, 'code' => '1200', 'name' => 'Bank'],
            ['id' => 15, 'code' => '2300', 'name' => 'Trade Creditors'],
            ['id' => 20, 'code' => '5000', 'name' => 'Expenses'],
            ['id' => 30, 'code' => '2100', 'name' => 'Director Loan'],
            ['id' => 50, 'code' => '9999', 'name' => 'Uncategorised'],
        ]);

        $harness->assertSame(true, str_contains($message, 'Default bank: 1200 - Bank'));
        $harness->assertSame(true, str_contains($message, '<br>Saved:<br>'));
        $harness->assertSame(true, str_contains($message, 'Default trade: 2300 - Trade Creditors'));
        $harness->assertSame(true, str_contains($message, 'Default expense: 5000 - Expenses'));
        $harness->assertSame(true, str_contains($message, 'Director loan: 2100 - Director Loan'));
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
        (new \eel_accounts\Service\AccountingContextService())->setPageContext(1, 'Test Company', '00000000', 0);
        $settingsStore = new \eel_accounts\Store\CompanySettingsStore(1);
        foreach ([
            'default_bank_nominal_id',
            'default_trade_nominal_id',
            'default_expense_nominal_id',
            'director_loan_nominal_id',
            'vat_nominal_id',
            'uncategorised_nominal_id',
        ] as $index => $setting) {
            $settingsStore->set($setting, 9000 + $index, 'int');
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
