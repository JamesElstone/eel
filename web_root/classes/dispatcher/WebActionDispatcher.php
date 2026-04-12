<?php
declare(strict_types=1);

final class WebActionDispatcher
{
    public function dispatch(WebRequest $request, WebPageService $services, callable $pageActionHandler): WebActionResult
    {
        $action = $request->action();
        if ($action !== '') {
            return $pageActionHandler($request, $services);
        }

        $cardAction = $request->cardAction();
        if ($cardAction === '') {
            return WebActionResult::none();
        }

        $className = $this->resolveCardActionClassName($cardAction);

        if (!class_exists($className)) {
            throw new RuntimeException('Unable to resolve shared action class: ' . $className);
        }

        $actionHandler = new $className();

        if (!$actionHandler instanceof WebActionInterface) {
            throw new RuntimeException('Resolved shared action does not implement WebActionInterface: ' . $className);
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
