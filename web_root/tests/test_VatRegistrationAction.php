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
$harness->run(VatRegistrationAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof VatRegistrationAction) {
        throw new RuntimeException('Unexpected VatRegistrationAction instance.');
    }

    $harness->check('VatRegistrationAction', 'implements the action interface', function () use ($harness, $instance): void {
        $harness->assertSame(true, $instance instanceof ActionInterfaceFramework);
    });

    $harness->check('VatRegistrationAction', 'requires a selected company', function () use ($harness, $instance): void {
        (new \eel_accounts\Service\AccountingContextService())->clearPageContext();

        $request = new RequestFramework(
            [],
            ['card_action' => 'VatRegistration', 'intent' => 'save_vat'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('Select a company before updating VAT registration.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('VatRegistrationAction', 'save_vat rejects submitted VAT details until validation matched', function () use ($harness, $instance): void {
        authenticateTestSession();

        try {
            $companyNumber = 'VAT' . strtoupper(substr(hash('sha256', (string)microtime(true)), 0, 8));
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                ['company_name' => 'VAT Fixture Limited', 'company_number' => $companyNumber]
            );

            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );

            (new \eel_accounts\Service\AccountingContextService())->setPageContext(
                $companyId,
                'VAT Fixture Limited',
                $companyNumber,
                0
            );

            $request = new RequestFramework(
                [],
                [
                    'card_action' => 'VatRegistration',
                    'intent' => 'save_vat',
                    'company_id' => (string)$companyId,
                    'is_vat_registered' => '1',
                    'vat_country_code' => 'gb',
                    'vat_number' => ' 123 456 789 ',
                ],
                ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
                [],
                [],
                null
            );

            $result = $instance->handle($request, createTestPageServiceFramework());
            $row = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId);

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'Check the VAT number'));
            $harness->assertSame(0, (int)($row['is_vat_registered'] ?? 0));
            $harness->assertSame('', (string)($row['vat_country_code'] ?? ''));
            $harness->assertSame('', (string)($row['vat_number'] ?? ''));
            $harness->assertSame('', (string)($row['vat_validation_status'] ?? ''));
        } finally {
            clearAuthenticatedTestSession();
        }
    });
});
