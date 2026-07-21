<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseIxbrlDocumentPolicyService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompaniesHouseIxbrlDocumentPolicyService $service): void {
        $harness->check($service::class, 'canonicalises the generated declaration without changing the document body', static function () use ($harness, $service): void {
            $body = '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml"><head/><body><p>Accounts</p></body></html>';
            $result = $service->canonicaliseGeneratedDocument(
                '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n" . $body
            );

            $harness->assertSame('<?xml version="1.0"?>' . "\n" . $body, $result);
            $harness->assertFalse(str_contains(strtok($result, "\n"), 'encoding='));
        });

        $harness->check($service::class, 'rejects non-literal declarations and leading bytes at the submission boundary', static function () use ($harness, $service): void {
            $body = '<html xmlns="http://www.w3.org/1999/xhtml"><head/><body/></html>';
            $invalid = [
                "\xEF\xBB\xBF" . '<?xml version="1.0"?>' . "\n" . $body,
                ' <?xml version="1.0"?>' . "\n" . $body,
                $body,
                '<?xml version="1.0"?>' . $body,
                '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $body,
                '<?xml version="1.0" standalone="yes"?>' . "\n" . $body,
                '<?xml version="1.0"?>' . "\n<html>",
                '<?xml version="1.0"?>' . "\n<html>\xC3\x28</html>",
            ];
            foreach ($invalid as $xml) {
                try {
                    $service->assertSubmissionCompliant($xml);
                    $harness->assertTrue(false, 'Invalid Companies House declaration should be rejected.');
                } catch (InvalidArgumentException) {
                    $harness->assertTrue(true);
                }
            }
        });

        $harness->check($service::class, 'rejects unsupported declarations during generated-document canonicalisation', static function () use ($harness, $service): void {
            try {
                $service->canonicaliseGeneratedDocument('<?xml version="1.0" standalone="yes"?>' . "\n<html/>");
                $harness->assertTrue(false, 'Unsupported generated declaration should be rejected.');
            } catch (InvalidArgumentException) {
                $harness->assertTrue(true);
            }
        });
    }
);
