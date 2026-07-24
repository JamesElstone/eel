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
$harness->check(PdoDB::class, 'loads static PDO helper', function () use ($harness): void {
    $harness->assertTrue(class_exists(PdoDB::class));
    $harness->assertTrue(method_exists(PdoDB::class, 'prepareExecuteOn'));
});

$harness->check(PdoDB::class, 'explains missing ODBC PDO driver failures', function (): void {
    $reflection = new ReflectionClass(PdoDB::class);
    $method = $reflection->getMethod('connectionExceptionMessage');
    $method->setAccessible(true);

    $message = $method->invoke(null, 'odbc:eelkit', new PDOException('could not find driver'));

    if (!is_string($message) || !str_contains($message, 'pdo_odbc')) {
        throw new RuntimeException('Missing ODBC driver failure did not include the pdo_odbc setup hint.');
    }
});

$harness->check(PdoDB::class, 'adds the Windows ODBC UTF-8 option only when PHP provides it', function () use ($harness): void {
    $options = PdoDB::connectionOptions('odbc:eelkit', [], 'Windows');
    $attribute = null;
    foreach (['PDO\\ODBC::ATTR_ASSUME_UTF8', 'Pdo\\Odbc::ATTR_ASSUME_UTF8', 'PDO::ODBC_ATTR_ASSUME_UTF8'] as $constantName) {
        if (defined($constantName)) {
            $attribute = constant($constantName);
            break;
        }
    }

    if (is_int($attribute)) {
        $harness->assertSame(true, $options[$attribute] ?? null);
    } else {
        $harness->assertSame(null, $options[999999] ?? null);
    }
    $harness->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
    $harness->assertSame(PDO::FETCH_ASSOC, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
});

$harness->check(PdoDB::class, 'does not apply the Windows-only ODBC option elsewhere or override caller options', function () use ($harness): void {
    $callerOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, 999999 => 'caller value'];
    $freeBsdOptions = PdoDB::connectionOptions('odbc:eelkit', $callerOptions, 'FreeBSD');
    $mysqlOptions = PdoDB::connectionOptions('mysql:host=localhost', $callerOptions, 'Windows');

    $harness->assertSame(PDO::ERRMODE_SILENT, $freeBsdOptions[PDO::ATTR_ERRMODE]);
    $harness->assertSame('caller value', $freeBsdOptions[999999]);
    $harness->assertSame($freeBsdOptions, $mysqlOptions);
});
