<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\AppManager;
use SymfonyNativeBridge\Service\DialogManager;
use SymfonyNativeBridge\Service\NotificationManager;
use SymfonyNativeBridge\Service\StorageManager;
use SymfonyNativeBridge\Service\TrayManager;
use SymfonyNativeBridge\Service\WindowManager;
use SymfonyNativeBridge\ValueObject\MenuItem;
use SymfonyNativeBridge\ValueObject\WindowOptions;

// =============================================================================
// WindowManager
// =============================================================================

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

// =============================================================================
// TrayManager
// =============================================================================

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

// =============================================================================
// NotificationManager
// =============================================================================

class NotificationManagerTest extends TestCase
{
    public function testSendCreatesOptionsAndDelegates(): void
    {
        $driver = $this->createMock(NativeDriverInterface::class);
        $driver->expects($this->once())
            ->method('sendNotification')
            ->with($this->callback(fn($o) => $o->title === 'Hello' && $o->body === 'World'));

        $manager = new NotificationManager($driver);
        $manager->send('Hello', 'World');
    }
}

// =============================================================================
// DialogManager
// =============================================================================

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

// =============================================================================
// StorageManager
// =============================================================================

class StorageManagerTest extends TestCase
{
    private NativeDriverInterface&MockObject $driver;
    private StorageManager $manager;

    protected function setUp(): void
    {
        $this->driver  = $this->createMock(NativeDriverInterface::class);
        $this->manager = new StorageManager($this->driver);
    }

    public function testGetReturnsValueFromDriver(): void
    {
        $this->driver->method('storeGet')->with('key')->willReturn('value');
        $this->assertSame('value', $this->manager->get('key'));
    }

    public function testGetReturnsDefaultWhenDriverReturnsNull(): void
    {
        $this->driver->method('storeGet')->willReturn(null);
        $this->assertSame('fallback', $this->manager->get('missing', 'fallback'));
    }

    public function testHasReturnsFalseForNull(): void
    {
        $this->driver->method('storeGet')->willReturn(null);
        $this->assertFalse($this->manager->has('missing'));
    }

    public function testHasReturnsTrueForValue(): void
    {
        $this->driver->method('storeGet')->willReturn('something');
        $this->assertTrue($this->manager->has('key'));
    }

    public function testRememberStoresAndReturnsCalculatedValue(): void
    {
        $this->driver->method('storeGet')->willReturn(null);
        $this->driver->expects($this->once())->method('storeSet')->with('key', 42);

        $result = $this->manager->remember('key', fn() => 42);
        $this->assertSame(42, $result);
    }

    public function testRememberReturnsCachedValueWithoutCallingCallback(): void
    {
        $this->driver->method('storeGet')->willReturn('cached');
        $called = false;

        $result = $this->manager->remember('key', function () use (&$called) {
            $called = true;
            return 'fresh';
        });

        $this->assertSame('cached', $result);
        $this->assertFalse($called);
    }
}

// =============================================================================
// AppManager
// =============================================================================

class AppManagerTest extends TestCase
{
    public function testGetNameReturnsFromConfig(): void
    {
        $driver  = $this->createMock(NativeDriverInterface::class);
        $manager = new AppManager($driver, ['name' => 'My App', 'version' => '2.0.0'], ['enabled' => false]);

        $this->assertSame('My App', $manager->getName());
        $this->assertSame('2.0.0', $manager->getVersion());
    }

    public function testCheckForUpdatesThrowsWhenDisabled(): void
    {
        $driver  = $this->createMock(NativeDriverInterface::class);
        $manager = new AppManager($driver, ['name' => 'App', 'version' => '1.0'], ['enabled' => false]);

        $this->expectException(\RuntimeException::class);
        $manager->checkForUpdates();
    }

    public function testCheckForUpdatesDelegatesToDriverWhenEnabled(): void
    {
        $driver = $this->createMock(NativeDriverInterface::class);
        $driver->expects($this->once())->method('checkForUpdates');

        $manager = new AppManager($driver, ['name' => 'App', 'version' => '1.0'], ['enabled' => true]);
        $manager->checkForUpdates();
    }
}
