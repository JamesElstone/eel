<?php
declare(strict_types=1);

abstract class BasePageFramework implements PageInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ResponseFramework
    {
        $actionDispatcher = new ActionDispatcherFramework();
        $actionResult = $actionDispatcher->dispatch(
            $request,
            $services,
            fn(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
                => $this->handlePageAction($request, $services)
        );

        $context = $this->buildContext($request, $services, $actionResult);
        $renderer = new PageRendererFramework(
            new CardRendererFramework(new CardFactoryFramework())
        );

        if ($request->isAjax()) {
            return $renderer->renderDelta($this, $request, $context, $actionResult, $services);
        }

        return $renderer->renderFull($this, $request, $context, $actionResult, $services);
    }

    protected function handlePageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework
    {
        return ActionResultFramework::none();
    }

    abstract protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array;
}
