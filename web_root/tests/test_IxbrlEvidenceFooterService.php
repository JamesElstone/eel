<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlEvidenceFooterService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlEvidenceFooterService $service
    ): void {
        $harness->check(
            $service::class,
            'replaces an inherited footer with the evidence ID on the final accounts page',
            static function () use ($harness, $service): void {
                $source = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
                    . '<div class="accountspage"><p>First page</p></div>'
                    . '<div class="accountspage"><p>Final page</p>'
                    . '<div class="evidence-footer">Evidence ID: EEL-AR-OLD</div></div>'
                    . '</body></html>';
                $xhtml = $service->withFooter($source, 'EEL-AR-NEW');

                $harness->assertFalse(str_contains($xhtml, 'EEL-AR-OLD'));
                $harness->assertSame(1, substr_count($xhtml, 'class="evidence-footer"'));
                $harness->assertTrue(str_contains(
                    $xhtml,
                    '<div class="evidence-footer">Evidence ID: EEL-AR-NEW</div>'
                ));
                $harness->assertTrue(str_contains(
                    $xhtml,
                    '<div class="accountspage"><p>Final page</p><div class="evidence-footer">'
                ));
            }
        );
    }
);
