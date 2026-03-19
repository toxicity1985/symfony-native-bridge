<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Driver;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Bridge\IpcBridge;
use SymfonyNativeBridge\Driver\ElectronDriver;
use SymfonyNativeBridge\ValueObject\DialogOptions;
use SymfonyNativeBridge\ValueObject\MenuItem;
use SymfonyNativeBridge\ValueObject\NotificationOptions;
use SymfonyNativeBridge\ValueObject\WindowOptions;

class ElectronDriverTest extends TestCase
{
    private IpcBridge&MockObject $ipc;
    private ElectronDriver $driver;

    protected function setUp(): void
    {
        $this->ipc = $this->createMock(IpcBridge::class);
        $this->driver = new ElectronDriver($this->ipc, [
            'app' => ['name' => 'Test App', 'version' => '1.0.0'],
        ]);
    }

    // ── Window ───────────────────────────────────────────────────────────────

    public function testOpenWindowCallsIpcAndReturnsId(): void
    {
        $this->ipc
            ->expects($this->once())
            ->method('call')
            ->with('window.open', $this->arrayHasKey('url'))
            ->willReturn('win_123');

        $options = new WindowOptions(width: 800, height: 600, title: 'Test');
        $id = $this->driver->openWindow('http://localhost', $options);

        $this->assertSame('win_123', $id);
    }

    public function testCloseWindowSendsIpcMessage(): void
    {
        $this->ipc
            ->expects($this->once())
            ->method('send')
            ->with('window.close', ['windowId' => 'win_abc']);

        $this->driver->closeWindow('win_abc');
    }

    public function testSetWindowTitleSendsCorrectPayload(): void
    {
        $this->ipc
            ->expects($this->once())
            ->method('send')
            ->with('window.setTitle', ['windowId' => 'win_1', 'title' => 'Hello World']);

        $this->driver->setWindowTitle('win_1', 'Hello World');
    }

    public function testListWindowsReturnsArray(): void
    {
        $this->ipc
            ->method('call')
            ->with('window.list')
            ->willReturn(['win_1', 'win_2']);

        $windows = $this->driver->listWindows();

        $this->assertSame(['win_1', 'win_2'], $windows);
    }

    // ── Tray ─────────────────────────────────────────────────────────────────

    public function testCreateTrayReturnsId(): void
    {
        $this->ipc
            ->expects($this->once())
            ->method('call')
            ->with('tray.create', ['icon' => '/path/icon.png', 'tooltip' => 'My App'])
            ->willReturn('tray_1');

        $id = $this->driver->createTray('/path/icon.png', 'My App');

        $this->assertSame('tray_1', $id);
    }

    public function testSetTrayMenuSerializesMenuItems(): void
    {
        $items = [
            new MenuItem(label: 'Open', id: 'open'),
            MenuItem::separator(),
            new MenuItem(label: 'Quit', id: 'quit'),
        ];

        $this->ipc
            ->expects($this->once())
            ->method('send')
            ->with('tray.setMenu', $this->callback(function ($payload) {
                return $payload['trayId'] === 'tray_1'
                    && count($payload['items']) === 3
                    && $payload['items'][0]['label'] === 'Open'
                    && $payload['items'][1]['type'] === 'separator';
            }));

        $this->driver->setTrayMenu('tray_1', $items);
    }

    // ── Notifications ────────────────────────────────────────────────────────

    public function testSendNotificationPassesAllOptions(): void
    {
        $options = new NotificationOptions(
            title:   'Test',
            body:    'Hello from Symfony!',
            sound:   true,
            urgency: 'normal',
        );

        $this->ipc
            ->expects($this->once())
            ->method('send')
            ->with('notification.send', $this->callback(fn($p) =>
                $p['title'] === 'Test' && $p['body'] === 'Hello from Symfony!'
            ));

        $this->driver->sendNotification($options);
    }

    // ── Dialogs ───────────────────────────────────────────────────────────────

    public function testShowOpenDialogReturnsPaths(): void
    {
        $this->ipc
            ->method('call')
            ->willReturn(['canceled' => false, 'filePaths' => ['/home/user/file.txt']]);

        $result = $this->driver->showOpenDialog(new DialogOptions(title: 'Open'));

        $this->assertSame(['/home/user/file.txt'], $result);
    }

    public function testShowOpenDialogReturnNullWhenCancelled(): void
    {
        $this->ipc
            ->method('call')
            ->willReturn(['canceled' => true, 'filePaths' => []]);

        $result = $this->driver->showOpenDialog(new DialogOptions());

        $this->assertNull($result);
    }

    public function testShowMessageBoxReturnsButtonIndex(): void
    {
        $this->ipc
            ->method('call')
            ->willReturn(['response' => 1]);

        $index = $this->driver->showMessageBox('Confirm', 'Are you sure?', ['No', 'Yes']);

        $this->assertSame(1, $index);
    }

    // ── App ───────────────────────────────────────────────────────────────────

    public function testGetPathCallsIpcWithName(): void
    {
        $this->ipc
            ->method('call')
            ->with('app.getPath', ['name' => 'appData'])
            ->willReturn('/home/user/.config/myapp');

        $path = $this->driver->getPath('appData');

        $this->assertSame('/home/user/.config/myapp', $path);
    }

    public function testQuitSendsCorrectAction(): void
    {
        $this->ipc->expects($this->once())->method('send')->with('app.quit');
        $this->driver->quit();
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function testStoreGetCallsIpcWithKey(): void
    {
        $this->ipc
            ->method('call')
            ->with('store.get', ['key' => 'theme'])
            ->willReturn('dark');

        $this->assertSame('dark', $this->driver->storeGet('theme'));
    }

    public function testStoreSetSendsKeyAndValue(): void
    {
        $this->ipc
            ->expects($this->once())
            ->method('send')
            ->with('store.set', ['key' => 'theme', 'value' => 'light']);

        $this->driver->storeSet('theme', 'light');
    }

    // ── Metadata ─────────────────────────────────────────────────────────────

    public function testGetNameReturnsElectron(): void
    {
        $this->assertSame('electron', $this->driver->getName());
    }
}
