<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(FieldValidationFramework::class, 'normalises supported validation type aliases', function () use ($harness): void {
    $harness->assertSame('boolean', FieldValidationFramework::normaliseType('bool'));
    $harness->assertSame('boolean', FieldValidationFramework::normaliseType('boolean'));
    $harness->assertSame('int', FieldValidationFramework::normaliseType('integer'));
    $harness->assertSame('float', FieldValidationFramework::normaliseType('decimal'));
    $harness->assertSame('float', FieldValidationFramework::normaliseType('number'));
    $harness->assertSame('ascii', FieldValidationFramework::normaliseType('string'));
    $harness->assertSame('ascii', FieldValidationFramework::normaliseType('ascii'));
    $harness->assertSame('null', FieldValidationFramework::normaliseType('null'));
    $harness->assertSame(null, FieldValidationFramework::normaliseType('object'));
});

$harness->check(FieldValidationFramework::class, 'validates and canonicalises typed values', function () use ($harness): void {
    $boolean = FieldValidationFramework::validateTypedValue(' TRUE ', 'bool');
    $integer = FieldValidationFramework::validateTypedValue('12345', 'int');
    $float = FieldValidationFramework::validateTypedValue('.5', 'float');
    $ascii = FieldValidationFramework::validateTypedValue('plain ASCII 123', 'string');
    $null = FieldValidationFramework::validateTypedValue('ignored', 'null');

    $harness->assertSame(true, $boolean['success']);
    $harness->assertSame('true', $boolean['value']);
    $harness->assertSame('boolean', $boolean['type']);
    $harness->assertSame(true, $integer['success']);
    $harness->assertSame('12345', $integer['value']);
    $harness->assertSame(true, $float['success']);
    $harness->assertSame('.5', $float['value']);
    $harness->assertSame(true, $ascii['success']);
    $harness->assertSame('plain ASCII 123', $ascii['value']);
    $harness->assertSame(true, $null['success']);
    $harness->assertSame(null, $null['value']);
});

$harness->check(FieldValidationFramework::class, 'rejects invalid typed values with actionable errors', function () use ($harness): void {
    $invalidBoolean = FieldValidationFramework::validateTypedValue('yes', 'bool');
    $invalidInteger = FieldValidationFramework::validateTypedValue('12a', 'int');
    $invalidFloat = FieldValidationFramework::validateTypedValue('1.', 'float');
    $invalidAscii = FieldValidationFramework::validateTypedValue('cafe' . chr(195) . chr(169), 'string');
    $invalidType = FieldValidationFramework::validateTypedValue('anything', 'array');

    $harness->assertSame(false, $invalidBoolean['success']);
    $harness->assertSame('Value must be true or false.', $invalidBoolean['error']);
    $harness->assertSame(false, $invalidInteger['success']);
    $harness->assertSame('Value must contain digits only.', $invalidInteger['error']);
    $harness->assertSame(false, $invalidFloat['success']);
    $harness->assertSame('Value must be a decimal number.', $invalidFloat['error']);
    $harness->assertSame(false, $invalidAscii['success']);
    $harness->assertSame('Value must contain ASCII characters only.', $invalidAscii['error']);
    $harness->assertSame(false, $invalidType['success']);
    $harness->assertSame('Unsupported value type.', $invalidType['error']);
});

$harness->check(FieldValidationFramework::class, 'renders typed controls with canonical validation attributes', function () use ($harness): void {
    $boolean = FieldValidationFramework::renderTypedValueControl('profile_value', 'false', 'bool', [
        'id' => 'profile-value-bool',
        'type_token' => 'row-1',
    ]);
    $integer = FieldValidationFramework::renderTypedValueControl('profile_value', '42', 'int');
    $float = FieldValidationFramework::renderTypedValueControl('profile_value', '12.5', 'float');
    $ascii = FieldValidationFramework::renderTypedValueControl('profile_value', 'abc', 'string');
    $null = FieldValidationFramework::renderTypedValueControl('profile_value', 'ignored', 'null', [
        'id' => 'profile-value-null',
    ]);

    $harness->assertTrue(str_contains($boolean, '<select class="select" name="profile_value" id="profile-value-bool" data-validate-type-target="row-1" data-validate-boolean>'));
    $harness->assertTrue(str_contains($boolean, '<option value="true">true</option>'));
    $harness->assertTrue(str_contains($boolean, '<option value="false" selected>false</option>'));
    $harness->assertTrue(str_contains($integer, '<input class="input" name="profile_value" type="text" data-validate-int inputmode="numeric" value="42">'));
    $harness->assertTrue(str_contains($float, '<input class="input" name="profile_value" type="text" data-validate-float inputmode="decimal" value="12.5">'));
    $harness->assertTrue(str_contains($ascii, '<input class="input" name="profile_value" type="text" data-validate-ascii value="abc">'));
    $harness->assertTrue(str_contains($null, '<input class="input" id="profile-value-null" type="text" disabled value="">'));
    $harness->assertTrue(str_contains($null, '<input type="hidden" name="profile_value" value="">'));
});
