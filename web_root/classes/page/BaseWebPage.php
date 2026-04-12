<?php
declare(strict_types=1);

abstract class BaseWebPage implements WebPageInterface
{
    public function handle(WebRequest $request, WebPageService $services): WebResponse
    {
        $actionDispatcher = new WebActionDispatcher();
        $actionResult = $actionDispatcher->dispatch(
            $request,
            $services,
            fn(WebRequest $request, WebPageService $services): WebActionResult
                => $this->handlePageAction($request, $services)
        );

        $context = $this->buildContext($request, $services, $actionResult);
        $renderer = new WebPageRenderer(new WebCardRenderer(new WebCardFactory()));

        if ($request->isAjax()) {
            return $renderer->renderDelta($this, $request, $context, $actionResult);
        }

        return $renderer->renderFull($this, $request, $context, $actionResult);
    }

    protected function handlePageAction(WebRequest $request, WebPageService $services): WebActionResult
    {
        return WebActionResult::none();
    }

    abstract protected function buildContext(
        WebRequest $request,
        WebPageService $services,
        WebActionResult $actionResult
    ): array;
}
