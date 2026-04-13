<?php
declare(strict_types=1);

final class ActionDispatcherFramework
{
    public function dispatch(
        RequestFramework $request,
        PageServiceFramework $services,
        callable $pageActionHandler
    ): ActionResultFramework
    {
        $action = $request->action();
        if ($action !== '') {
            return $pageActionHandler($request, $services);
        }

        $cardAction = $request->cardAction();
        if ($cardAction === '') {
            return ActionResultFramework::none();
        }

        $className = $this->resolveCardActionClassName($cardAction);

        if (!class_exists($className)) {
            throw new RuntimeException('Unable to resolve shared action class: ' . $className);
        }

        $actionHandler = new $className();

        if (!$actionHandler instanceof ActionInterfaceFramework) {
            throw new RuntimeException('Resolved shared action does not implement ActionInterfaceFramework: ' . $className);
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
