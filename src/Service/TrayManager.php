<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\ValueObject\MenuItem;

class TrayManager
{
    /** @var array<string, string> label => trayId */
    private array $trays = [];

    public function __construct(
        private readonly NativeDriverInterface $driver,
    ) {}

    public function create(string $iconPath, string $tooltip = '', string $label = 'default'): string
    {
        $trayId              = $this->driver->createTray($iconPath, $tooltip);
        $this->trays[$label] = $trayId;

        return $trayId;
    }

    /**
     * @param MenuItem[] $items
     */
    public function menu(string $label, array $items): void
    {
        $trayId = $this->resolve($label);
        $this->driver->setTrayMenu($trayId, $items);
    }

    public function tooltip(string $label, string $tooltip): void
    {
        $this->driver->setTrayTooltip($this->resolve($label), $tooltip);
    }

    public function destroy(string $label = 'default'): void
    {
        $trayId = $this->resolve($label);
        $this->driver->destroyTray($trayId);
        unset($this->trays[$label]);
    }

    /**
     * Re-synchronise in-memory state with the native runtime.
     *
     * Call this in your AppReadyEvent listener after a hot-reload or when the
     * PHP process restarts while Electron stays alive. Trays that were created
     * before the restart will be tracked again using their ID as label (since
     * original labels are PHP-only and cannot be recovered).
     *
     * Already-tracked trays are preserved as-is.
     */
    public function syncFromRuntime(): void
    {
        $runtimeIds = $this->driver->listTrays();
        $trackedIds = array_values($this->trays);

        foreach ($runtimeIds as $trayId) {
            if (!in_array($trayId, $trackedIds, true)) {
                $this->trays[$trayId] = $trayId;
            }
        }
    }

    private function resolve(string $label): string
    {
        if (!isset($this->trays[$label])) {
            throw new \RuntimeException("No tray registered with label '{$label}'");
        }

        return $this->trays[$label];
    }
}
