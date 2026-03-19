<?php

declare(strict_types=1);

namespace App\Native;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use SymfonyNativeBridge\Attribute\AsNativeListener;
use SymfonyNativeBridge\Event\AppReadyEvent;
use SymfonyNativeBridge\Event\AppBeforeQuitEvent;
use SymfonyNativeBridge\Event\TrayClickedEvent;
use SymfonyNativeBridge\Event\TrayMenuItemClickedEvent;
use SymfonyNativeBridge\Event\UpdateAvailableEvent;
use SymfonyNativeBridge\Service\AppManager;
use SymfonyNativeBridge\Service\DialogManager;
use SymfonyNativeBridge\Service\NotificationManager;
use SymfonyNativeBridge\Service\TrayManager;
use SymfonyNativeBridge\Service\WindowManager;
use SymfonyNativeBridge\ValueObject\MenuItem;

/**
 * Example: wire up a system tray icon with a context menu when the app starts.
 *
 * All methods are discovered automatically via #[AsNativeListener] —
 * no services.yaml needed.
 */
class AppBootstrap
{
    public function __construct(
        private readonly TrayManager $tray,
        private readonly WindowManager $window,
        private readonly NotificationManager $notification,
        private readonly DialogManager $dialog,
        private readonly AppManager $app,
        #[Autowire('%symfony_native_bridge.app%')]
        private readonly array $appConfig,
    ) {}

    #[AsNativeListener(AppReadyEvent::class)]
    public function onReady(AppReadyEvent $event): void
    {
        // Create the system tray icon
        $this->tray->create(
            iconPath: dirname(__DIR__, 2) . '/assets/tray-icon.png',
            tooltip:  $this->appConfig['name'],
            label:    'main',
        );

        // Build the context menu
        $this->tray->menu('main', [
            new MenuItem(label: $this->appConfig['name'], enabled: false),
            MenuItem::separator(),
            new MenuItem(label: 'Open',         id: 'open',         accelerator: 'CmdOrCtrl+O'),
            new MenuItem(label: 'New Window',   id: 'new_window',   accelerator: 'CmdOrCtrl+N'),
            MenuItem::separator(),
            new MenuItem(label: 'Check for updates', id: 'check_updates'),
            MenuItem::separator(),
            new MenuItem(label: 'Quit',         id: 'quit',         role: 'quit', accelerator: 'CmdOrCtrl+Q'),
        ]);

        // Greet the user
        $this->notification->send(
            title: $this->appConfig['name'],
            body:  'App is running. Right-click the tray icon for options.',
        );
    }

    #[AsNativeListener(TrayClickedEvent::class)]
    public function onTrayClick(TrayClickedEvent $event): void
    {
        // Left-click on tray: bring the main window to focus
        if ($event->button === 'left') {
            $windows = $this->window->all();
            if (!empty($windows)) {
                $this->window->focus($windows[0]);
            }
        }
    }

    #[AsNativeListener(TrayMenuItemClickedEvent::class)]
    public function onMenuItemClick(TrayMenuItemClickedEvent $event): void
    {
        match ($event->menuItemId) {
            'open'          => $this->window->focus($this->window->all()[0] ?? ''),
            'new_window'    => $this->window->open('http://127.0.0.1:8765'),
            'check_updates' => $this->app->checkForUpdates(),
            'quit'          => $this->app->quit(),
            default         => null,
        };
    }

    #[AsNativeListener(AppBeforeQuitEvent::class)]
    public function onBeforeQuit(AppBeforeQuitEvent $event): void
    {
        // Ask the user to confirm before quitting
        $confirmed = $this->dialog->confirm(
            message: 'Are you sure you want to quit?',
            title:   'Quit ' . $this->appConfig['name'],
        );

        if (!$confirmed) {
            $event->prevent(); // cancels the quit
        }
    }

    #[AsNativeListener(UpdateAvailableEvent::class)]
    public function onUpdateAvailable(UpdateAvailableEvent $event): void
    {
        $shouldInstall = $this->dialog->ask(
            message: "Version {$event->version} is available. Install now?",
            title:   'Update Available',
            buttons: ['Later', 'Install'],
        );

        if ($shouldInstall === 1) {
            $this->app->installUpdate();
        }
    }
}
