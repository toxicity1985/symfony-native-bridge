<?php
// examples/02-todo-app/src/EventListener/TodoNativeListener.php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Todo;
use Doctrine\ORM\EntityManagerInterface;
use SymfonyNativeBridge\Attribute\AsNativeListener;
use SymfonyNativeBridge\Event\AppReadyEvent;
use SymfonyNativeBridge\Event\TrayMenuItemClickedEvent;
use SymfonyNativeBridge\Service\AppManager;
use SymfonyNativeBridge\Service\TrayManager;
use SymfonyNativeBridge\Service\WindowManager;
use SymfonyNativeBridge\ValueObject\MenuItem;

/**
 * Sets up the system tray on startup and reacts to menu clicks.
 *
 * The tray icon tooltip shows the number of pending todos.
 * The context menu offers quick-add and quick-view shortcuts.
 */
class TodoNativeListener
{
    public function __construct(
        private readonly TrayManager           $tray,
        private readonly WindowManager         $window,
        private readonly AppManager            $app,
        private readonly EntityManagerInterface $em,
    ) {}

    #[AsNativeListener(AppReadyEvent::class)]
    public function onReady(AppReadyEvent $event): void
    {
        $pending = $this->countPending();

        $this->tray->create(
            iconPath: __DIR__ . '/../../assets/todo-tray.png',
            tooltip:  $this->trayTooltip($pending),
            label:    'main',
        );

        $this->rebuildMenu($pending);
    }

    #[AsNativeListener(TrayMenuItemClickedEvent::class)]
    public function onMenuClick(TrayMenuItemClickedEvent $event): void
    {
        match ($event->menuItemId) {
            'show'        => $this->focusOrOpen('/todos'),
            'show_pending'=> $this->focusOrOpen('/todos?filter=pending'),
            'quit'        => $this->app->quit(),
            default       => null,
        };
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function focusOrOpen(string $path): void
    {
        $windows = $this->window->all();
        if (!empty($windows)) {
            $this->window->focus($windows[0]);
        } else {
            $this->window->open('http://127.0.0.1:8765' . $path);
        }
    }

    private function rebuildMenu(int $pending): void
    {
        $this->tray->menu('main', [
            new MenuItem(label: '📝 Todo App',      enabled: false),
            MenuItem::separator(),
            new MenuItem(label: '📋 Show all',       id: 'show'),
            new MenuItem(
                label:   "⏳ Pending ({$pending})",
                id:      'show_pending',
                enabled: $pending > 0,
            ),
            MenuItem::separator(),
            new MenuItem(label: '🚪 Quit', id: 'quit'),
        ]);
    }

    private function countPending(): int
    {
        return (int) $this->em
            ->getRepository(Todo::class)
            ->count(['done' => false]);
    }

    private function trayTooltip(int $pending): string
    {
        return $pending === 0
            ? 'Todo App — all done! 🎉'
            : "Todo App — {$pending} task(s) pending";
    }
}
