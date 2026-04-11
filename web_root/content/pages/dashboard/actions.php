<?php
declare(strict_types=1);

function dashboard_handle_action(WebRequest $request, WebPageServices $services): WebActionResult
{
    if (!$request->isPost()) {
        return WebActionResult::none();
    }

    if ($request->action() !== 'set-focus') {
        return WebActionResult::none();
    }

    $focus = dashboard_normalise_focus((string)$request->post('focus', 'architecture'));

    return WebActionResult::success(
        ['dashboard.selection', 'dashboard.metrics', 'dashboard.feed'],
        [
            [
                'type' => 'success',
                'message' => 'Focus switched to ' . dashboard_focus_label($focus) . '.',
            ],
        ],
        ['focus' => $focus]
    );
}
