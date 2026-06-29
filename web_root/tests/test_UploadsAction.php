<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(UploadsAction::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(UploadsAction::class, 'combines batch upload success and warning into one file flash', static function () use ($harness): void {
        $action = new UploadsAction();
        $method = new ReflectionMethod(UploadsAction::class, 'batchUploadItemFlash');
        $method->setAccessible(true);

        [$message, $error] = $method->invoke($action, [
            'filename' => '2025-02-BANK_010225_280225.csv',
            'success' => true,
            'already_uploaded' => false,
        ], [
            'This CSV contains headers but no transaction rows, so there is nothing to preview or import.',
        ], []);

        $harness->assertSame('', $error);
        $harness->assertSame(
            '2025-02-BANK_010225_280225.csv: uploaded successfully. This CSV contains headers but no transaction rows, so there is nothing to preview or import.',
            $message
        );
    });

    $harness->check(UploadsAction::class, 'combines batch upload failure errors into one file flash error', static function () use ($harness): void {
        $action = new UploadsAction();
        $method = new ReflectionMethod(UploadsAction::class, 'batchUploadItemFlash');
        $method->setAccessible(true);

        [$message, $error] = $method->invoke($action, [
            'filename' => 'statement.csv',
            'success' => false,
        ], [], [
            'The uploaded file could not be read.',
        ]);

        $harness->assertSame('', $message);
        $harness->assertSame('statement.csv: upload failed. The uploaded file could not be read.', $error);
    });

    $harness->check(UploadsAction::class, 'can target upload details after committing transactions', static function () use ($harness): void {
        $action = new UploadsAction();
        $method = new ReflectionMethod(UploadsAction::class, 'actionResult');
        $method->setAccessible(true);
        $request = new RequestFramework([], [
            'upload_id' => '7',
            'filter' => 'ready',
            'page' => '2',
        ], [
            'REQUEST_METHOD' => 'POST',
        ], [], []);

        $result = $method->invoke(
            $action,
            $request,
            true,
            [],
            [],
            ['upload_id' => 7],
            ['page.context', 'uploads.details'],
            ['show_card' => 'uploads_details']
        );

        $harness->assertSame(['page.context', 'uploads.details'], $result->changedFacts());
        $harness->assertSame(['show_card' => 'uploads_details'], $result->query());
        $harness->assertSame([
            'uploads' => [
                'id' => 7,
                'filter' => 'ready',
                'page' => 2,
            ],
        ], $result->context());
    });

    $harness->check(UploadsAction::class, 'commit upload result returns to upload details card', static function () use ($harness): void {
        $method = new ReflectionMethod(UploadsAction::class, 'commitAccountUpload');
        $lines = file((string)$method->getFileName());
        $source = implode('', array_slice(
            $lines === false ? [] : $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $harness->assertTrue(str_contains($source, "['page.context', 'uploads.details']"));
        $harness->assertTrue(str_contains($source, "['show_card' => 'uploads_details']"));
    });
});
