<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'tax_companies_house_accounts_schemas.php';

$harness = new GeneratedServiceClassTestHarness();
$card = new _tax_companies_house_accounts_schemasCard();
$harness->check($card::class, 'renders verified profile status and refresh control', static function () use ($harness, $card): void {
    $html = $card->render(['services'=>['companies_house_accounts_schemas'=>[
        'snapshot'=>['file_count'=>12,'checked_at'=>'2026-07-21 12:00:00','manifest_sha256'=>str_repeat('a',64)],
        'roots'=>['form_submission'=>'https://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/FormSubmission-v2-11.xsd'],
    ]]]);
    $harness->assertTrue(str_contains($html, 'refresh_companies_house_accounts_schemas'));
    $harness->assertTrue(str_contains($html, 'FormSubmission-v2-11.xsd'));
    $harness->assertTrue(str_contains($html, str_repeat('a',64)));
});
