<?php
declare(strict_types=1);

interface ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework;
}
