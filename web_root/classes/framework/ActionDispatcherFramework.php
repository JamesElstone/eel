<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ActionDispatcherFramework
{
    public function dispatch(
        RequestFramework $request,
        PageServiceFramework $services,
        callable $pageActionHandler
    ): ActionResultFramework
    {
        
        // Run page action if set
        $action = $request->action();
        if ($action !== '') {
            return $pageActionHandler($request, $services);
        }

        $cardAction = $request->cardAction();
        if ($cardAction === '') {
            if ((string)$request->input('_pagination', '') === '1') {
                return ActionResultFramework::success(['page.reload']);
            }

            return ActionResultFramework::none();
        }

        $className = $this->resolveCardActionClassName($cardAction);
        if (!class_exists($className)) {
            throw new RuntimeException('ActionInterfaceFramework: Unable to resolve the requested card action class: ' . $className);
        }

        $actionHandler = new $className();

        if (!$actionHandler instanceof ActionInterfaceFramework) {
            throw new RuntimeException('ActionInterfaceFramework: ' . $className . ' does not resolve to an action interface class.');
        }

        return $actionHandler->handle($request, $services);
    }

    private function resolveCardActionClassName(string $cardAction): string
    {
        $cardAction = trim($cardAction);

        if ($cardAction === '' || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $cardAction) !== 1) {
            throw new InvalidArgumentException('Invalid shared card action: ' . $cardAction);
        }

        return $cardAction . 'Action';
    }
}
