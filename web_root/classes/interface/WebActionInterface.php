<?php
declare(strict_types=1);

interface WebActionInterface
{
    public function handle(WebRequest $request, WebPageService $services): WebActionResult;
}
