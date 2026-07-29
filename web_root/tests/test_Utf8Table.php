<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Support\Utf8Table::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Support\Utf8Table::class, 'normalises legacy text before constructing a table', static function () use ($harness): void {
        $table = \eel_accounts\Support\Utf8Table::make('utf8-dedicated', [[
            'description' => "Director\x92s equipment",
        ]])->textColumn('description', 'Description');

        $html = $table->renderTable();
        $harness->assertTrue(str_contains($html, 'Director’s equipment'));
        $harness->assertFalse(str_contains($html, "\x92"));
    });
});
