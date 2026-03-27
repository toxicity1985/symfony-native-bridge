<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Event\WindowClosedEvent;
use SymfonyNativeBridge\Service\WindowManager;

class WindowManagerSyncTest extends TestCase
{
    private NativeDriverInterface&MockObject $driver;
    private WindowManager $manager;

    protected function setUp(): void
    {
        $this->driver  = $this->createMock(NativeDriverInterface::class);
        $this->manager = new WindowManager($this->driver, [
            'width' => 1200, 'height' => 800,
            'min_width' => 400, 'min_height' => 300,
            'resizable' => true, 'fullscreen' => false,
            'frame' => true, 'transparent' => false,
            'background_color' => '#fff',
        ]);
    }

    // ── syncFromRuntime ───────────────────────────────────────────────────────

    public function testSyncFromRuntimeAddsUnknownWindowIds(): void
    {
        $this->driver->method('listWindows')->willReturn(['win_1', 'win_2']);

        $this->manager->syncFromRuntime();

        // After sync, closeAll should close both windows
        $this->driver->expects($this->exactly(2))->method('closeWindow');
        $this->manager->closeAll();
    }

    public function testSyncFromRuntimeDoesNotDuplicateAlreadyTrackedWindows(): void
    {
        // Open one window the normal way
        $this->driver->method('openWindow')->willReturn('win_1');
        $this->manager->open('http://localhost');

        // Runtime reports the same window plus a new one
        $this->driver->method('listWindows')->willReturn(['win_1', 'win_orphan']);
        $this->manager->syncFromRuntime();

        // closeAll must close exactly 2 unique windows
        $this->driver->expects($this->exactly(2))->method('closeWindow');
        $this->manager->closeAll();
    }

    public function testSyncFromRuntimeHandlesEmptyRuntime(): void
    {
        $this->driver->method('listWindows')->willReturn([]);

        $this->manager->syncFromRuntime(); // must not throw

        $this->driver->expects($this->never())->method('closeWindow');
        $this->manager->closeAll();
    }

    // ── onWindowClosed ────────────────────────────────────────────────────────

    public function testOnWindowClosedRemovesWindowFromTrackedState(): void
    {
        $this->driver->method('openWindow')->willReturn('win_42');
        $this->manager->open('http://localhost');

        // Simulate Electron closing the window
        $this->manager->onWindowClosed(new WindowClosedEvent('win_42'));

        // closeAll should now close nothing
        $this->driver->expects($this->never())->method('closeWindow');
        $this->manager->closeAll();
    }

    public function testOnWindowClosedIgnoresUnknownWindowId(): void
    {
        $this->driver->method('openWindow')->willReturn('win_1');
        $this->manager->open('http://localhost');

        // Closing a window we never tracked must not throw
        $this->manager->onWindowClosed(new WindowClosedEvent('win_unknown'));

        // win_1 is still tracked
        $this->driver->expects($this->once())->method('closeWindow')->with('win_1');
        $this->manager->closeAll();
    }

    public function testOnWindowClosedHandlesLabeledWindow(): void
    {
        $this->driver->method('openWindow')->willReturn('win_99');
        $options = new \SymfonyNativeBridge\ValueObject\WindowOptions(label: 'settings');
        $this->manager->open('http://localhost/settings', $options);

        // Window is tracked under label 'settings' → windowId 'win_99'
        $this->manager->onWindowClosed(new WindowClosedEvent('win_99'));

        $this->driver->expects($this->never())->method('closeWindow');
        $this->manager->closeAll();
    }
}
