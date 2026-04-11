<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'dashboard' . DIRECTORY_SEPARATOR . 'actions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'dashboard' . DIRECTORY_SEPARATOR . 'context.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'dashboard' . DIRECTORY_SEPARATOR . 'helpers.php';

final class _dashboard implements WebPageInterface
{
    public function id(): string
    {
        return 'dashboard';
    }

    public function title(): string
    {
        return 'Dashboard';
    }

    public function subtitle(): string
    {
        return 'Track the new page architecture with convention-led cards, shared rendering, and AJAX-only card updates.';
    }

    public function services(): array
    {
        return ['company_account'];
    }

    public function cards(): array
    {
        return [
            'hero',
            'overview',
            'activity',
        ];
    }

    public function handle(WebRequest $request, WebPageServices $services): WebResponse
    {
        $actionResult = dashboard_handle_action($request, $services);
        $context = dashboard_build_context($request, $services, $actionResult);
        $renderer = new WebPageRenderer(new WebCardRenderer(new WebCardFactory()));

        if ($request->isAjax()) {
            return $renderer->renderDelta($this, $request, $context, $actionResult);
        }

        return $renderer->renderFull($this, $request, $context, $actionResult);
    }
}
