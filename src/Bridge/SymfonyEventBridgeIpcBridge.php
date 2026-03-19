<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Bridge;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use SymfonyNativeBridge\Event\AppActivatedEvent;
use SymfonyNativeBridge\Event\AppBeforeQuitEvent;
use SymfonyNativeBridge\Event\AppReadyEvent;
use SymfonyNativeBridge\Event\NotificationClickedEvent;
use SymfonyNativeBridge\Event\TrayClickedEvent;
use SymfonyNativeBridge\Event\TrayMenuItemClickedEvent;
use SymfonyNativeBridge\Event\UpdateAvailableEvent;
use SymfonyNativeBridge\Event\UpdateDownloadedEvent;
use SymfonyNativeBridge\Event\WindowBlurredEvent;
use SymfonyNativeBridge\Event\WindowClosedEvent;
use SymfonyNativeBridge\Event\WindowFocusedEvent;
use SymfonyNativeBridge\Event\WindowMovedEvent;
use SymfonyNativeBridge\Event\WindowResizedEvent;

/**
 * Extends IpcBridge to forward push events from the native runtime
 * into Symfony's EventDispatcher.
 *
 * Map: native event name => factory closure
 */
class SymfonyEventBridgeIpcBridge extends IpcBridge
{
    /** @var array<string, callable> */
    private array $eventMap;

    public function __construct(
        string $driver,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct($driver);

        $this->eventMap = [
            'window.focused'              => fn(array $p) => new WindowFocusedEvent($p['windowId']),
            'window.blurred'              => fn(array $p) => new WindowBlurredEvent($p['windowId']),
            'window.closed'              => fn(array $p) => new WindowClosedEvent($p['windowId']),
            'window.resized'             => fn(array $p) => new WindowResizedEvent($p['windowId'], $p['width'], $p['height']),
            'window.moved'               => fn(array $p) => new WindowMovedEvent($p['windowId'], $p['x'], $p['y']),
            'tray.clicked'               => fn(array $p) => new TrayClickedEvent($p['trayId'], $p['button'] ?? 'left'),
            'tray.menu_item_clicked'     => fn(array $p) => new TrayMenuItemClickedEvent($p['trayId'], $p['menuItemId']),
            'app.ready'                  => fn(array $p) => new AppReadyEvent(),
            'app.before_quit'            => fn(array $p) => new AppBeforeQuitEvent(),
            'app.activated'              => fn(array $p) => new AppActivatedEvent($p['hasVisibleWindows'] ?? false),
            'updater.update_available'   => fn(array $p) => new UpdateAvailableEvent($p['version'], $p['releaseNotes'] ?? null),
            'updater.update_downloaded'  => fn(array $p) => new UpdateDownloadedEvent($p['version']),
            'notification.clicked'       => fn(array $p) => new NotificationClickedEvent($p['title'], $p['action'] ?? null),
        ];
    }

    protected function dispatchPushEvent(array $msg): void
    {
        $eventName = $msg['event'] ?? null;
        $payload   = $msg['payload'] ?? [];

        if ($eventName === null || !isset($this->eventMap[$eventName])) {
            return;
        }

        $event = ($this->eventMap[$eventName])($payload);
        $this->eventDispatcher->dispatch($event, $event::getEventName());

        // Special case: if AppBeforeQuitEvent was prevented, send cancel back
        if ($event instanceof AppBeforeQuitEvent && $event->isPrevented()) {
            $this->send('app.cancelQuit');
        }
    }
}
