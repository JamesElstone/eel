<?php
declare(strict_types=1);

abstract class BasePageFramework implements PageInterfaceFramework
{
    public function showsTaxYearSelector(): bool
    {
        return true;
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ResponseFramework
    {
        $actionDispatcher = new ActionDispatcherFramework();
        $actionResult = $actionDispatcher->dispatch(
            $request,
            $services,
            fn(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
                => $this->dispatchPageAction($request, $services)
        );

        $pageContext = $this->buildContext($request, $services, $actionResult);
        $selectorContext = $services->get(CompanyStore::class)->buildSelectorContext($request, $this);
        $context = array_merge($pageContext, [
            'selected_company_id' => $selectorContext['selected_company_id'] ?? 0,
            'selected_tax_year_id' => $selectorContext['selected_tax_year_id'] ?? 0,
            'show_tax_year_selector' => $selectorContext['show_tax_year_selector'] ?? true,
        ]);
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

    private function dispatchPageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework
    {
        if ($request->action() === 'set-page-context') {
            return ActionResultFramework::success(
                ['page.context', 'page.selector_ui'],
                [],
                [
                    'company_id' => $request->companyId() > 0 ? $request->companyId() : null,
                    'tax_year_id' => $request->taxYearId() > 0 ? $request->taxYearId() : null,
                ]
            );
        }

        return $this->handlePageAction($request, $services);
    }

    abstract protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array;
}
