<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TableExportFramework
{
    public function handle(
        PageBaseFramework $page,
        RequestFramework $request,
        array $context,
        PageServiceFramework $services,
        CardRendererFramework $renderer
    ): ?ResponseFramework {
        $downloadResponse = $this->downloadPreparedExport($page, $request, $services, $renderer);
        if ($downloadResponse instanceof ResponseFramework) {
            return $downloadResponse;
        }

        $format = strtolower(trim((string)$request->input('_table_export_prepare', '')));
        if (!in_array($format, ['csv', 'xlsx', 'tsv', 'ascii'], true)) {
            return null;
        }

        $rawTableKey = trim((string)$request->input('table_key', ''));
        if ($rawTableKey === '') {
            return null;
        }

        try {
            $tableKey = HelperFramework::normaliseCardKey($rawTableKey);
        } catch (InvalidArgumentException) {
            return ResponseFramework::html('Invalid table export request.', 400);
        }

        $cardFactory = new CardFactoryFramework();
        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $card = $cardFactory->create((string)$cardKey);
            $cardContext = $renderer->buildContextForCard($card, $context, $services);

            foreach ($card->tables($cardContext) as $table) {
                if ($table instanceof TableFramework && $table->key() === $tableKey) {
                    if ($this->clipboardRequested($request, $format)) {
                        return $this->clipboardExportResponse($request, $table, $format);
                    }

                    return $this->prepareExportResponse($request, $table, $format);
                }
            }
        }

        return ResponseFramework::json([
            'success' => false,
            'errors' => ['Table export was not found.'],
            'ajax_nonce' => (new SessionAuthenticationService())->consumeAjaxNonceRefresh(),
        ], 404);
    }

    public function downloadPreparedExport(
        PageBaseFramework $page,
        RequestFramework $request,
        PageServiceFramework $services,
        CardRendererFramework $renderer
    ): ?ResponseFramework {
        $token = trim((string)$request->input('_table_export_download', ''));
        if ($token === '') {
            return null;
        }

        $sessionAuthenticationService = new SessionAuthenticationService(request: $request);
        $sessionAuthenticationService->startSession();
        $deviceId = $this->currentDeviceId();
        $export = (new TableExportTokenStoreFramework())->consume(
            $token,
            $sessionAuthenticationService->authenticatedUserId($deviceId),
            $deviceId
        );

        if (!is_array($export)) {
            return ResponseFramework::html('The requested export was not found.', 404);
        }

        $format = strtolower(trim((string)($export['format'] ?? '')));
        $tableKey = trim((string)($export['table_key'] ?? ''));
        if (!in_array($format, ['csv', 'xlsx', 'tsv', 'ascii'], true) || $tableKey === '') {
            return ResponseFramework::html('The requested export was invalid.', 400);
        }

        $replayRequest = $request->replayWith(
            $this->replayValues((array)($export['query'] ?? []), $page->id()),
            $this->replayValues((array)($export['post'] ?? []), $page->id())
        );
        $replayContext = $page->buildContextForRequest($replayRequest, $services, ActionResultFramework::none());
        $table = $this->findTable($tableKey, $replayContext, $services, $renderer);

        if (!$table instanceof TableFramework) {
            return ResponseFramework::html('Table export was not found.', 404);
        }

        return $table->downloadResponse($format);
    }

    private function clipboardRequested(RequestFramework $request, string $format): bool
    {
        return trim((string)$request->input('_table_export_clipboard', '')) === '1'
            && in_array($format, ['csv', 'tsv', 'ascii'], true);
    }

    private function clipboardExportResponse(RequestFramework $request, TableFramework $table, string $format): ResponseFramework
    {
        $sessionAuthenticationService = new SessionAuthenticationService(request: $request);
        $sessionAuthenticationService->startSession();

        return ResponseFramework::json([
            'success' => true,
            'clipboard_text' => $this->clipboardExportText($table, $format),
            'clipboard_format' => $format,
            'ajax_nonce' => $sessionAuthenticationService->consumeAjaxNonceRefresh(),
        ]);
    }

    private function clipboardExportText(TableFramework $table, string $format): string
    {
        if ($format === 'tsv') {
            return $table->exportTsv();
        }

        if ($format === 'ascii') {
            return $table->exportAscii();
        }

        return $table->exportCsv();
    }

    private function prepareExportResponse(RequestFramework $request, TableFramework $table, string $format): ResponseFramework
    {
        $sessionAuthenticationService = new SessionAuthenticationService(request: $request);
        $sessionAuthenticationService->startSession();
        $deviceId = $this->currentDeviceId();
        $token = (new TableExportTokenStoreFramework())->create(
            $sessionAuthenticationService->authenticatedUserId($deviceId),
            $deviceId,
            [
                'page' => $request->getPage(),
                'table_key' => $table->key(),
                'format' => $format,
                'query' => $this->exportReplayFacts($request->queryValues()),
                'post' => $this->exportReplayFacts($request->postValues()),
            ]
        );

        return ResponseFramework::json([
            'success' => true,
            'download_url' => $request->pageUrl([
                '_table_export_download' => $token,
            ]),
            'ajax_nonce' => $sessionAuthenticationService->consumeAjaxNonceRefresh(),
        ]);
    }

    private function findTable(
        string $tableKey,
        array $context,
        PageServiceFramework $services,
        CardRendererFramework $renderer
    ): ?TableFramework {
        $cardFactory = new CardFactoryFramework();
        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $card = $cardFactory->create((string)$cardKey);
            $cardContext = $renderer->buildContextForCard($card, $context, $services);

            foreach ($card->tables($cardContext) as $table) {
                if ($table instanceof TableFramework && $table->key() === $tableKey) {
                    return $table;
                }
            }
        }

        return null;
    }

    private function exportReplayFacts(array $values): array
    {
        foreach (['_table_export_prepare', '_table_export_download', '_table_export_clipboard', 'ajax_nonce', '_ajax', 'action', 'card_action'] as $key) {
            unset($values[$key]);
        }

        return $values;
    }

    private function replayValues(array $values, string $pageId): array
    {
        $values = $this->exportReplayFacts($values);
        $values['page'] = (string)($values['page'] ?? $pageId);

        return $values;
    }

    private function currentDeviceId(): string
    {
        return trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
    }

}
