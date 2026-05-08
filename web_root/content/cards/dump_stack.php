<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dump_stackCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dump_stack';
    }

    public function services(): array
    {
        return [];
    }

    public function helper(array $context): string{
        return 'This is a debugging card and shows the call stack to get to a card or action.';        
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '[' . $serviceKey . '] ' . (string)($error['type'] ?? 'error') . ': ' . (string)($error['message'] ?? '');
    }

    public function render(array $context): string
    {
        return '
            <div class="stack">
                <div class="panel-soft">
                    <div class="stack">
                        <div>
                            <p class="helper">Call stack</p>
                        </div>
                        ' . HelperFramework::showCallStack() . '
                    </div>
                </div>
                <div class="panel-soft">
                    <div class="stack">
                        <div>
                            <p class="helper">Action Stack</p>
                        </div>
                        <div>
                            <form method="post" data-ajax="true">
                                <input type="hidden" name="card_action" value="Test">
                                <button class="button primary" type="submit" name="intent" value="check">Test Action</button>
                            </form>
                        </div>
                        <pre class="preformatted-panel">' . HelperFramework::escape($context['actionCallStack'] ?? '') . '</pre>
                    </div>
                </div>
            </div>
        ';
    }
}

