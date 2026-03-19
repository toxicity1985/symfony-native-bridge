<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use SymfonyNativeBridge\Driver\NativeDriverInterface;

readonly class AppManager
{
    public function __construct(
        private NativeDriverInterface $driver,
        private array                 $appConfig,
        private array                 $updaterConfig,
    ) {}

    public function quit(): void
    {
        $this->driver->quit();
    }

    public function relaunch(): void
    {
        $this->driver->relaunch();
    }

    public function getName(): string
    {
        return $this->appConfig['name'];
    }

    public function getVersion(): string
    {
        return $this->appConfig['version'];
    }

    public function getPath(string $name): string
    {
        return $this->driver->getPath($name);
    }

    public function openExternal(string $url): void
    {
        $this->driver->openExternal($url);
    }

    public function openPath(string $path): void
    {
        $this->driver->openPath($path);
    }

    public function trashItem(string $path): void
    {
        $this->driver->trashItem($path);
    }

    public function showItemInFolder(string $path): void
    {
        $this->driver->showItemInFolder($path);
    }

    public function checkForUpdates(): void
    {
        if (!($this->updaterConfig['enabled'] ?? false)) {
            throw new \RuntimeException('Auto-updater is disabled. Enable it in symfony_native_bridge.yaml');
        }

        $this->driver->checkForUpdates();
    }

    public function installUpdate(): void
    {
        $this->driver->installUpdate();
    }
}
