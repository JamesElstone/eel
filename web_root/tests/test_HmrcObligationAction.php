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

$harness->run(HmrcObligationAction::class, static function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof HmrcObligationAction) {
        $harness->skip('HMRC obligation action did not instantiate.');
    }

    $harness->check(HmrcObligationAction::class, 'filter_obligations refreshes context without mutation flash', static function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'HmrcObligation',
                'intent' => 'filter_obligations',
                'company_id' => '49',
                'hmrc_filter' => 'open',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(true, in_array('hmrc.obligations.timeline', $result->changedFacts(), true));
        $harness->assertSame(true, in_array('page.context', $result->changedFacts(), true));
        $harness->assertSame([], $result->flashMessages());
    });
});
