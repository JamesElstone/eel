<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

(new GeneratedServiceClassTestHarness())->run(
    CompaniesHouseSchemaArtifactsAction::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $calls = 0;
        $action = new CompaniesHouseSchemaArtifactsAction(
            static function (ActionProgressFramework $progress) use (&$calls): array { $calls++; return ['success'=>true,'changed'=>true]; },
            static fn(RequestFramework $request): ?string => null
        );
        $harness->check($action::class, 'refreshes the profile through the manual pre-warm action', static function () use ($harness, $action, &$calls): void {
            $request = new RequestFramework([], ['intent'=>'refresh_companies_house_accounts_schemas'], ['REQUEST_METHOD'=>'POST'], [], []);
            $result = $action->handle($request, createTestPageServiceFramework());
            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(1, $calls);
            $harness->assertSame(['companies.house.accounts.schemas','page.context'], $result->changedFacts());
        });
    }
);
