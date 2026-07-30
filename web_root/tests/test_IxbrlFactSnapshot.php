<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR
    . 'ixbrl' . DIRECTORY_SEPARATOR . 'IxbrlFactSnapshot.php';

use eel_accounts\Tests\Support\Ixbrl\IxbrlFactSnapshot;

$fixture = static function (): string {
    return <<<'XHTML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml"
      xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"
      xmlns:xbrli="http://www.xbrl.org/2003/instance"
      xmlns:ex="https://example.test/taxonomy"
      xmlns:ixt="http://www.xbrl.org/inlineXBRL/transformation/2020-02-12"
      xmlns:iso4217="http://www.xbrl.org/2003/iso4217"
      xml:lang="en">
  <head>
    <title>Invariance fixture</title>
    <style>.accounting { font-variant-numeric: tabular-nums; }</style>
  </head>
  <body>
    <div class="ixbrl-header">
      <ix:header>
        <ix:resources>
          <xbrli:context id="period">
            <xbrli:entity>
              <xbrli:identifier scheme="https://example.test/entity">12345678</xbrli:identifier>
            </xbrli:entity>
            <xbrli:period>
              <xbrli:startDate>2025-01-01</xbrli:startDate>
              <xbrli:endDate>2025-12-31</xbrli:endDate>
            </xbrli:period>
          </xbrli:context>
          <xbrli:context id="period-equivalent">
            <xbrli:entity>
              <xbrli:identifier scheme="https://example.test/entity">12345678</xbrli:identifier>
            </xbrli:entity>
            <xbrli:period>
              <xbrli:startDate>2025-01-01</xbrli:startDate>
              <xbrli:endDate>2025-12-31</xbrli:endDate>
            </xbrli:period>
          </xbrli:context>
          <xbrli:unit id="GBP"><xbrli:measure>iso4217:GBP</xbrli:measure></xbrli:unit>
          <xbrli:unit id="GBP-equivalent"><xbrli:measure>iso4217:GBP</xbrli:measure></xbrli:unit>
        </ix:resources>
      </ix:header>
    </div>
    <div id="loss-row-a">Adjusted trading loss for the period
      <span class="accounting">(<ix:nonFraction id="loss-fact-a"
        name="ex:AdjustedLossOfPeriod" contextRef="period" unitRef="GBP"
        decimals="2" format="ixt:numdotdecimal">7,022.81</ix:nonFraction>)</span>
    </div>
    <div id="loss-row-b">Repeated header loss
      <span class="accounting">(<ix:nonFraction id="loss-fact-b"
        name="ex:AdjustedLossOfPeriod" contextRef="period-equivalent"
        unitRef="GBP-equivalent" decimals="2"
        format="ixt:numdotdecimal">7,022.81</ix:nonFraction>)</span>
    </div>
    <p id="company-a"><ix:nonNumeric id="company-fact-a"
      name="ex:CompanyName" contextRef="period">Example Limited</ix:nonNumeric></p>
    <p id="company-b"><ix:nonNumeric id="company-fact-b"
      name="ex:CompanyName" contextRef="period-equivalent">Example Limited</ix:nonNumeric></p>
    <p id="status-a"><ix:nonNumeric id="status-fact-a"
      name="ex:FilingStatus" contextRef="period">Original</ix:nonNumeric></p>
    <p id="status-b"><ix:nonNumeric id="status-fact-b"
      name="ex:FilingStatus" contextRef="period-equivalent">Revised</ix:nonNumeric></p>
  </body>
</html>
XHTML;
};

