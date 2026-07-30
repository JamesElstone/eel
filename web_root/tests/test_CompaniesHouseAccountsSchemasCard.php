<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'tax_companies_house_accounts_schemas.php';

$harness = new GeneratedServiceClassTestHarness();
$card = new _tax_companies_house_accounts_schemasCard();
$harness->check($card::class, 'renders verified file status and refresh control', static function () use ($harness, $card): void {
    $html = $card->render(['services'=>['companies_house_accounts_schemas'=>[
        'state'=>[
            'ready'=>true,
            'file_count'=>17,
            'checked_at'=>'2026-07-21 12:00:00',
            'validation_profile'=>'libxml-v1',
            'validation_verified_at'=>'2026-07-21 12:01:00',
        ],
        'files'=>[[
            'schema_name'=>'FormSubmission-v2-11.xsd',
            'file_role'=>'profile_root',
            'relative_path'=>'v1-0/schema/forms/FormSubmission-v2-11.xsd',
            'catalogue_status'=>'live',
            'sha256'=>str_repeat('b',64),
            'validation_profile'=>'libxml-v1',
            'validation_sha256'=>str_repeat('c',64),
            'verified_at'=>'2026-07-21 12:00:00',
            'validation_verified_at'=>'2026-07-21 12:01:00',
        ]],
    ]]]);
    $harness->assertTrue(str_contains($html, 'refresh_companies_house_accounts_schemas'));
    $harness->assertTrue(str_contains($html, 'FormSubmission-v2-11.xsd'));
    $harness->assertTrue(str_contains($html, 'v1-0/schema/forms/FormSubmission-v2-11.xsd'));
    $harness->assertTrue(str_contains($html, str_repeat('b',64)));
    $harness->assertTrue(str_contains($html, str_repeat('c',64)));
    $harness->assertTrue(str_contains($html, 'Validation profile'));
    $harness->assertTrue(str_contains($html, 'Last compilation'));
    $harness->assertTrue(str_contains($html, 'Refresh Companies House Filing Schema'));
    $harness->assertFalse(str_contains(strtolower($html), 'snapshot'));
    $harness->assertFalse(str_contains(strtolower($html), 'manifest'));
});
