<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlRevisedAccountsArtifactService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlRevisedAccountsArtifactService $service): void {
        $harness->check($service::class, 'validates revision declarations before locating an artifact', static function () use ($harness, $service): void {
            $result = $service->prepare(0, 0, []);
            $harness->assertFalse((bool)($result['success'] ?? true));
            $harness->assertTrue((array)($result['errors'] ?? []) !== []);
        });

        $harness->check($service::class, 'retains the literal Companies House declaration after revised-account DOM serialisation', static function () use ($harness, $service): void {
            $source = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml"'
                . ' xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"'
                . ' xmlns:xbrli="http://www.xbrl.org/2003/instance"'
                . ' xmlns:bus="http://xbrl.frc.org.uk/cd/2026-01-01/business"'
                . ' xmlns:ixt="http://www.xbrl.org/inlineXBRL/transformation/2015-02-26">'
                . '<head/><body><h1>Original accounts</h1><div style="display:none"><ix:header><ix:resources>'
                . '<xbrli:context id="current_period_duration"/>'
                . '</ix:resources></ix:header></div><p id="preserved">Preserved content</p></body></html>';
            $result = $service->transform($source, [
                'replaces_statement' => 'These revised accounts replace the previously filed report.',
                'statutory_accounts_statement' => 'These are now the statutory accounts.',
                'prepared_as_statement' => 'Prepared as at the date of the previous report.',
                'non_compliance_explanation' => 'The original report contained an error.',
                'significant_amendments' => 'The comparative figures were corrected.',
                'revision_approval_date' => '2026-07-21',
            ]);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $xhtml = (string)($result['xhtml'] ?? '');
            $harness->assertTrue(str_starts_with($xhtml, '<?xml version="1.0"?>' . "\n"));
            $harness->assertFalse(str_contains(strtok($xhtml, "\n"), 'encoding='));
            $harness->assertTrue(str_contains($xhtml, 'id="preserved"'));
            $harness->assertTrue(str_contains($xhtml, 'ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse'));
        });
    }
);