(new GeneratedServiceClassTestHarness())->run(
    IxbrlFactSnapshot::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        IxbrlFactSnapshot $snapshot
    ) use ($fixture): void {
        $harness->check(
            IxbrlFactSnapshot::class,
            'captures numeric invariants and literal normalised accounting text',
            static function () use ($harness, $snapshot, $fixture): void {
                $inspection = $snapshot->inspect($fixture());

                $harness->assertSame(2, (int)$inspection['counts']['numeric_facts']);
                $harness->assertSame(2, count((array)$inspection['numeric_facts']));
                $harness->assertSame(
                    $inspection['numeric_facts'],
                    $snapshot->numericFactInventory($fixture())
                );
                foreach ((array)$inspection['numeric_facts'] as $fact) {
                    $harness->assertSame(
                        '{https://example.test/taxonomy}AdjustedLossOfPeriod',
                        (string)$fact['qname']
                    );
                    $harness->assertSame('7,022.81', (string)$fact['value']);
                    $harness->assertSame('', (string)$fact['sign']);
                    $harness->assertSame('2', (string)$fact['decimals']);
                    $harness->assertSame('', (string)$fact['scale']);
                    $harness->assertSame('en', (string)$fact['xml_lang']);
                }
                $visible = (string)$inspection['normalised_visible_text'];
                $harness->assertTrue(str_contains($visible, '(7,022.81)'));
                $harness->assertFalse(str_contains($visible, 'font-variant-numeric'));
                $harness->assertFalse(str_contains($visible, '12345678'));
                $harness->assertSame($visible, $snapshot->normalisedVisibleText($fixture()));
            }
        );

        $harness->check(
            IxbrlFactSnapshot::class,
            'distinguishes equivalent repeated facts from conflicting duplicates',
            static function () use ($harness, $snapshot, $fixture): void {
                $inspection = $snapshot->inspect($fixture());
                $duplicates = (array)$inspection['duplicate_facts'];
                $equivalent = (array)$duplicates['equivalent'];
                $conflicting = (array)$duplicates['conflicting'];

                $harness->assertSame(2, count($equivalent));
                $harness->assertSame(1, count($conflicting));
                $equivalentQNames = array_column($equivalent, 'qname');
                sort($equivalentQNames, SORT_STRING);
                $harness->assertSame([
                    '{https://example.test/taxonomy}AdjustedLossOfPeriod',
                    '{https://example.test/taxonomy}CompanyName',
                ], $equivalentQNames);
                $harness->assertSame(
                    '{https://example.test/taxonomy}FilingStatus',
                    (string)$conflicting[0]['qname']
                );
                $harness->assertSame(2, count((array)$conflicting[0]['payloads']));
                $harness->assertSame(
                    ['period', 'period-equivalent'],
                    (array)$conflicting[0]['context_refs']
                );
            }
        );

        $harness->check(
            IxbrlFactSnapshot::class,
            'reports duplicate and invalid XML identifiers',
            static function () use ($harness, $snapshot, $fixture): void {
                $valid = $snapshot->inspect($fixture());
                $harness->assertTrue((bool)$valid['ids_unique']);
                $harness->assertSame([], (array)$valid['duplicate_xml_ids']);
                $harness->assertSame([], (array)$valid['invalid_xml_ids']);

                $duplicateXhtml = str_replace(
                    'id="company-b"',
                    'id="company-a"',
                    $fixture()
                );
                $duplicate = $snapshot->inspect($duplicateXhtml);
                $harness->assertFalse((bool)$duplicate['ids_unique']);
                $harness->assertSame(
                    2,
                    count((array)$duplicate['duplicate_xml_ids']['company-a'])
                );

                $invalidXhtml = str_replace(
                    'id="company-b"',
                    'id=""',
                    $fixture()
                );
                $invalid = $snapshot->inspect($invalidXhtml);
                $harness->assertFalse((bool)$invalid['ids_unique']);
                $harness->assertSame(1, count((array)$invalid['invalid_xml_ids']));
            }
        );

        $harness->check(
            IxbrlFactSnapshot::class,
            'separates numeric invariance from intended presentation changes',
            static function () use ($harness, $snapshot, $fixture): void {
                $before = $fixture();
                $after = str_replace(
                    'Adjusted trading loss for the period',
                    'Legacy loss wording',
                    $before
                );
                $comparison = $snapshot->compare($before, $after);

                $harness->assertTrue((bool)$comparison['facts_unchanged_except_allowlist']);
                $harness->assertTrue((bool)$comparison['numeric_facts_unchanged']);
                $harness->assertTrue((bool)$comparison['contexts_unchanged']);
                $harness->assertTrue((bool)$comparison['units_unchanged']);
                $harness->assertFalse((bool)$comparison['other_visible_text_unchanged']);
                $harness->assertFalse((bool)$comparison['passed']);
            }
        );
    }
);
