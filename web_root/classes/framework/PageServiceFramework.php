<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class PageServiceFramework
{
    private ?SiteContextCoordinatorFramework $siteContextCoordinator = null;
    private ?ActionProgressFramework $actionProgress = null;

    public function __construct(
        private readonly AppService $appServices,
        ?SiteContextCoordinatorFramework $siteContextCoordinator = null,
        ?ActionProgressFramework $actionProgress = null
    ) {
        $this->siteContextCoordinator = $siteContextCoordinator;
        $this->actionProgress = $actionProgress;
    }

    public function get(string $serviceClass): object
    {
        $serviceClass = ltrim(trim($serviceClass), '\\');

        if ($serviceClass === '') {
            throw new InvalidArgumentException('Requested page service class must not be empty.');
        }

        try {
            return $this->appServices->get($serviceClass);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Page-defined service [' . $serviceClass . '] is unavailable; service ' . $serviceClass . ' was not resolved.',
                0,
                $exception
            );
        }
    }

    public function has(string $serviceClass): bool
    {
        $serviceClass = ltrim(trim($serviceClass), '\\');

        if ($serviceClass === '') {
            return false;
        }

        try {
            $this->appServices->get($serviceClass);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function setSiteContextCoordinator(SiteContextCoordinatorFramework $siteContextCoordinator): void
    {
        $this->siteContextCoordinator = $siteContextCoordinator;
    }

    public function siteContextCoordinator(): SiteContextCoordinatorFramework
    {
        if ($this->siteContextCoordinator === null) {
            $this->siteContextCoordinator = SiteContextCoordinatorFramework::fromConfiguration($this->appServices);
        }

        return $this->siteContextCoordinator;
    }

    public function actionProgress(): ActionProgressFramework
    {
        if ($this->actionProgress === null) {
            $this->actionProgress = new ActionProgressFramework();
        }

        return $this->actionProgress;
    }
}
