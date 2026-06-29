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
$harness->run(\eel_accounts\Service\CompaniesHouseSICService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompaniesHouseSICService $service): void {
    $harness->check(\eel_accounts\Service\CompaniesHouseSICService::class, 'extracts SIC codes from stored Companies House profile json', function () use ($harness, $service): void {
        $codes = $service->extractSicCodesFromProfileJson(json_encode([
            'sic_codes' => ['33130', '43210', '43210', 'invalid'],
        ], JSON_UNESCAPED_SLASHES));

        $harness->assertSame(['33130', '43210'], $codes);
    });

    $harness->check(\eel_accounts\Service\CompaniesHouseSICService::class, 'parses sections and SIC codes from Companies House HTML', function () use ($harness, $service): void {
        $parsed = $service->parseLookupHtml(<<<'HTML'
<!DOCTYPE html>
<html>
<body>
  <table id="sic-codes">
    <tbody>
      <tr>
        <td><strong>Section C</strong></td>
        <td><strong>Manufacturing</strong></td>
      </tr>
      <tr>
        <td>33130</td>
        <td>Repair of electronic and optical equipment</td>
      </tr>
      <tr>
        <td>33140</td>
        <td>Repair of electrical equipment</td>
      </tr>
      <tr>
        <td><strong>Section F</strong></td>
        <td><strong>Construction</strong></td>
      </tr>
      <tr>
        <td>43210</td>
        <td>Electrical installation</td>
      </tr>
    </tbody>
  </table>
</body>
</html>
HTML);

        $harness->assertCount(2, $parsed['sections']);
        $harness->assertCount(3, $parsed['codes']);
        $harness->assertSame('C', $parsed['sections'][0]['section_letter'] ?? '');
        $harness->assertSame('Manufacturing', $parsed['sections'][0]['description'] ?? '');
        $harness->assertSame('43210', $parsed['codes'][2]['sic_code'] ?? '');
        $harness->assertSame('F', $parsed['codes'][2]['section_letter'] ?? '');
    });

    $harness->check(\eel_accounts\Service\CompaniesHouseSICService::class, 'formats resolved SIC codes for display', function () use ($harness, $service): void {
        $lines = $service->formatResolvedCodesForDisplay([
            [
                'sic_code' => '43210',
                'description' => 'Electrical installation',
                'section_letter' => 'F',
                'section_description' => 'Construction',
            ],
            [
                'sic_code' => '99999',
                'description' => '',
                'section_letter' => '',
                'section_description' => '',
            ],
        ]);

        $harness->assertSame(
            [
                '43210 - Electrical installation (Section F: Construction)',
                '99999',
            ],
            $lines
        );
    });
});
