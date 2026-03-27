<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\WindowManager;
use SymfonyNativeBridge\ValueObject\WindowOptions;

class WindowManagerTest extends TestCase
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

    public function testOpenUsesDefaultOptions(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('openWindow')
            ->willReturn('win_1');

        $id = $this->manager->open('http://localhost');
        $this->assertSame('win_1', $id);
    }

    public function testOpenWithExplicitOptions(): void
    {
        $options = new WindowOptions(width: 640, height: 480, title: 'Custom');
        $this->driver->method('openWindow')->willReturn('win_2');

        $id = $this->manager->open('http://localhost', $options);
        $this->assertSame('win_2', $id);
    }

    public function testCloseAllClosesEachWindow(): void
    {
        $this->driver->method('openWindow')->willReturnOnConsecutiveCalls('win_a', 'win_b');
        $this->manager->open('http://localhost/a');
        $this->manager->open('http://localhost/b');

        $this->driver->expects($this->exactly(2))->method('closeWindow');
        $this->manager->closeAll();
    }

    public function testSetTitleDelegates(): void
    {
        $this->driver->expects($this->once())->method('setWindowTitle')->with('win_1', 'New Title');
        $this->manager->setTitle('win_1', 'New Title');
    }
}
