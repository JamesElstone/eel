<?php
declare(strict_types=1);

interface WebPageInterface
{
    public function id(): string;

    public function title(): string;

    public function subtitle(): string;

    public function services(): array;

    public function cards(): array;

    public function handle(WebRequest $request, WebPageServices $services): WebResponse;
}
