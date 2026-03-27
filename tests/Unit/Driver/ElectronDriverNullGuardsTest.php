<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Driver;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Bridge\IpcBridge;
use SymfonyNativeBridge\Driver\ElectronDriver;
use SymfonyNativeBridge\ValueObject\DialogOptions;

/**
 * Verifies that ElectronDriver handles null/unexpected IPC responses
 * gracefully (no TypeError, no undefined index warnings).
 */
class ElectronDriverNullGuardsTest extends TestCase
{
    private IpcBridge&MockObject $bridge;
    private ElectronDriver $driver;

    protected function setUp(): void
    {
        $this->bridge = $this->createMock(IpcBridge::class);
        $this->driver = new ElectronDriver($this->bridge, []);
    }

    // ── showOpenDialog ────────────────────────────────────────────────────────

    public function testShowOpenDialogReturnsNullWhenIpcReturnsNull(): void
    {
        $this->bridge->method('call')->willReturn(null);

        $result = $this->driver->showOpenDialog(new DialogOptions());

        $this->assertNull($result);
    }

    public function testShowOpenDialogReturnsNullWhenCanceled(): void
    {
        $this->bridge->method('call')->willReturn(['canceled' => true, 'filePaths' => []]);

        $result = $this->driver->showOpenDialog(new DialogOptions());

        $this->assertNull($result);
    }

    public function testShowOpenDialogReturnsPathsOnSuccess(): void
    {
        $this->bridge->method('call')->willReturn([
            'canceled'  => false,
            'filePaths' => ['/a/b.txt', '/c/d.txt'],
        ]);

        $result = $this->driver->showOpenDialog(new DialogOptions());

        $this->assertSame(['/a/b.txt', '/c/d.txt'], $result);
    }

    public function testShowOpenDialogReturnsNullWhenFilePathsIsMissing(): void
    {
        $this->bridge->method('call')->willReturn(['canceled' => false]);

        $result = $this->driver->showOpenDialog(new DialogOptions());

        $this->assertNull($result);
    }

    // ── showSaveDialog ────────────────────────────────────────────────────────

    public function testShowSaveDialogReturnsNullWhenIpcReturnsNull(): void
    {
        $this->bridge->method('call')->willReturn(null);

        $result = $this->driver->showSaveDialog(new DialogOptions());

        $this->assertNull($result);
    }

    public function testShowSaveDialogReturnsNullWhenCanceled(): void
    {
        $this->bridge->method('call')->willReturn(['canceled' => true, 'filePath' => '']);

        $result = $this->driver->showSaveDialog(new DialogOptions());

        $this->assertNull($result);
    }

    public function testShowSaveDialogReturnsPathOnSuccess(): void
    {
        $this->bridge->method('call')->willReturn(['canceled' => false, 'filePath' => '/save/here.txt']);

        $result = $this->driver->showSaveDialog(new DialogOptions());

        $this->assertSame('/save/here.txt', $result);
    }

    // ── showMessageBox ────────────────────────────────────────────────────────

    public function testShowMessageBoxReturnsZeroWhenIpcReturnsNull(): void
    {
        $this->bridge->method('call')->willReturn(null);

        $result = $this->driver->showMessageBox('Title', 'Message');

        $this->assertSame(0, $result);
    }

    public function testShowMessageBoxReturnsButtonIndex(): void
    {
        $this->bridge->method('call')->willReturn(['response' => 2]);

        $result = $this->driver->showMessageBox('Title', 'Message', ['Cancel', 'No', 'Yes']);

        $this->assertSame(2, $result);
    }

    // ── listWindows / listTrays ───────────────────────────────────────────────

    public function testListWindowsReturnsEmptyArrayWhenIpcReturnsNull(): void
    {
        $this->bridge->method('call')->willReturn(null);

        $this->assertSame([], $this->driver->listWindows());
    }

    public function testListTraysReturnsEmptyArrayWhenIpcReturnsNull(): void
    {
        $this->bridge->method('call')->willReturn(null);

        $this->assertSame([], $this->driver->listTrays());
    }
}
