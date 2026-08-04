<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$className = \eel_accounts\Service\IxbrlAuthorityProfile::class;

$harness->check($className, 'provides a stable serialised profile and fingerprint', static function () use ($harness): void {
    $profile = new \eel_accounts\Service\IxbrlAuthorityProfile(
        'test_accounts',
        'TEST_AUTHORITY',
        '1.2.3',
        'https://example.test/transforms/1',
        ['numdotdecimal'],
        [
            'xml_declaration_mode' => 'exact',
            'document_prefix' => '<?xml version="1.0"?>' . "\n",
        ]
    );

    $harness->assertSame('test_accounts', $profile->key());
    $harness->assertSame('TEST_AUTHORITY', $profile->authority());
    $harness->assertSame('1.2.3', $profile->version());
    $harness->assertSame(['numdotdecimal'], $profile->allowedTransforms());
    $harness->assertSame([], $profile->factPolicy());
    $harness->assertFalse(array_key_exists('fact_policy', $profile->toArray()));
    $harness->assertSame(64, strlen($profile->fingerprint()));
    $harness->assertSame($profile->fingerprint(), $profile->fingerprint());
    $harness->assertSame('test_accounts', $profile->toArray()['key']);
});

$harness->check($className, 'includes an optional versioned fact policy in its serialization and fingerprint', static function () use ($harness): void {
    $base = new \eel_accounts\Service\IxbrlAuthorityProfile(
        'test_computation',
        'TEST_AUTHORITY',
        '1.0.0',
        'https://example.test/transforms/1',
        ['numdotdecimal'],
        ['xml_declaration_mode' => 'exact']
    );
    $withFacts = new \eel_accounts\Service\IxbrlAuthorityProfile(
        'test_computation',
        'TEST_AUTHORITY',
        '1.0.0',
        'https://example.test/transforms/1',
        ['numdotdecimal'],
        ['xml_declaration_mode' => 'exact'],
        [
            'version' => 'mandatory-facts-v1',
            'allowed_namespaces' => ['https://example.test/taxonomy/1'],
            'required_facts' => [['local_name' => 'CompanyName']],
        ]
    );

    $harness->assertSame('mandatory-facts-v1', $withFacts->factPolicy()['version']);
    $harness->assertSame($withFacts->factPolicy(), $withFacts->toArray()['fact_policy']);
    $harness->assertFalse(hash_equals($base->fingerprint(), $withFacts->fingerprint()));
});

$harness->check($className, 'rejects malformed profile definitions', static function () use ($harness): void {
    $invalidFactories = [
        static fn() => new \eel_accounts\Service\IxbrlAuthorityProfile(
            'Bad Key', 'HMRC', '1', 'https://example.test/transforms', ['numdotdecimal'], ['xml_declaration_mode' => 'exact']
        ),
        static fn() => new \eel_accounts\Service\IxbrlAuthorityProfile(
            'valid_key', 'HMRC', '1', 'not-a-url', ['numdotdecimal'], ['xml_declaration_mode' => 'exact']
        ),
        static fn() => new \eel_accounts\Service\IxbrlAuthorityProfile(
            'valid_key', 'HMRC', '1', 'https://example.test/transforms', [], ['xml_declaration_mode' => 'exact']
        ),
        static fn() => new \eel_accounts\Service\IxbrlAuthorityProfile(
            'valid_key', 'HMRC', '1', 'https://example.test/transforms', ['invalid:name'], ['xml_declaration_mode' => 'exact']
        ),
        static fn() => new \eel_accounts\Service\IxbrlAuthorityProfile(
            'valid_key',
            'HMRC',
            '1',
            'https://example.test/transforms',
            ['numdotdecimal'],
            ['xml_declaration_mode' => 'exact'],
            ['version' => '', 'allowed_namespaces' => ['https://example.test/taxonomy'], 'required_facts' => [['local_name' => 'CompanyName']]]
        ),
        static fn() => new \eel_accounts\Service\IxbrlAuthorityProfile(
            'valid_key',
            'HMRC',
            '1',
            'https://example.test/transforms',
            ['numdotdecimal'],
            ['xml_declaration_mode' => 'exact'],
            ['version' => 'v1', 'allowed_namespaces' => [], 'required_facts' => [['local_name' => 'CompanyName']]]
        ),
        static fn() => new \eel_accounts\Service\IxbrlAuthorityProfile(
            'valid_key',
            'HMRC',
            '1',
            'https://example.test/transforms',
            ['numdotdecimal'],
            ['xml_declaration_mode' => 'exact'],
            ['version' => 'v1', 'allowed_namespaces' => ['https://example.test/taxonomy'], 'required_facts' => [['local_name' => 'Bad:Name']]]
        ),
    ];

    foreach ($invalidFactories as $factory) {
        $harness->assertThrows($factory, InvalidArgumentException::class);
    }
});
