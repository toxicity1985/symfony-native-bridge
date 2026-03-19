<?php
// examples/04-system-monitor/src/EventListener/MonitorNativeListener.php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\SystemStatsService;
use SymfonyNativeBridge\Attribute\AsNativeListener;
use SymfonyNativeBridge\Event\AppReadyEvent;
use SymfonyNativeBridge\Event\TrayMenuItemClickedEvent;
use SymfonyNativeBridge\Event\WindowFocusedEvent;
use SymfonyNativeBridge\Event\WindowBlurredEvent;
use SymfonyNativeBridge\Service\AppManager;
use SymfonyNativeBridge\Service\NotificationManager;
use SymfonyNativeBridge\Service\TrayManager;
use SymfonyNativeBridge\Service\WindowManager;
use SymfonyNativeBridge\ValueObject\MenuItem;

class MonitorNativeListener
{
    private bool $alertSent = false;

    public function __construct(
        private readonly TrayManager        $tray,
        private readonly WindowManager      $window,
        private readonly AppManager         $app,
        private readonly NotificationManager $notification,
        private readonly SystemStatsService $stats,
    ) {}

    #[AsNativeListener(AppReadyEvent::class)]
    public function onReady(AppReadyEvent $event): void
    {
        $cpu = $this->stats->getStats()['cpu'];

        $this->tray->create(
            iconPath: __DIR__ . '/../../assets/monitor-tray.png',
            tooltip:  "System Monitor — CPU: {$cpu}%",
            label:    'main',
        );

        $this->rebuildMenu($cpu);
    }

    #[AsNativeListener(TrayMenuItemClickedEvent::class)]
    public function onMenuClick(TrayMenuItemClickedEvent $event): void
    {
        match ($event->menuItemId) {
            'show'  => $this->focusOrOpen(),
            'quit'  => $this->app->quit(),
            default => null,
        };
    }

    /**
     * When the window gains focus, update the tray tooltip with fresh stats.
     * This simulates "resume live update" on focus.
     */
    #[AsNativeListener(WindowFocusedEvent::class)]
    public function onFocus(WindowFocusedEvent $event): void
    {
        $stats = $this->stats->getStats();
        $cpu   = $stats['cpu'];

        $this->tray->tooltip('main', "System Monitor — CPU: {$cpu}%");

        // Alert if CPU is critically high (only once per session)
        if ($cpu > 90 && !$this->alertSent) {
            $this->alertSent = true;
            $this->notification->send(
                title: '⚠️ High CPU Usage',
                body:  "CPU at {$cpu}% — check running processes.",
            );
        } elseif ($cpu < 70) {
            $this->alertSent = false; // reset so it can alert again
        }

        $this->rebuildMenu($cpu);
    }

    /**
     * When the window loses focus, update the tray with a "paused" hint.
     */
    #[AsNativeListener(WindowBlurredEvent::class)]
    public function onBlur(WindowBlurredEvent $event): void
    {
        $this->tray->tooltip('main', 'System Monitor (background)');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function focusOrOpen(): void
    {
        $windows = $this->window->all();
        if (!empty($windows)) {
            $this->window->focus($windows[0]);
        } else {
            $this->window->open('http://127.0.0.1:8765');
        }
    }

    private function rebuildMenu(float $cpu): void
    {
        $bar = $this->cpuBar($cpu);

        $this->tray->menu('main', [
            new MenuItem(label: "🖥 System Monitor",    enabled: false),
            new MenuItem(label: "CPU  {$bar} {$cpu}%",  enabled: false),
            MenuItem::separator(),
            new MenuItem(label: '🔍 Open',  id: 'show'),
            MenuItem::separator(),
            new MenuItem(label: '🚪 Quit',  id: 'quit'),
        ]);
    }

    private function cpuBar(float $percent): string
    {
        $filled = (int) round($percent / 10);
        return str_repeat('█', $filled) . str_repeat('░', 10 - $filled);
    }
}
