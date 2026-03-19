<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\ValueObject\WindowOptions;

class WindowManager
{
    /** @var array<string, string> label => windowId */
    private array $windows = [];

    public function __construct(
        private readonly NativeDriverInterface $driver,
        private readonly array $defaultConfig,
    ) {}

    public function open(string $url, ?WindowOptions $options = null): string
    {
        $options ??= WindowOptions::fromArray($this->defaultConfig);
        $windowId       = $this->driver->openWindow($url, $options);
        $this->windows[$options->label ?? $windowId] = $windowId;

        return $windowId;
    }

    public function close(string $windowId): void
    {
        $this->driver->closeWindow($windowId);
        $this->windows = array_filter($this->windows, fn($id) => $id !== $windowId);
    }

    public function closeAll(): void
    {
        foreach ($this->windows as $windowId) {
            $this->driver->closeWindow($windowId);
        }
        $this->windows = [];
    }

    public function setTitle(string $windowId, string $title): void
    {
        $this->driver->setWindowTitle($windowId, $title);
    }

    public function resize(string $windowId, int $width, int $height): void
    {
        $this->driver->resizeWindow($windowId, $width, $height);
    }

    public function minimize(string $windowId): void
    {
        $this->driver->minimizeWindow($windowId);
    }

    public function maximize(string $windowId): void
    {
        $this->driver->maximizeWindow($windowId);
    }

    public function fullscreen(string $windowId, bool $enabled = true): void
    {
        $this->driver->setFullscreen($windowId, $enabled);
    }

    public function focus(string $windowId): void
    {
        $this->driver->focusWindow($windowId);
    }

    /** @return string[] */
    public function all(): array
    {
        return $this->driver->listWindows();
    }
}