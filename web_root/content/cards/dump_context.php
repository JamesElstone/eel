<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dump_contextCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dump_context';
    }

    public function services(): array
    {
        return [
            
            // [
            //     'key' => 'nameOfService',
            //     'service' => className::class,
            //     'method' => 'classFunction',
            //     'params' => [
            //         'input1' => ':context.value',
            //         'flag' => true,
            //     ],
            // ],
        ]
        ;
    }

    public function helper(array $context): string{
        return 'This is a debugging card and displays the global context used by cards...';        
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
                            <p class="helper">Context dump (Warning: Only shows services for this card not others)</p>
                        </div>
                        <pre class="preformatted-panel">' . HelperFramework::escape($this->dumpJson($context)) . '</pre>
                    </div>
                </div>
            </div>
        ';
    }

    private function dumpContext(array $context): string
    {
        ob_start();
        var_dump($context);

        return trim((string)ob_get_clean());
    }

    private function dumpJson(array $context) : string
    {
        return json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    
}

