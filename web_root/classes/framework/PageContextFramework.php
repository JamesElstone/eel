<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

abstract class PageContextFramework extends PageBaseFramework
{
    public function services(): array
    {
        return [];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        
        $context = [
            'page' => [
                'page_id' => $this->id(),
                'page_cards' => $this->cards(),
            ],
        ];

        return array_merge($context, $this->moduleContext($request, $services, $actionResult, $context));
    }

    

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        return [];
    }
}
