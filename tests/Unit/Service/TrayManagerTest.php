<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\TrayManager;
use SymfonyNativeBridge\ValueObject\MenuItem;

class TrayManagerTest extends TestCase
{
    private NativeDriverInterface&MockObject $driver;
    private TrayManager $manager;

    protected function setUp(): void
    {
        $this->driver  = $this->createMock(NativeDriverInterface::class);
        $this->manager = new TrayManager($this->driver);
    }

    public function testCreateRegistersAndReturnsId(): void
    {
        $this->driver->method('createTray')->willReturn('tray_1');
        $id = $this->manager->create('/icon.png', 'Tooltip', 'main');
        $this->assertSame('tray_1', $id);
    }

    public function testMenuOnUnknownLabelThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager->menu('nonexistent', []);
    }

    public function testMenuDelegatesToDriver(): void
    {
        $this->driver->method('createTray')->willReturn('tray_1');
        $this->manager->create('/icon.png', '', 'main');

        $items = [new MenuItem(label: 'Quit', id: 'quit')];
        $this->driver->expects($this->once())->method('setTrayMenu')->with('tray_1', $items);
        $this->manager->menu('main', $items);
    }

    public function testDestroyRemovesLabel(): void
    {
        $this->driver->method('createTray')->willReturn('tray_1');
        $this->manager->create('/icon.png', '', 'main');

        $this->driver->expects($this->once())->method('destroyTray')->with('tray_1');
        $this->manager->destroy('main');

        // After destroy, label is gone → should throw
        $this->expectException(\RuntimeException::class);
        $this->manager->tooltip('main', 'x');
    }
}
