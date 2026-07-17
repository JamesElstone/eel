<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlParserService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlParserService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlParserService::class, 'applies Inline XBRL sign and scale attributes', static function () use ($harness, $service): void {
            $parsed = $service->parse(ixbrlParserNumericFixture());
            $facts = [];
            foreach ((array)($parsed['facts'] ?? []) as $fact) {
                $facts[(string)($fact['short_name'] ?? '')] = $fact;
            }

            $harness->assertSame('-5854', (string)($facts['ScaledNegative']['normalised_numeric'] ?? ''));
            $harness->assertSame('ix_sign', (string)($facts['ScaledNegative']['sign_hint'] ?? ''));
            $harness->assertSame('-', (string)($facts['ScaledNegative']['sign_value'] ?? ''));
            $harness->assertSame('2', (string)($facts['ScaledNegative']['scale_value'] ?? ''));
            $harness->assertSame('12.345', (string)($facts['NegativeScale']['normalised_numeric'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\IxbrlParserService::class, 'keeps presentation parentheses compatible with Inline XBRL sign', static function () use ($harness, $service): void {
            $parsed = $service->parse(ixbrlParserNumericFixture());
            $facts = [];
            foreach ((array)($parsed['facts'] ?? []) as $fact) {
                $facts[(string)($fact['short_name'] ?? '')] = $fact;
            }

            $harness->assertSame('-1234', (string)($facts['Parentheses']['normalised_numeric'] ?? ''));
            $harness->assertSame('1234', (string)($facts['DoubleNegative']['normalised_numeric'] ?? ''));
        });
    }
);

function ixbrlParserNumericFixture(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml"
      xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"
      xmlns:xbrli="http://www.xbrl.org/2003/instance"
      xmlns:test="https://example.test/taxonomy">
  <body>
    <div style="display:none">
      <ix:header>
        <ix:resources>
          <xbrli:context id="current">
            <xbrli:entity><xbrli:identifier scheme="https://example.test">123</xbrli:identifier></xbrli:entity>
            <xbrli:period><xbrli:instant>2026-09-30</xbrli:instant></xbrli:period>
          </xbrli:context>
          <xbrli:unit id="GBP"><xbrli:measure>iso4217:GBP</xbrli:measure></xbrli:unit>
        </ix:resources>
      </ix:header>
    </div>
    <ix:nonFraction name="test:ScaledNegative" contextRef="current" unitRef="GBP" sign="-" scale="2">58.54</ix:nonFraction>
    <ix:nonFraction name="test:NegativeScale" contextRef="current" unitRef="GBP" scale="-3">12345</ix:nonFraction>
    <ix:nonFraction name="test:Parentheses" contextRef="current" unitRef="GBP">(1,234)</ix:nonFraction>
    <ix:nonFraction name="test:DoubleNegative" contextRef="current" unitRef="GBP" sign="-">(1,234)</ix:nonFraction>
  </body>
</html>
XML;
}
