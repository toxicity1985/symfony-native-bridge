<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\DialogManager;

class DialogManagerTest extends TestCase
{
    private NativeDriverInterface&MockObject $driver;
    private DialogManager $manager;

    protected function setUp(): void
    {
        $this->driver  = $this->createMock(NativeDriverInterface::class);
        $this->manager = new DialogManager($this->driver);
    }

    public function testConfirmReturnsTrueOnOk(): void
    {
        $this->driver->method('showMessageBox')->willReturn(1);
        $this->assertTrue($this->manager->confirm('Are you sure?'));
    }

    public function testConfirmReturnsFalseOnCancel(): void
    {
        $this->driver->method('showMessageBox')->willReturn(0);
        $this->assertFalse($this->manager->confirm('Are you sure?'));
    }

    public function testOpenFileReturnsPaths(): void
    {
        $this->driver->method('showOpenDialog')->willReturn(['/a/b.txt']);
        $result = $this->manager->openFile();
        $this->assertSame(['/a/b.txt'], $result);
    }

    public function testSaveFileReturnsPath(): void
    {
        $this->driver->method('showSaveDialog')->willReturn('/save/here.txt');
        $this->assertSame('/save/here.txt', $this->manager->saveFile());
    }

    public function testOpenFolderSetsCorrectProperty(): void
    {
        $this->driver->expects($this->once())
            ->method('showOpenDialog')
            ->with($this->callback(fn($o) => in_array('openDirectory', $o->properties)))
            ->willReturn(['/home/user']);

        $this->manager->openFolder();
    }
}
