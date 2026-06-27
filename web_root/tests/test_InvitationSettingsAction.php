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
$harness->check(InvitationSettingsAction::class, 'explains invited account completion enabled state', function () use ($harness): void {
    $action = new InvitationSettingsAction();
    $method = new ReflectionMethod(InvitationSettingsAction::class, 'successFlashMessage');
    $method->setAccessible(true);

    $harness->assertSame(
        'Invited account completion is now enabled.',
        $method->invoke($action, false, true)
    );
    $harness->assertSame(
        'Invited account completion is now disabled.',
        $method->invoke($action, true, false)
    );
    $harness->assertSame(
        'Invitation settings updated. Invited account completion is enabled.',
        $method->invoke($action, true, true)
    );
    $harness->assertSame(
        'Invitation settings updated. Invited account completion is disabled.',
        $method->invoke($action, false, false)
    );
});

$harness->check(InvitationSettingsAction::class, 'invalidates add user card after settings changes', function () use ($harness): void {
    $source = (string)file_get_contents(APP_ACTIONS . 'InvitationSettingsAction.php');

    $harness->assertTrue(str_contains($source, "ActionResultFramework::success(['invitation.settings', 'add.user']"));
});
