<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TestAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {

        return ActionResultFramework::success(
            changedFacts: ['dump.stack', 'dump.classes', 'dump.context'],
            flashMessages: [ 
                'type' => 'success',
                'message' => 'This is a test action flash!'
            ],
            query: [],
            context: [
                'actionCallStack' => $this->formatCallStack(),
            ]
        );
    }

    private function formatCallStack(int $limit = 10): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);

        $lines = [];

        foreach ($trace as $i => $frame) {
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? 'unknown';
            $file = $frame['file'] ?? '[internal]';
            $line = $frame['line'] ?? '?';

            $lines[] = sprintf(
                '#%d %s%s%s() called at [%s:%s]',
                $i,
                $class,
                $type,
                $function,
                $file,
                $line
            );
        }

        return implode("\n", $lines);
    }
}
