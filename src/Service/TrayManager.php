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

    private function resolve(string $label): string
    {
        if (!isset($this->trays[$label])) {
            throw new \RuntimeException("No tray registered with label '{$label}'");
        }

        return $this->trays[$label];
    }
}
