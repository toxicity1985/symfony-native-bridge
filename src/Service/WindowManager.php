<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyNativeBridge\Attribute\AsNativeListener;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Event\WindowClosedEvent;
use SymfonyNativeBridge\Exception\NativeException;
use SymfonyNativeBridge\ValueObject\WindowOptions;

class WindowManager
{
    /** @var array<string, string> label => windowId */
    private array $windows = [];

    public function __construct(
        private readonly NativeDriverInterface  $driver,
        private readonly array                  $defaultConfig,
        private readonly ?NativeRouteRegistry   $routeRegistry = null,
        private readonly ?UrlGeneratorInterface $urlGenerator  = null,
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

    /**
     * Automatically remove a window from the tracked state when Electron
     * closes it (user clicks ✕ or the window is destroyed programmatically).
     *
     * Registered automatically by NativeListenerPass via the attribute below.
     */
    #[AsNativeListener(WindowClosedEvent::class)]
    public function onWindowClosed(WindowClosedEvent $event): void
    {
        $this->windows = array_filter(
            $this->windows,
            fn(string $id) => $id !== $event->windowId,
        );
    }

    /**
     * Opens a native window by its #[NativeRoute] name.
     *
     * The Symfony route is resolved to an absolute URL via the UrlGenerator,
     * then merged with the window options declared on the attribute.
     * Pass $override to fully replace the attribute options at call site.
     *
     * @param array<string, mixed> $parameters Route parameters forwarded to the UrlGenerator
     * @throws NativeException When the native route name is unknown
     * @throws \LogicException When the bundle is not configured with the router service
     */
    public function openRoute(string $name, array $parameters = [], ?WindowOptions $override = null): string
    {
        if ($this->routeRegistry === null || $this->urlGenerator === null) {
            throw new \LogicException(
                'openRoute() requires symfony/routing. Make sure the router service is available.'
            );
        }

        ['route' => $routeName, 'options' => $optionsOverrides] = $this->routeRegistry->get($name);

        $url = $this->urlGenerator->generate($routeName, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);

        if ($override !== null) {
            return $this->open($url, $override);
        }

        // Merge attribute overrides on top of the bundle defaults
        $merged  = $optionsOverrides + $this->defaultConfig;
        $options = WindowOptions::fromArray($merged);

        return $this->open($url, $options);
    }

    /**
     * Re-synchronise in-memory state with the native runtime.
     *
     * Call this in your AppReadyEvent listener after a hot-reload or when the
     * PHP process restarts while Electron stays alive. Windows that were
     * opened before the restart will be tracked again, using their ID as label
     * (since original labels are PHP-only and cannot be recovered).
     *
     * Already-tracked windows are preserved as-is.
     */
    public function syncFromRuntime(): void
    {
        $runtimeIds = $this->driver->listWindows();

        $trackedIds = array_values($this->windows);

        foreach ($runtimeIds as $windowId) {
            if (!in_array($windowId, $trackedIds, true)) {
                $this->windows[$windowId] = $windowId;
            }
        }
    }
}