<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(eel_accountsServiceApiCredentialCatalogProvider::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(eel_accountsServiceApiCredentialCatalogProvider::class, 'declares REST lookup and VAT credentials plus XML filing credentials', static function () use ($harness): void {
        $entries = (new eel_accountsServiceApiCredentialCatalogProvider())->credentialCatalog();

        $harness->assertSame(true, in_array([
            'provider' => 'COMPANIESHOUSE',
            'gateway' => 'REST',
            'tag' => 'COMPANY_LOOKUP',
            'environment' => 'TEST',
        ], $entries, true));
        $harness->assertSame(true, in_array([
            'provider' => 'HMRC',
            'gateway' => 'REST',
            'tag' => 'VAT_CHECK',
            'environment' => 'LIVE',
        ], $entries, true));
        $harness->assertSame(true, in_array([
            'provider' => 'COMPANIESHOUSE',
            'gateway' => 'XML',
            'tag' => 'ACCOUNTS_FILING_PRESENTER_ID',
            'environment' => 'TEST',
        ], $entries, true));
        $harness->assertSame(true, in_array([
            'provider' => 'HMRC',
            'gateway' => 'XML',
            'tag' => 'CT600_XML',
            'environment' => 'LIVE',
        ], $entries, true));

        $catalog = new \ApiCredentialCatalogService([
            \eel_accounts\Service\ApiCredentialCatalogProvider::class,
        ]);
        $harness->assertSame([
            'provider' => 'COMPANIESHOUSE',
            'gateway' => 'REST',
            'tag' => 'COMPANY_LOOKUP',
            'environment' => 'TEST',
        ], $catalog->requireAllowed('companieshouse', 'rest', 'company_lookup', 'test'));
        $harness->assertSame([
            'provider' => 'COMPANIESHOUSE',
            'gateway' => 'XML',
            'tag' => 'ACCOUNTS_FILING_PRESENTER_ID',
            'environment' => 'TEST',
        ], $catalog->requireAllowed('companieshouse', 'xml', 'accounts_filing_presenter_id', 'test'));
    });
});
