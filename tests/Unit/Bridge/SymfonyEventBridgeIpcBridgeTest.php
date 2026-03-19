<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Bridge;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use SymfonyNativeBridge\Bridge\SymfonyEventBridgeIpcBridge;
use SymfonyNativeBridge\Event\AppReadyEvent;
use SymfonyNativeBridge\Event\TrayClickedEvent;
use SymfonyNativeBridge\Event\TrayMenuItemClickedEvent;
use SymfonyNativeBridge\Event\WindowClosedEvent;
use SymfonyNativeBridge\Event\WindowFocusedEvent;
use SymfonyNativeBridge\Event\WindowResizedEvent;
use SymfonyNativeBridge\Event\UpdateAvailableEvent;
use SymfonyNativeBridge\Event\AppBeforeQuitEvent;

/**
 * Tests that push messages from the native runtime are correctly
 * translated into Symfony events and dispatched.
 */
class SymfonyEventBridgeIpcBridgeTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private TestableIpcBridge $bridge;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->bridge     = new TestableIpcBridge('electron', $this->dispatcher);
    }

    public function testAppReadyEventIsDispatched(): void
    {
        $received = null;
        $this->dispatcher->addListener(AppReadyEvent::NAME, function (AppReadyEvent $e) use (&$received) {
            $received = $e;
        });

        $this->bridge->simulatePush(['event' => 'app.ready', 'payload' => []]);

        $this->assertInstanceOf(AppReadyEvent::class, $received);
    }

    public function testWindowFocusedEventCarriesWindowId(): void
    {
        $received = null;
        $this->dispatcher->addListener(WindowFocusedEvent::NAME, function (WindowFocusedEvent $e) use (&$received) {
            $received = $e;
        });

        $this->bridge->simulatePush(['event' => 'window.focused', 'payload' => ['windowId' => 'win_99']]);

        $this->assertInstanceOf(WindowFocusedEvent::class, $received);
        $this->assertSame('win_99', $received->windowId);
    }

    public function testWindowResizedEventCarriesDimensions(): void
    {
        $received = null;
        $this->dispatcher->addListener(WindowResizedEvent::NAME, function (WindowResizedEvent $e) use (&$received) {
            $received = $e;
        });

        $this->bridge->simulatePush([
            'event'   => 'window.resized',
            'payload' => ['windowId' => 'win_1', 'width' => 1024, 'height' => 768],
        ]);

        $this->assertSame(1024, $received->width);
        $this->assertSame(768, $received->height);
    }

    public function testTrayClickedEventCarriesButton(): void
    {
        $received = null;
        $this->dispatcher->addListener(TrayClickedEvent::NAME, function (TrayClickedEvent $e) use (&$received) {
            $received = $e;
        });

        $this->bridge->simulatePush([
            'event'   => 'tray.clicked',
            'payload' => ['trayId' => 'tray_1', 'button' => 'right'],
        ]);

        $this->assertSame('right', $received->button);
        $this->assertSame('tray_1', $received->trayId);
    }

    public function testTrayMenuItemClickedEventCarriesMenuItemId(): void
    {
        $received = null;
        $this->dispatcher->addListener(TrayMenuItemClickedEvent::NAME, function (TrayMenuItemClickedEvent $e) use (&$received) {
            $received = $e;
        });

        $this->bridge->simulatePush([
            'event'   => 'tray.menu_item_clicked',
            'payload' => ['trayId' => 'tray_1', 'menuItemId' => 'quit'],
        ]);

        $this->assertSame('quit', $received->menuItemId);
    }

    public function testUpdateAvailableEventCarriesVersionAndNotes(): void
    {
        $received = null;
        $this->dispatcher->addListener(UpdateAvailableEvent::NAME, function (UpdateAvailableEvent $e) use (&$received) {
            $received = $e;
        });

        $this->bridge->simulatePush([
            'event'   => 'updater.update_available',
            'payload' => ['version' => '2.0.0', 'releaseNotes' => 'Bug fixes'],
        ]);

        $this->assertSame('2.0.0', $received->version);
        $this->assertSame('Bug fixes', $received->releaseNotes);
    }

    public function testUnknownEventIsIgnoredSilently(): void
    {
        // Should not throw
        $this->bridge->simulatePush(['event' => 'some.unknown.event', 'payload' => []]);
        $this->assertTrue(true); // no exception = pass
    }

    public function testMissingEventKeyIsIgnored(): void
    {
        $this->bridge->simulatePush(['payload' => []]);
        $this->assertTrue(true);
    }
}

/**
 * Exposes dispatchPushEvent() as public for testing.
 */
class TestableIpcBridge extends SymfonyEventBridgeIpcBridge
{
    public function simulatePush(array $msg): void
    {
        $this->dispatchPushEvent($msg);
    }
}
