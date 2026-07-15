<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Renderer\WorkflowHandoffRenderer::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Renderer\WorkflowHandoffRenderer::class, 'renders escaped POST handoffs with nested hidden fields', static function () use ($harness): void {
        $html = \eel_accounts\Renderer\WorkflowHandoffRenderer::button(
            '?page=transactions',
            '<Open transactions>',
            [
                'page' => 'ignored',
                'company_id' => 7,
                'filters' => ['status' => 'ready & waiting'],
            ],
            'button "primary"',
            false
        );

        $harness->assertTrue(str_contains($html, '<form method="post" action="?page=transactions"'));
        $harness->assertFalse(str_contains($html, 'data-ajax="true"'));
        $harness->assertTrue(str_contains($html, 'class="button &quot;primary&quot;"'));
        $harness->assertTrue(str_contains($html, 'name="company_id" value="7"'));
        $harness->assertTrue(str_contains($html, 'name="filters[status]" value="ready &amp; waiting"'));
        $harness->assertFalse(str_contains($html, 'name="page"'));
        $harness->assertTrue(str_contains($html, '&lt;Open transactions&gt;'));
    });

    $harness->check(\eel_accounts\Renderer\WorkflowHandoffRenderer::class, 'normalises URL and workflow handoffs with explicit-field precedence', static function () use ($harness): void {
        $fromUrl = \eel_accounts\Renderer\WorkflowHandoffRenderer::fromUrl(
            '?page=tax&show_card=tax_workings&company_id=4',
            'Open tax',
            ['company_id' => 9]
        );
        $harness->assertTrue(str_contains($fromUrl, 'action="?page=tax"'));
        $harness->assertTrue(str_contains($fromUrl, 'name="show_card" value="tax_workings"'));
        $harness->assertTrue(str_contains($fromUrl, 'name="company_id" value="9"'));
        $harness->assertFalse(str_contains($fromUrl, 'name="company_id" value="4"'));

        $fromWorkflow = \eel_accounts\Renderer\WorkflowHandoffRenderer::fromWorkflow(
            [
                'workflow_page' => 'year_end',
                'workflow_label' => 'Review year end',
                'workflow_fields' => ['accounting_period_id' => 12],
            ],
            'Fallback'
        );
        $harness->assertTrue(str_contains($fromWorkflow, 'action="?page=year_end"'));
        $harness->assertTrue(str_contains($fromWorkflow, 'name="accounting_period_id" value="12"'));
        $harness->assertTrue(str_contains($fromWorkflow, '>Review year end</button>'));
        $harness->assertSame('', \eel_accounts\Renderer\WorkflowHandoffRenderer::fromWorkflow([], 'Fallback'));
    });
});
